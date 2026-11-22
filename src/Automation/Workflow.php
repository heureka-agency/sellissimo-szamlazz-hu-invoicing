<?php

namespace Sellissimo\SzamlazzHu\Automation;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Sellissimo\Core\Store;
use Sellissimo\SzamlazzHu\Fiscal\Placement;
use Sellissimo\SzamlazzHu\Ledger;
use Sellissimo\SzamlazzHu\Settings;

final class Workflow
{
    public function __construct(private Store $store, private Settings $settings, private Ledger $ledger,
        private CommercialSources $sources, private Placement $placements) {}

    public function register(): void
    {
        foreach (['paid', 'shipped', 'amended', 'refunded', 'refund_reversed'] as $topic) {
            Event::listen('sellissimo.order.'.$topic, fn (string $id) => $this->onEvent($id));
        }
        Event::listen('sellissimo.order.cancelled', fn (array $event) => $this->onEvent($event['order_id']));
    }

    private function onEvent(string $orderId): void
    {
        // Core emits these after commit. A queue outage must not fail an already-paid checkout.
        try {
            $this->enqueue($orderId);
        } catch (\Throwable $error) {
            report($error);
        }
    }

    public function enqueue(string $orderId): void
    {
        if (! $this->store->hasTable('sz_settings')) {
            return;
        }
        $site = $this->store->table('orders')->where('id', $orderId)->value('site');
        if ($site === null) {
            return;
        }
        $settings = $this->settings->forSite($site);
        if ($this->settings->pro() && $settings['automation_window']) {
            dispatch(new ProcessOrder($orderId, $settings['automation_window']));
        }
    }

    public function scan(): void
    {
        if (! $this->store->hasTable('sz_settings')) {
            return;
        }
        $this->settings->observeEdition();
        if (! $this->settings->pro()) {
            return;
        }
        foreach ($this->store->table('sz_settings')->pluck('site') as $site) {
            $settings = $this->settings->forSite($site);
            if (! $window = $settings['automation_window']) {
                continue;
            }
            $since = $settings['automation_started_at'];
            $orders = $this->store->table('orders')->where('site', $site)->where(function ($query) use ($since) {
                $query->where(function ($initial) use ($since) {
                    $initial->where('created_at', '>=', $since)->whereIn('status', ['paid', 'awaiting_collection', 'refunded', 'cancelled'])
                        ->whereNotIn('id', $this->store->table('sz_documents')->select('order_id')->where('kind', 'invoice'))
                        ->whereNotIn('id', $this->store->table('szamlazz_reviews')->select('order_id')->where('source', 'invoice')->whereNull('resolved_at'));
                })->orWhereIn('id', $this->store->table('amendments')->select('order_id')->where('applied_at', '>=', $since))
                    ->orWhereIn('id', $this->store->table('return_requests')->select('order_id')->where('status', 'exchanged')
                        ->whereNull('exchange_amendment')->where('updated_at', '>=', $since))
                    ->orWhereIn('id', $this->store->table('sz_documents')->select('order_id')
                        ->whereIn('status', ['submitting', 'uncertain', 'rejected'])->where('updated_at', '>=', $since))
                    ->orWhereIn('id', $this->store->table('refunds')->select('order_id')
                        ->where(fn ($q) => $q->where('settled_at', '>=', $since)->orWhere('reversed_at', '>=', $since)))
                    ->orWhereIn('id', $this->store->table('order_activity')->select('order_id')
                        ->where('kind', 'cancellation')->where('created_at', '>=', $since))
                    ->orWhereIn('id', $this->store->table('szamlazz_reviews')->select('order_id')->whereNull('resolved_at'));
            });
            foreach ($orders->select('id')->lazyById(100) as $order) {
                dispatch(new ProcessOrder($order->id, $window));
            }
        }
    }

    /** Null means the selected trigger has not happened. False is a final unpaid physical dispatch. */
    public function trigger(object $order, array $settings): ?bool
    {
        return $this->triggerFacts($order, $settings)['paid'] ?? null;
    }

    public function triggerFacts(object $order, array $settings): ?array
    {
        if (! $settings['automation_window'] || $order->created_at < $settings['automation_started_at']) {
            return null;
        }
        $receipt = json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR);
        $digital = ! empty($receipt['lines']) && collect($receipt['lines'])->every(fn ($line) => ($line['digital'] ?? false) === true);
        if ($settings['trigger'] === 'payment' || $digital) {
            if (empty($order->paid_at)) {
                return null;
            }
            $paid = true;
            $at = $order->paid_at;
        } else {
            if (! $order->tracking || $order->partially_shipped) {
                return null;
            }
            $parcel = $this->store->table('parcels')->where('order_id', $order->id)->orderByDesc('number')->first();
            if (! $parcel || ! $parcel->created_at || $parcel->paid_at_dispatch === null) {
                throw new DomainException(__('szamlazz::automation.dispatch_unknown'));
            }
            $paid = (bool) $parcel->paid_at_dispatch;
            $at = $parcel->created_at;
        }
        $placement = $this->placements->forOrder($order->id);
        if (($placement['automation_window'] ?? null) !== $settings['automation_window']) {
            return null;
        }
        if ($placement['account_id'] !== $settings['account_id'] || $placement['mode'] !== $settings['mode']) {
            throw new DomainException(__('szamlazz::ui.account_changed'));
        }
        if (! in_array($order->status, ['paid', 'awaiting_collection'], true)
            || $this->store->table('amendments')->where('order_id', $order->id)->whereNotIn('status', ['expired'])->exists()
            || $this->store->table('refunds')->where('order_id', $order->id)->whereIn('status', ['pending', 'succeeded'])->exists()) {
            throw new DomainException(__('szamlazz::automation.sale_changed'));
        }

        return [
            'paid' => $paid,
            'fulfilment_date' => Carbon::parse($at, config('app.timezone'))->setTimezone('Europe/Budapest')->toDateString(),
        ];
    }

    public function handle(string $orderId, string $window): void
    {
        $order = $this->store->table('orders')->where('id', $orderId)->first();
        if (! $order) {
            return;
        }
        $settings = $this->settings->forSite($order->site);
        if (! $this->settings->pro() || $settings['automation_window'] !== $window) {
            return;
        }
        $docs = $this->ledger->history($order->id);
        $original = $docs->firstWhere('kind', 'invoice');
        if (! $original) {
            try {
                $paid = $this->trigger($order, $settings);
                if ($paid === null) {
                    return;
                }
                $this->ledger->automatic($order->id, $window, $paid);
            } catch (DomainException|ValidationException $exception) {
                $this->review($order, 'invoice', $exception->getMessage());

                return;
            }
        }
        // Re-read the source and ledger together. A late worker must not reopen a review just resolved by staff.
        $this->store->db()->transaction(function () use ($orderId, $window) {
            $order = $this->store->table('orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            $settings = $this->settings->forSite($order->site);
            if ($this->settings->pro() && $settings['automation_window'] === $window) {
                $this->updateReviews($order, $settings);
            }
        }, 3);
    }

    private function updateReviews(object $order, array $settings): void
    {
        $docs = $this->ledger->history($order->id);
        $original = $docs->firstWhere('kind', 'invoice');
        if (! $original) {
            return;
        }
        if ($original->status !== 'issued') {
            $this->review($order, 'invoice', $original->reason ?: __('szamlazz::automation.pending_document'));

            return;
        }
        $this->resolve($order->id, 'invoice');

        $closed = $docs->contains(fn ($doc) => $doc->status === 'issued'
            && in_array($doc->payload['source'] ?? '', ['manual-reversal', 'cancellation'], true));
        foreach ($this->sources->forOrder($order) as $source => $candidate) {
            $doc = $docs->firstWhere('business_key', $source);
            if (isset($candidate['supersedes'])) {
                $this->resolve($order->id, $candidate['supersedes']);
                $this->store->table('szamlazz_reviews')->where('order_id', $order->id)->whereNull('resolved_at')
                    ->where('source', 'like', $candidate['reversal_prefix'].'%')->where('source', '!=', $source)
                    ->update(['resolved_at' => now()]);
            }
            if ($doc?->status === 'issued' || ($closed && $candidate['code'] !== 'reversed_refund')) {
                $this->resolve($order->id, $source);

                continue;
            }
            if (($candidate['observed_at'] ?? '') < $settings['automation_started_at']) {
                continue;
            }
            $reason = $doc && $doc->status !== 'issued' ? ($doc->reason ?: __('szamlazz::automation.pending_document')) : $candidate['reason'];
            $this->review($order, $source, $reason);
        }
    }

    private function review(object $order, string $source, string $reason): void
    {
        $this->store->table('szamlazz_reviews')->upsert([[
            'id' => (string) Str::uuid(), 'site' => $order->site, 'order_id' => $order->id, 'source' => $source,
            'reason' => $reason, 'last_attempt_at' => now(), 'resolved_at' => null,
        ]], ['order_id', 'source'], ['reason', 'last_attempt_at', 'resolved_at']);
    }

    private function resolve(string $orderId, string $source): void
    {
        $this->store->table('szamlazz_reviews')->where(['order_id' => $orderId, 'source' => $source])->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);
    }
}
