<?php

namespace Sellissimo\SzamlazzHu;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Sellissimo\Core\Store;
use Sellissimo\SzamlazzHu\Agent\Client;
use Sellissimo\SzamlazzHu\Agent\Rejected;
use Sellissimo\SzamlazzHu\Automation\Adjustments;
use Sellissimo\SzamlazzHu\Automation\Workflow;
use Sellissimo\SzamlazzHu\Fiscal\InvoiceBuilder;
use Sellissimo\SzamlazzHu\Fiscal\Placement;
use Throwable;

class Ledger
{
    public function __construct(private Store $store, private Settings $settings, private Client $agent) {}

    private function docs()
    {
        return $this->store->table('sz_documents');
    }

    public function history(string $orderId): Collection
    {
        return $this->docs()->select(['id', 'site', 'order_id', 'account_id', 'mode', 'kind', 'business_key', 'number', 'status',
            'reason', 'payload', 'email_requested', 'email_response', 'created_at', 'updated_at'])->where('order_id', $orderId)
            ->orderBy('created_at')->orderBy('id')->get()->map(function ($row) {
                $row->payload = $this->unpack($row->payload);

                return $row;
            });
    }

    private function pack(array $data): string
    {
        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function unpack(string $data): array
    {
        return json_decode(Crypt::decryptString($data), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function preview(object $order, string $kind, ?string $source, array $overrides = []): array
    {
        $settings = $this->settings->forSite($order->site);
        if ($kind === 'invoice') {
            if ($this->store->table('amendments')->where('order_id', $order->id)->where('status', 'applied')->exists()) {
                throw new DomainException(__('szamlazz::ui.modified'));
            }
            $placement = app(Placement::class)->forOrder($order->id);
            if ($placement['account_id'] !== $settings['account_id'] || $placement['mode'] !== $settings['mode']) {
                throw new DomainException(__('szamlazz::ui.account_changed'));
            }
            $history = $this->history($order->id);
            if ($history->contains(fn ($r) => $r->kind === 'invoice' && $r->status !== 'rejected')) {
                throw new DomainException(__('szamlazz::ui.existing'));
            }
            $overrides['send_email'] = $this->settings->pro() && $settings['send_email'];
            $payload = app(InvoiceBuilder::class)->build($order, $placement, $settings, $overrides);
        } else {
            $history = $this->history($order->id);
            $original = $history->first(fn ($r) => $r->kind === 'invoice' && $r->status === 'issued');
            if (! $original || empty($original->payload['lines'])) {
                throw new DomainException(__('szamlazz::ui.no_original'));
            }
            if ($settings['mode'] !== $original->mode) {
                throw new DomainException(__('szamlazz::ui.account_changed'));
            }
            $chain = $history->where('account_id', $original->account_id);
            if ($chain->contains(fn ($r) => in_array($r->status, ['uncertain', 'submitting'], true))) {
                throw new DomainException(__('szamlazz::ui.uncertain'));
            }
            $payload = app(Adjustments::class)->build($order, $original->payload, $chain->where('status', 'issued')->values(), $kind, $source);
            $payload['send_email'] = $this->settings->pro() && $settings['send_email'];
            $settings['account_id'] = $original->account_id;
        }

        return array_replace($payload, ['account_id' => $settings['account_id'], 'mode' => $settings['mode']]);
    }

    public function automatic(string $orderId, string $window, bool $paid): void
    {
        $this->issue($orderId, 'invoice', null, [], null, null, $window);
    }

    public function issue(string $orderId, string $kind, ?string $source, array $overrides, ?string $digest,
        ?string $actor, ?string $window = null, bool $retry = false): string
    {
        $key = $kind === 'invoice' ? 'invoice' : ($source ?: 'manual-reversal');
        $claim = $this->store->db()->transaction(function () use ($orderId, $kind, $source, $overrides, $digest, $actor, $window, $key, $retry) {
            $order = $this->store->table('orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            if ($window !== null) {
                $this->store->table('sz_settings')->where('site', $order->site)->lockForUpdate()->first();
            }
            $settings = $this->settings->forSite($order->site);
            if ($window !== null && (! $this->settings->pro() || $settings['automation_window'] !== $window
                || (app(Placement::class)->forOrder($orderId)['automation_window'] ?? null) !== $window)) {
                throw new DomainException(__('szamlazz::ui.window_closed'));
            }
            $existing = $this->docs()->where(['order_id' => $orderId, 'business_key' => $key])->first();
            if ($existing && ($existing->status !== 'rejected' || ! $retry)) {
                return [$existing->id, null];
            }
            if ($window !== null) {
                $facts = app(Workflow::class)->triggerFacts($order, $settings);
                if ($facts === null) {
                    throw new DomainException(__('szamlazz::ui.window_closed'));
                }
                $overrides['fulfilment_date'] = $facts['fulfilment_date'];
            }
            $payload = $this->preview($order, $kind, $source, $overrides);
            if ($window !== null) {
                $payload['paid'] = $facts['paid'];
            }
            if ($digest !== null && ! hash_equals(self::fingerprint($payload), $digest)) {
                throw new DomainException(__('szamlazz::ui.changed'));
            }
            $id = $existing?->id ?? (string) Str::uuid();
            if ($existing) {
                // A definitive rejection is the only state that can be submitted again.
                $this->archiveRejection($existing);
                $this->docs()->where('id', $id)->update(['status' => 'submitting', 'payload' => $this->pack($payload),
                    'actor' => $actor, 'email_requested' => $payload['send_email'], 'reason' => null, 'updated_at' => now()]);
            } else {
                $this->insert($id, $order, $payload, $key, $actor);
            }

            return [$id, $payload];
        }, 3);
        [$id,$payload] = $claim;
        if ($payload === null) {
            return $id;
        }
        $row = $this->docs()->where('id', $id)->firstOrFail();
        try {
            $response = $this->agent->submit($this->credential($row), $payload, $id);
        } catch (Rejected $e) {
            $this->fail($id, 'rejected', $e->getMessage());

            return $id;
        } catch (Throwable) {
            $this->fail($id, 'uncertain', __('szamlazz::ui.uncertain'));

            return $id;
        }
        $this->issued($row, $response);

        return $id;
    }

    private function archiveRejection(object $row): void
    {
        $this->store->table('sz_attempts')->insert(['document_id' => $row->id, 'payload' => $row->payload,
            'reason' => $row->reason ?? '', 'attempted_at' => $row->updated_at, 'actor' => $row->actor]);
    }

    private function insert(string $id, object $order, array $payload, string $key, ?string $actor): void
    {
        $this->docs()->insert(['id' => $id, 'site' => $order->site, 'order_id' => $order->id, 'mode' => $payload['mode'],
            'account_id' => $payload['account_id'], 'kind' => $payload['kind'], 'business_key' => $key, 'status' => 'submitting',
            'payload' => $this->pack($payload), 'email_requested' => (bool) ($payload['send_email'] ?? false),
            'actor' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function credential(object $row): string
    {
        return $this->settings->key($row->site, $row->mode, $row->account_id);
    }

    private function fail(string $id, string $state, string $reason): void
    {
        $this->docs()->where('id', $id)->whereIn('status', ['submitting', 'uncertain'])->update([
            'status' => $state, 'reason' => $reason, 'updated_at' => now(),
        ]);
    }

    private function issued(object $row, array $response): void
    {
        $this->docs()->where('id', $row->id)->whereIn('status', ['submitting', 'uncertain'])->update(['status' => 'issued',
            'number' => $response['number'], 'pdf' => empty($response['pdf']) ? null : Crypt::encryptString(base64_encode($response['pdf'])),
            'email_response' => $response['email_response'] ?? null, 'reason' => null, 'updated_at' => now()]);
    }

    public function reconcile(string $id, ?string $number = null): void
    {
        $row = $this->docs()->where('id', $id)->firstOrFail();
        if ($row->status === 'issued') {
            return;
        }
        if (! in_array($row->status, ['submitting', 'uncertain'], true)) {
            throw new DomainException(__('szamlazz::ui.no_lookup'));
        }
        $this->docs()->where('id', $id)->update(['last_lookup_at' => now()]);
        $payload = $this->unpack($row->payload);
        if (($payload['kind'] === 'reversal') && ! $number) {
            throw new DomainException(__('szamlazz::ui.need_number'));
        }
        try {
            $result = $this->agent->lookup($this->credential($row), $payload, $number,
                $payload['kind'] === 'reversal' ? null : $id);
        } catch (Throwable) {
            throw new DomainException(__('szamlazz::ui.unverified'));
        }
        $this->issued($row, $result);
    }

    public function reconcileDue(): void
    {
        if (! $this->store->hasTable('sz_documents')) {
            return;
        }
        // A worker may have died after sending. Age protects a request still in flight.
        $this->docs()->where('status', 'submitting')->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'uncertain', 'reason' => __('szamlazz::ui.uncertain'), 'updated_at' => now()]);
        $ids = $this->docs()->where('status', 'uncertain')->where('kind', '!=', 'reversal')
            ->where(fn ($q) => $q->whereNull('last_lookup_at')->orWhere('last_lookup_at', '<', now()->subHour()))
            ->orderBy('last_lookup_at')->limit(50)->pluck('id');
        foreach ($ids as $id) {
            try {
                $this->reconcile($id);
            } catch (DomainException) {
                // Retain the unresolved state for staff review and a later read-only lookup.
            }
        }
    }

    public function match(object $order, string $number, string $actor): void
    {
        $settings = $this->settings->forSite($order->site);
        if (! $settings['account_id']) {
            throw new DomainException(__('szamlazz::ui.missing_account'));
        }
        $key = $this->settings->key($order->site, $settings['mode'], $settings['account_id']);
        try {
            $placement = $this->store->table('sz_placements')->where('order_id', $order->id)->exists()
                ? app(Placement::class)->forOrder($order->id) : null;
            if ($placement && $placement['account_id'] !== $settings['account_id']) {
                throw new DomainException(__('szamlazz::ui.account_changed'));
            }
            $result = $this->agent->matchOrder($key, $order, $number, $placement, $settings['mode']);
        } catch (Throwable) {
            throw new DomainException(__('szamlazz::ui.unverified'));
        }
        $this->store->db()->transaction(function () use ($order, $settings, $actor, $result) {
            $this->store->table('orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            $previous = $this->docs()->where('order_id', $order->id)->where('kind', 'invoice')->first();
            if ($previous && ($previous->status !== 'rejected' || $previous->account_id !== $settings['account_id'])) {
                throw new DomainException(__('szamlazz::ui.existing'));
            }
            $id = $previous?->id ?? (string) Str::uuid();
            $payload = ['kind' => 'invoice', 'external' => true, 'mode' => $settings['mode'], 'account_id' => $settings['account_id']];
            if ($previous) {
                $this->archiveRejection($previous);
                $this->docs()->where('id', $id)->update(['status' => 'submitting', 'payload' => $this->pack($payload),
                    'actor' => $actor, 'email_requested' => false, 'reason' => null, 'updated_at' => now()]);
            } else {
                $this->insert($id, $order, $payload, 'invoice', $actor);
            }
            $this->issued((object) ['id' => $id], $result);
        });
    }

    public function matchAdjustment(object $order, string $number, string $kind, string $source, array $amounts, string $actor): void
    {
        $history = $this->history($order->id);
        $original = $history->first(fn ($r) => $r->kind === 'invoice' && $r->status === 'issued');
        if (! $original || $original->mode !== $this->settings->forSite($order->site)['mode']) {
            throw new DomainException(__('szamlazz::ui.no_original'));
        }
        if ($amounts['gross'] !== $amounts['net'] + $amounts['vat']) {
            throw new DomainException(__('szamlazz::fiscal.total'));
        }
        $candidates = app(Adjustments::class)->candidates($order);
        if (! isset($candidates[$source]) && ! in_array($source, ['manual-reversal', 'manual-external'], true)) {
            throw new DomainException(__('szamlazz::ui.unverified'));
        }
        // A merchant may manually make several unrelated corrections to the same original.
        if ($source === 'manual-external') {
            $source = 'external:'.hash('sha256', $number);
        }
        try {
            $facts = $original->payload + ['order_number' => (string) $order->order_number];
            $result = $this->agent->matchAdjustment($this->credential($original), $facts, $original->number, $number, $kind, $amounts);
        } catch (Throwable) {
            throw new DomainException(__('szamlazz::ui.unverified'));
        }
        $this->store->db()->transaction(function () use ($order, $source, $kind, $amounts, $original, $actor, $result) {
            $this->store->table('orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            $chain = $this->history($order->id)->where('account_id', $original->account_id);
            if ($chain->contains(fn ($row) => in_array($row->status, ['submitting', 'uncertain'], true)
                || $row->business_key === $source || $row->number === $result['number'])) {
                throw new DomainException(__('szamlazz::ui.existing'));
            }
            $id = (string) Str::uuid();
            $payload = $amounts + ['kind' => $kind, 'external' => true, 'source' => $source, 'original_number' => $original->number,
                'currency' => 'HUF', 'mode' => $original->mode, 'account_id' => $original->account_id];
            $this->insert($id, $order, $payload, $source, $actor);
            $this->issued((object) ['id' => $id], $result);
        });
    }

    public function pdf(string $id): string
    {
        $row = $this->docs()->where('id', $id)->where('status', 'issued')->firstOrFail();
        if ($row->pdf !== null) {
            return base64_decode(Crypt::decryptString($row->pdf), true);
        }
        try {
            $bytes = $this->agent->pdf($this->credential($row), $row->number);
        } catch (Throwable) {
            throw new DomainException(__('szamlazz::ui.pdf_unavailable'));
        }
        $this->docs()->where('id',$id)->update(['pdf' => Crypt::encryptString(base64_encode($bytes))]);

        return $bytes;
    }
}
