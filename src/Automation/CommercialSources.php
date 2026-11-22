<?php

namespace Sellissimo\SzamlazzHu\Automation;

use Illuminate\Support\Collection;
use Sellissimo\Core\Store;

final class CommercialSources
{
    public function __construct(private Store $store) {}

    /** One review per commercial change, even when several captures funded it. */
    public function forOrder(object $order): array
    {
        $amendments = $this->store->table('amendments')->where('order_id', $order->id)->get()->keyBy('id');
        $returns = $this->store->table('return_requests')->where('order_id', $order->id)->get()->keyBy('id');
        $refunds = $this->store->table('refunds')->where('order_id', $order->id)->where(fn ($q) => $q->where('status', '!=', 'superseded')->orWhereNotNull('reversed_at'))->get();
        $groups = $refunds->groupBy(fn ($refund) => $this->sourceKey($order, $refund));
        $sources = [];
        foreach ($amendments as $row) {
            if ($row->status === 'applied') {
                $sources['amendment:'.$row->id] = $this->entry($row->exchange_return ? 'exchange_review' : 'amendment', ! $row->exchange_return, $row->applied_at);
            }
        }
        // Equal-value replacements need no payment amendment in Core. A different invoiced item can still need review.
        foreach ($returns as $request) {
            if ($request->status === 'exchanged' && $request->replacement && ! $request->exchange_amendment) {
                $original = collect(json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR)['lines'])
                    ->first(fn ($line) => ($line['key'] ?? $line['sku']) === $request->line_key);
                if (($original['sku'] ?? '') !== $request->replacement) {
                    $sources['exchange:'.$request->id] = $this->entry('exchange_review', false, $request->updated_at);
                }
            }
        }
        foreach ($groups as $key => $parts) {
            $source = $this->refundSource($key, $parts, $amendments, $returns);
            if ($source) {
                $source['observed_at'] = max($source['observed_at'], $sources[$key]['observed_at'] ?? '');
                if ($source['code'] === 'reversed_refund') {
                    // Keep the issued commercial correction linked. A later provider reversal can need its own document.
                    $prefix = 'reversed:'.$key.':';
                    $reversed = $parts->whereNotNull('reversed_at')->pluck('reversed_at', 'id')->sortKeys()->all();
                    $source['supersedes'] = $key;
                    $source['reversal_prefix'] = $prefix;
                    unset($sources[$key]);
                    $key = $prefix.substr(hash('sha256', json_encode($reversed, JSON_THROW_ON_ERROR)), 0, 24);
                }
                $sources[$key] = $source;
            }
        }
        if ($order->status === 'cancelled') {
            $when = $this->store->table('order_activity')->where('order_id', $order->id)
                ->where('kind', 'cancellation')->max('created_at');
            $cancellation = $sources['cancellation'] ?? null;
            $complete = (int) $refunds->where('status', 'succeeded')->sum('amount') === (int) $order->total
                && ! $refunds->contains('status', 'pending');
            $candidate = $this->entry($complete ? 'cancellation' : 'incomplete_cancellation', $complete,
                max($when ?? '', $cancellation['observed_at'] ?? ''), 'reversal');
            if (! collect($sources)->contains(fn ($source) => ($source['supersedes'] ?? null) === 'cancellation')) {
                $sources['cancellation'] = $candidate;
            }
        }

        return $sources;
    }

    private function sourceKey(object $order, object $refund): string
    {
        if ($refund->amendment_id) {
            return 'amendment:'.$refund->amendment_id;
        }
        if ($refund->return_request_id) {
            return 'return:'.$refund->return_request_id;
        }
        if ($order->status === 'cancelled' && $refund->sku === '@all') {
            return 'cancellation';
        }

        return 'refund:'.($refund->group_id ?: $refund->id);
    }

    private function refundSource(string $key, Collection $parts, Collection $amendments, Collection $returns): ?array
    {
        $last = max($parts->max('reversed_at') ?? '', $parts->max('settled_at') ?? '');
        if ($parts->contains(fn ($row) => $row->reversed_at !== null)) {
            return $this->entry('reversed_refund', false, $last);
        }
        if (! $parts->contains('status', 'succeeded')) {
            return null;
        }
        if ($parts->contains(fn ($row) => $row->status !== 'succeeded')) {
            return $this->entry('partly_settled', false, $last);
        }
        $part = $parts->first();
        $amount = (int) $parts->sum('amount');
        if ($part->amendment_id) {
            $amendment = $amendments->get($part->amendment_id);
            $complete = $amendment?->status === 'applied' && (int) $amendment->difference === -$amount;

            if ($complete && $amendment->exchange_return) {
                return $this->entry('exchange_review', false, $last);
            }

            return $this->entry($complete ? 'amendment' : 'partly_settled', $complete, $last);
        }
        if ($part->return_request_id) {
            $request = $returns->get($part->return_request_id);
            $received = $request?->status === 'received' && ! $request->replacement;
            $complete = $received && $request->refund_entitlement !== null && (int) $request->refund_entitlement === $amount
                && $parts->every(fn ($part) => $part->sku === $request->line_key);

            return $this->entry($complete ? 'return' : 'return_mismatch', $complete, $last);
        }
        if ($key === 'cancellation') {
            return $this->entry('cancellation', true, $last, 'reversal');
        }

        return $this->entry('unallocated_refund', false, $last);
    }

    private function entry(string $code, bool $eligible, ?string $at, string $kind = 'correction'): array
    {
        return ['reason' => __('szamlazz::automation.'.$code), 'code' => $code, 'eligible' => $eligible,
            'kind' => $kind, 'observed_at' => $at];
    }
}
