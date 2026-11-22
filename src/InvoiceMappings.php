<?php

namespace Sellissimo\SzamlazzHu;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Sellissimo\Core\PaymentSettings;
use Sellissimo\Core\Registry;
use Sellissimo\Core\Store;
use Sellissimo\Core\TaxSettings;
use Sellissimo\SzamlazzHu\Fiscal\InvoiceBuilder;

final class InvoiceMappings
{
    public function __construct(private Store $store, private Registry $registry) {}

    public function options(string $site, array $settings): array
    {
        // These are the same sources as Core's product tax-class selector.
        $classes = ['standard' => __('Standard')];
        foreach (config('sellissimo.taxes', []) as $rates) {
            foreach (array_keys($rates) as $handle) {
                $classes[$handle] = __(Str::headline($handle));
            }
        }
        if ($this->store->hasTable('tax_preferences')) {
            $shippingClass = app(TaxSettings::class)->forSite($site)['shipping_class'] ?? null;
            if (filled($shippingClass)) {
                $classes[$shippingClass] ??= __(Str::headline($shippingClass));
            }
        }
        if ($this->store->hasTable('tax_classes')) {
            $classes = array_replace($classes, $this->store->table('tax_classes')->pluck('name', 'handle')->all());
        }
        asort($classes, SORT_NATURAL | SORT_FLAG_CASE);

        $gateways = [];
        $configured = app(PaymentSettings::class)->preferences($site)['methods'];
        $installed = $this->registry->installed('payments');
        foreach ($configured as $handle) {
            $gateways[$handle] = $handle === 'pay_on_delivery' ? __('sellissimo::shop.pay_on_delivery') : __(Str::headline($handle));
        }
        $inactiveVat = array_diff_key($settings['vat_codes'], $classes);
        $inactiveGateways = array_diff_key($gateways + $settings['payment_methods'], array_intersect_key($gateways, $installed));

        return [
            'vatClasses' => $classes + $this->labels($inactiveVat),
            'gateways' => $gateways + $this->labels($inactiveGateways),
            'inactiveVat' => array_keys($inactiveVat),
            'inactiveGateways' => array_keys($inactiveGateways),
        ];
    }

    private function labels(array $mappings): array
    {
        $labels = [];
        foreach (array_keys($mappings) as $handle) {
            $labels[$handle] = __(Str::headline((string) $handle));
        }

        return $labels;
    }

    /** Resolve form rows against the selected site while its settings row is locked. */
    public function fromForm(string $site, array $settings, array $input): array
    {
        $options = $this->options($site, $settings);
        foreach (['vat_codes' => 'vatClasses', 'payment_methods' => 'gateways'] as $field => $option) {
            if (! array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            $legacy = ! is_array($value);
            $rows = $legacy ? $this->legacyRows($field, $value) : $value;
            // The old textarea submitted an entire map. Structured rows update only the submitted handles.
            $mappings = $legacy ? [] : $settings[$field];
            $seen = [];
            foreach ($rows as $i => $row) {
                if (! is_int($i) || $i < 0 || ! is_array($row) || array_diff(array_keys($row), ['handle', 'value'])) {
                    $this->invalid($field, 'invalid_map');
                }
                $path = $legacy ? $field : "$field.$i";
                $handle = $row['handle'] ?? '';
                $label = $row['value'] ?? '';
                if (! is_string($handle) || ! is_string($label)) {
                    $this->invalid($path, 'invalid_map');
                }
                $handle = trim($handle);
                $label = trim($label);
                if ($handle === '' && $label === '') {
                    continue;
                }
                $handlePath = $legacy ? $field : "$path.handle";
                $valuePath = $legacy ? $field : "$path.value";
                if (! array_key_exists($handle, $options[$option])) {
                    $this->invalid($handlePath, 'unknown_handle');
                }
                if (isset($seen[$handle])) {
                    $this->invalid($handlePath, 'duplicate_handle');
                }
                $seen[$handle] = true;
                if (! array_key_exists('value', $row)) {
                    $this->invalid($valuePath, 'invalid_map');
                }
                if ($label === '') {
                    if ($legacy) {
                        $this->invalid($field, 'invalid_map');
                    }
                    unset($mappings[$handle]);

                    continue;
                }
                if ($field === 'vat_codes' && ! in_array($label, InvoiceBuilder::VAT_CODES, true)
                    && $label !== ($settings[$field][$handle] ?? null)) {
                    $this->invalid($valuePath, 'invalid_vat');
                }
                if ($field === 'payment_methods' && (! mb_check_encoding($label, 'UTF-8') || mb_strlen($label) > 80 || preg_match('/[[:cntrl:]]/u', $label))) {
                    $this->invalid($valuePath, 'payment_label');
                }
                $mappings[$handle] = $label;
            }
            $input[$field] = $mappings;
        }

        return $input;
    }

    private function legacyRows(string $field, mixed $text): array
    {
        if ($text !== null && (! is_string($text) || mb_strlen($text) > 8000)) {
            $this->invalid($field, 'invalid_map');
        }
        $rows = [];
        foreach (preg_split('/\R/', trim($text ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $pair = explode('=', $line, 2);
            if (count($pair) !== 2 || trim($pair[0]) === '') {
                $this->invalid($field, 'invalid_map');
            }
            $rows[] = ['handle' => $pair[0], 'value' => $pair[1]];
        }

        return $rows;
    }

    private function invalid(string $field, string $reason): never
    {
        throw ValidationException::withMessages([$field => __('szamlazz::ui.'.$reason)]);
    }
}
