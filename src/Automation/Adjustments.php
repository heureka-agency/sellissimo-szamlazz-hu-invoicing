<?php

namespace Sellissimo\SzamlazzHu\Automation;

use Brick\Math\BigInteger;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Sellissimo\Core\Store;
use Sellissimo\SzamlazzHu\Fiscal\InvoiceBuilder;
use Sellissimo\SzamlazzHu\Fiscal\Placement;
use Sellissimo\SzamlazzHu\Settings;

final class Adjustments
{
    public function __construct(
        private Store $store,
        private CommercialSources $sources,
        private Placement $placements,
        private InvoiceBuilder $builder,
        private Settings $settings,
    ) {}

    public function candidates(object $order): array
    {
        return $this->sources->forOrder($order);
    }

    /** Build again under the order lock at confirmation. A new source or balance invalidates a prior preview. */
    public function build(object $order, array $originalPayload, Collection $issued, string $kind, ?string $source): array
    {
        $source ??= $kind === 'reversal' ? 'manual-reversal' : '';
        $original = $issued->first(fn ($doc) => $doc->kind === 'invoice' && $doc->status === 'issued'
            && $doc->payload === $originalPayload);
        if (! $original || ! $original->number || empty($originalPayload['lines'])) {
            $this->reject('incomplete_history');
        }
        $chain = $issued->where('account_id', $original->account_id)->where('mode', $original->mode);
        if ($chain->contains(fn ($doc) => in_array($doc->status, ['submitting', 'uncertain'], true))) {
            $this->reject('pending_document');
        }
        $chain = $chain->where('status', 'issued');
        if ($chain->where('kind', 'invoice')->count() !== 1 || $chain->contains('kind', 'reversal')
            || $chain->contains(fn ($doc) => in_array($doc->payload['source'] ?? '', ['manual-reversal', 'cancellation'], true))
            || $chain->contains(fn ($doc) => ($doc->payload['source'] ?? $doc->business_key) === $source)) {
            $this->reject('already_adjusted');
        }
        foreach ($chain as $doc) {
            if (empty($doc->payload['lines'])) {
                $this->reject('incomplete_history');
            }
        }
        $chainLines = $chain->flatMap(fn ($doc) => $doc->payload['lines'])->all();
        $balance = DocumentBalance::remaining($chainLines);
        if ($source !== 'manual-reversal') {
            $candidate = $this->candidates($order)[$source] ?? null;
            if (! $candidate || ! $candidate['eligible']) {
                $this->reject($candidate['code'] ?? 'missing_source');
            }
        }
        $kind = match ($source) {
            'manual-reversal', 'cancellation' => $chain->contains('kind', 'correction') ? 'correction' : 'reversal',
            default => 'correction',
        };
        $lines = match (true) {
            in_array($source, ['manual-reversal', 'cancellation'], true) => DocumentBalance::correction($chainLines, [], $originalPayload['buyer']['type']),
            str_starts_with($source, 'amendment:') => $this->amendment($order, substr($source, 10), $chain),
            str_starts_with($source, 'return:') => $this->receivedReturn($order, substr($source, 7), $balance),
            default => $this->reject('missing_source'),
        };
        if (! $lines) {
            $this->reject('no_change');
        }
        if (! DocumentBalance::reproducible($lines, $originalPayload['buyer']['type'])) {
            $this->reject('return_rounding');
        }
        DocumentBalance::validate($balance, $lines);
        // Check the exact displayed chain upon submission, including two different corrections of equal value.
        $revision = hash('sha256', json_encode([$source, $chain->pluck('id')->sort()->values(), $lines], JSON_THROW_ON_ERROR));
        $today = now('Europe/Budapest')->toDateString();

        return array_replace($originalPayload, [
            'kind' => $kind, 'source' => $source, 'original_number' => $original->number, 'revision' => $revision,
            'issue_date' => $today, 'due_date' => $today, 'send_email' => false, 'lines' => $lines,
            'net' => array_sum(array_column($lines, 'net')), 'vat' => array_sum(array_column($lines, 'vat')),
            'gross' => array_sum(array_column($lines, 'gross')),
        ]);
    }

    private function amendment(object $order, string $id, Collection $chain): array
    {
        $row = $this->store->table('amendments')->where(['id' => $id, 'order_id' => $order->id, 'status' => 'applied'])->firstOrFail();
        if ($row->exchange_return) {
            // Core's exchange proposal carries the purchased line's class, not necessarily the replacement's rate.
            $this->reject('exchange_review');
        }
        $placement = $this->placements->forOrder($order->id);
        $before = json_decode($row->previous, true, flags: JSON_THROW_ON_ERROR);
        $after = json_decode($row->proposed, true, flags: JSON_THROW_ON_ERROR);
        if ((int) $row->difference !== $after['total'] - $before['total']) {
            $this->reject('amendment_mismatch');
        }

        $settings = $this->settings->forSite($order->site);
        $oldLines = $this->quoteLines($order, $placement, $before, $settings);
        $newLines = $this->quoteLines($order, $placement, $after, $settings);
        // Previous amendment positions must already be represented. Standalone returns are separate credits.
        $priorSale = $chain->filter(fn ($doc) => $doc->kind === 'invoice'
            || str_starts_with($doc->payload['source'] ?? '', 'amendment:'));
        $priorLines = $priorSale->flatMap(fn ($doc) => $doc->payload['lines'])->values()->all();
        if (DocumentBalance::changed($priorLines, $oldLines)) {
            $this->reject('previous_amendment');
        }

        return DocumentBalance::correction($oldLines, $newLines, $placement['buyer']['type']);
    }

    private function quoteLines(object $order, array $placement, array $receipt, array $settings): array
    {
        $revision = clone $order;
        $revision->total = $receipt['total'];
        $placement['receipt'] = $receipt;

        return $this->builder->build($revision, $placement, $settings)['lines'];
    }

    private function receivedReturn(object $order, string $id, array $balance): array
    {
        $request = $this->store->table('return_requests')->where(['order_id' => $order->id, 'id' => $id])->firstOrFail();
        $received = $this->store->table('returns')->where(['order_id' => $order->id, 'id' => $id, 'line_key' => $request->line_key])->first();
        if (! $received || (int) $received->quantity !== (int) $request->quantity) {
            $this->reject('return_mismatch');
        }
        $matches = array_values(array_filter($balance, fn ($line) => $line['key'] === 'item:'.$request->line_key && $line['qty'] > 0));
        if (count($matches) !== 1) {
            $this->reject('ambiguous_return');
        }
        $line = $matches[0];
        $quantity = (int) $request->quantity;
        if ($quantity < 1 || $quantity > $line['qty']) {
            $this->reject('insufficient_balance');
        }
        // Preserve per-unit tax and original pricing. A non-exact allocation needs explicit fiscal evidence.
        foreach (['net', 'vat', 'gross'] as $amount) {
            $share = BigInteger::of($line[$amount])->multipliedBy($quantity);
            [$credit, $remainder] = $share->quotientAndRemainder($line['qty']);
            if (! $remainder->isZero()) {
                $this->reject('return_rounding');
            }
            $line[$amount] = $credit->toInt();
        }
        $digits = $this->placements->forOrder($order->id)['digits'];
        if ((int) $request->refund_entitlement !== $line['gross'] * 10 ** $digits) {
            $this->reject('return_mismatch');
        }
        // The original unit price already includes the allocated discount. A partial credit has no independent promotion.
        $line['discount_minor'] = 0;
        $line['qty'] = $quantity;

        return [DocumentBalance::negate($line)];
    }

    private function reject(string $reason): never
    {
        throw ValidationException::withMessages(['adjustment' => __('szamlazz::automation.'.$reason)]);
    }
}
