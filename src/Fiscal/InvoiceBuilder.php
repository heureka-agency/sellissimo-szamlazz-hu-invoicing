<?php

namespace Sellissimo\SzamlazzHu\Fiscal;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Sellissimo\Core\Money;
use Sellissimo\Core\PricingRules;

final class InvoiceBuilder
{
    // Domestic numeric rates and explicitly mapped domestic zero/exempt treatments supported by this pilot.
    public const VAT_CODES = ['0', '5', '18', '27', 'AAM', 'TAM', 'F.AFA', 'TAHK', 'ATK'];

    public function build(object $order, array $placement, array $settings, array $overrides = []): array
    {
        $receipt = $placement['receipt'] ?? [];
        $buyer = $placement['buyer'] ?? [];
        if (($receipt['currency'] ?? '') !== 'HUF' || $order->currency !== 'HUF'
            || ($buyer['country'] ?? '') !== 'HU' || ($receipt['shipping_address']['country'] ?? 'HU') !== 'HU') {
            throw new DomainException(__('szamlazz::fiscal.scope'));
        }
        if (! empty($receipt['voucher']) || collect($receipt['lines'] ?? [])->contains(fn ($r) => ! empty($r['voucher_product']))) {
            throw new DomainException(__('szamlazz::fiscal.voucher'));
        }
        if (! in_array($buyer['type'] ?? '', ['person', 'business'], true) || blank($buyer['name'] ?? '')
            || ! preg_match('/^[0-9]{4}$/D', $buyer['postcode'] ?? '') || blank($buyer['city'] ?? '') || blank($buyer['address'] ?? '')
            || ($buyer['type'] === 'business' && ! preg_match('/^\d{8}-[1-5]-\d{2}$/D', $buyer['tax_number'] ?? ''))) {
            throw new DomainException(__('szamlazz::fiscal.buyer'));
        }
        $payment = $placement['settings']['payment_methods'][$order->gateway] ?? '';
        if (blank($payment)) {
            throw new DomainException(__('szamlazz::fiscal.no_payment'));
        }
        if (! is_int($placement['digits'] ?? null) || ! in_array($placement['digits'], [0, 2], true)
            || empty($receipt['lines']) || ! is_int($receipt['tax'] ?? null) || ! is_int($receipt['total'] ?? null)) {
            throw new DomainException(__('szamlazz::fiscal.no_snapshot'));
        }

        $divisor = 10 ** $placement['digits'];
        $mappings = $placement['settings']['vat_codes'] ?? [];
        $lines = [];
        $grossMinor = $vatMinor = 0;
        foreach ($this->rows($receipt) as [$key, $name, $qty, $row]) {
            if (! is_int($qty) || $qty <= 0 || ! is_int($row['tax'] ?? null) || ! is_int($row['total'] ?? null)
                || blank($name) || blank($key) || $row['total'] < $row['tax'] || $row['tax'] < 0) {
                throw new DomainException(__('szamlazz::fiscal.evidence', ['item' => $name]));
            }
            $rate = $row['tax_rate'] ?? null;
            $code = (string) ($mappings[$row['tax_class'] ?? ''] ?? '');
            if (! is_int($rate) || ! is_bool($row['tax_inclusive'] ?? null) || ! in_array($code, self::VAT_CODES, true)
                || $rate !== (ctype_digit($code) ? (int) $code * 100 : 0)) {
                throw new DomainException(__('szamlazz::fiscal.mapping', ['item' => $name]));
            }
            $grossMinor += $row['total'];
            $vatMinor += $row['tax'];
            $netMinor = $row['total'] - $row['tax'];
            if ($buyer['type'] === 'person') {
                $gross = $this->roundMinor($row['total'], $divisor);
                $vat = PricingRules::roundedRate($gross, $rate, true);
                $net = $gross - $vat;
            } else {
                $net = $this->roundMinor($netMinor, $divisor);
                $vat = PricingRules::roundedRate($net, $rate);
                $gross = $net + $vat;
            }
            // Compare in the original scale. At an exact half-forint only the chosen
            // primary basis is independently rounded; its complement must still sum to gross.
            $this->agrees($name.' (VAT)', $vat, $row['tax'], $divisor, $placement['digits'], allowRounding: true);
            $this->agrees($name.' (net)', $net, $netMinor, $divisor, $placement['digits'], allowRounding: true);
            $this->agrees($name.' (gross)', $gross, $row['total'], $divisor, $placement['digits'], allowRounding: true);
            $lines[] = [
                'key' => $key, 'name' => $name, 'qty' => $qty, 'unit' => 'db', 'unit_net' => self::unitNet($net, $qty),
                'vat_code' => $code, 'net' => $net, 'vat' => $vat, 'gross' => $gross,
                'discount_minor' => $row['discount'] ?? 0,
            ];
        }
        if ($grossMinor !== $receipt['total'] || $grossMinor !== (int) $order->total || $vatMinor !== $receipt['tax']) {
            throw new DomainException(__('szamlazz::fiscal.total'));
        }

        $gross = array_sum(array_column($lines, 'gross'));
        $vat = array_sum(array_column($lines, 'vat'));
        $this->agrees('gross', $gross, $grossMinor, $divisor, $placement['digits']);
        $this->agrees('VAT', $vat, $vatMinor, $divisor, $placement['digits'], allowRounding: true);
        $this->agrees('net', $gross - $vat, $grossMinor - $vatMinor, $divisor, $placement['digits'], allowRounding: true);

        $today = now('Europe/Budapest');
        $issue = $overrides['issue_date'] ?? $today->toDateString();
        $fulfilment = $overrides['fulfilment_date'] ?? $today->toDateString();
        $due = $overrides['due_date'] ?? $today->copy()->addDays((int) ($settings['due_days'] ?? 0))->toDateString();
        $language = $overrides['language'] ?? $settings['language'] ?? 'hu';
        foreach ([$issue, $fulfilment, $due] as $date) {
            if (! is_string($date) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                throw new DomainException(__('szamlazz::fiscal.dates'));
            }
        }
        if (! in_array($language, ['en', 'hu'], true)) {
            throw new DomainException(__('szamlazz::fiscal.dates'));
        }

        return [
            'kind' => 'invoice', 'order_id' => $order->id, 'order_number' => (string) $order->order_number,
            'revision' => hash('sha256', json_encode([$order->id, $receipt, $buyer], JSON_THROW_ON_ERROR)),
            'currency' => 'HUF', 'mode' => $placement['mode'], 'digits' => $placement['digits'],
            'buyer' => $buyer, 'payment_method' => $payment, 'paid' => ! empty($order->paid_at) || $order->status === 'paid',
            'issue_date' => $issue, 'fulfilment_date' => $fulfilment, 'due_date' => $due, 'language' => $language,
            'electronic' => (bool) ($settings['electronic'] ?? false), 'send_email' => (bool) ($overrides['send_email'] ?? false),
            'lines' => $lines, 'net' => $gross - $vat, 'vat' => $vat, 'gross' => $gross,
        ];
    }

    private function rows(array $receipt): iterable
    {
        foreach ($receipt['lines'] as $line) {
            yield ['item:'.($line['key'] ?? $line['sku'] ?? ''), $line['title'] ?? '', $line['qty'] ?? null, $line];
        }
        if ($shipping = $receipt['shipping'] ?? null) {
            // A complimentary delivery carries no consideration and needs no inferred VAT classification.
            if (($shipping['amount'] ?? null) !== 0) {
                yield ['shipping', $shipping['label'] ?? '', 1, [...$shipping, 'total' => $shipping['amount'] ?? null]];
            }
        }
        foreach ($receipt['charges'] ?? [] as $handle => $charge) {
            yield ['fee:'.$handle, $charge['label'] ?? '', 1, $charge];
        }
    }

    public static function unitNet(int $net, int $qty): string
    {
        return (string) BigDecimal::of($net)->dividedBy($qty, 8, RoundingMode::HalfUp)->strippedOfTrailingZeros();
    }

    private function roundMinor(int $minor, int $divisor): int
    {
        return intdiv($minor + intdiv($divisor, 2), $divisor);
    }

    private function agrees(string $field, int $actual, int $expectedMinor, int $divisor, int $digits, bool $allowRounding = false): void
    {
        $difference = $actual * $divisor - $expectedMinor;
        if (abs($difference) > ($allowRounding ? intdiv($divisor, 2) : 0)) {
            throw new DomainException(__('szamlazz::fiscal.rounding', [
                'field' => $field, 'difference' => Money::decimalWithDigits($difference, $digits),
            ]));
        }
    }
}
