<?php

namespace Sellissimo\SzamlazzHu;

use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Sellissimo\Core\Store;
use Statamic\Facades\Addon;

class Settings
{
    public const PACKAGE = 'sellissimo/szamlazz-hu-invoicing';

    public function __construct(private Store $store) {}

    public function pro(): bool
    {
        return Addon::get(self::PACKAGE)?->edition() === 'pro';
    }

    private function defaults(): array
    {
        return [
            'mode' => 'test', 'accounts' => [], 'language' => 'hu', 'due_days' => 8,
            'electronic' => false, 'vat_codes' => [], 'payment_methods' => [],
            'trigger' => 'payment', 'send_email' => false, 'automation_window' => null,
            'automation_started_at' => null, 'automation_enabled' => false, 'observed_pro' => false,
        ];
    }

    private function decode(?string $json): array
    {
        return ($json ? json_decode($json, true, flags: JSON_THROW_ON_ERROR) : []) + $this->defaults();
    }

    private function change(string $site, callable $change): array
    {
        return $this->store->db()->transaction(function () use ($site, $change) {
            $table = $this->store->table('sz_settings');
            $table->insertOrIgnore(['site' => $site, 'settings' => '{}']);
            $row = $table->where('site', $site)->lockForUpdate()->firstOrFail();
            $before = $this->decode($row->settings);
            $after = $change($before);
            if ($before !== $after) {
                $table->update(['settings' => json_encode($after, JSON_THROW_ON_ERROR)]);
            }

            return $after;
        }, 3);
    }

    private function editionWindow(array $data): array
    {
        $pro = $this->pro();
        if ($data['observed_pro'] !== $pro) {
            $data['observed_pro'] = $pro;
            $data['automation_window'] = $pro && $data['automation_enabled'] ? (string) Str::uuid() : null;
            $data['automation_started_at'] = $data['automation_window'] ? now()->toDateTimeString() : null;
        }

        return $data;
    }

    public function observeEdition(): void
    {
        if (! $this->store->hasTable('sz_settings')) {
            return;
        }
        foreach ($this->store->table('sz_settings')->pluck('site') as $site) {
            $this->forSite($site);
        }
    }

    public function forSite(string $site): array
    {
        if (! $this->store->hasTable('sz_settings')) {
            return $this->defaults() + ['site' => $site, 'account_id' => null];
        }
        $data = $this->decode($this->store->table('sz_settings')->where('site', $site)->value('settings'));
        if ($data['observed_pro'] !== $this->pro()) {
            $data = $this->change($site, $this->editionWindow(...));
        }

        return $data + ['site' => $site, 'account_id' => $data['accounts'][$data['mode']] ?? null];
    }

    public function save(string $site, array $input, #[\SensitiveParameter] ?string $credential, bool $mappingForm = false): void
    {
        if (! $this->store->hasTable('sz_settings')) {
            throw ValidationException::withMessages(['settings' => __('szamlazz::ui.install')]);
        }
        $this->change($site, function (array $previous) use ($site, $input, $credential, $mappingForm) {
            $data = $this->editionWindow($previous);
            if ($mappingForm) {
                $input = app(InvoiceMappings::class)->fromForm($site, $data, $input);
            }
            $oldMode = $data['mode'];
            $oldAccount = $data['accounts'][$oldMode] ?? null;
            $oldTrigger = $data['trigger'];
            $data = array_replace($data, array_intersect_key($input, array_flip(['mode', 'language', 'due_days', 'electronic', 'vat_codes', 'payment_methods'])));
            $mode = $data['mode'];
            if ($credential !== null && $credential !== '') {
                $accountId = null;
                foreach ($this->store->table('sz_accounts')->where('site', $site)->get() as $account) {
                    if (hash_equals(Crypt::decryptString($account->credential), $credential)) {
                        if ($account->mode !== $mode) {
                            throw ValidationException::withMessages(['credential' => __('szamlazz::ui.different_keys')]);
                        }
                        $accountId = $account->id;
                        break;
                    }
                }
                if (! $accountId) {
                    $accountId = (string) Str::uuid();
                    $this->store->table('sz_accounts')->insert(['id' => $accountId, 'site' => $site, 'mode' => $mode,
                        'credential' => Crypt::encryptString($credential), 'created_at' => now()]);
                }
                $data['accounts'][$mode] = $accountId;
            }
            if ($this->pro()) {
                $data = array_replace($data, array_intersect_key($input, array_flip(['trigger', 'send_email', 'automation_enabled'])));
                if ($data['automation_enabled'] && empty($data['accounts'][$mode])) {
                    throw ValidationException::withMessages(['automation_enabled' => __('szamlazz::ui.missing_account')]);
                }
                if (! $data['automation_enabled']) {
                    $data['automation_window'] = $data['automation_started_at'] = null;
                } elseif (! $data['automation_window'] || $oldMode !== $mode || $oldAccount !== $data['accounts'][$mode] || $oldTrigger !== $data['trigger']) {
                    $data['automation_window'] = (string) Str::uuid();
                    $data['automation_started_at'] = now()->toDateTimeString();
                }
            }

            return $data;
        });
    }

    public function key(string $site, string $mode, string $id): string
    {
        $credential = $this->store->table('sz_accounts')->where(['site' => $site, 'mode' => $mode, 'id' => $id])->value('credential');
        if (! $credential) {
            throw new DomainException(__('szamlazz::ui.missing_account'));
        }

        return Crypt::decryptString($credential);
    }
}
