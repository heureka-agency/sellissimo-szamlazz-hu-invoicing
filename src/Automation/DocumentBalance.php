<?php

namespace Sellissimo\SzamlazzHu\Automation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;
use Sellissimo\Core\PricingRules;

/** Signed line positions in the original invoice and every issued corrective document. */
final class DocumentBalance
{
    public static function negate(array $line): array
    {
        foreach (['qty', 'net', 'vat', 'gross'] as $amount) {
            $line[$amount] = -$line[$amount];
        }

        $line['discount_minor'] = -($line['discount_minor'] ?? 0);

        return $line;
    }

    public static function remaining(array $lines): array
    {
        $positions = [];
        foreach ($lines as $line) {
            if (! isset($line['key'], $line['qty'], $line['unit_net'], $line['vat_code'], $line['name'], $line['unit'],
                $line['net'], $line['vat'], $line['gross']) || $line['gross'] !== $line['net'] + $line['vat']) {
                throw ValidationException::withMessages(['adjustment' => __('szamlazz::automation.incomplete_history')]);
            }
            // Preserve both price and VAT treatment, including zero-value quantity changes.
            $key = json_encode([$line['key'], $line['vat_code'], $line['unit_net'], $line['name'], $line['unit']], JSON_THROW_ON_ERROR);
            if (! isset($positions[$key])) {
                $positions[$key] = $line;

                continue;
            }
            $positions[$key]['discount_minor'] = ($positions[$key]['discount_minor'] ?? 0) + ($line['discount_minor'] ?? 0);
            foreach (['qty', 'net', 'vat', 'gross'] as $amount) {
                $positions[$key][$amount] += $line[$amount];
            }
        }

        return array_values(array_filter($positions, fn ($line) => $line['qty'] || $line['net'] || $line['vat'] || $line['gross']));
    }

    public static function changed(array $before, array $after): array
    {
        return self::remaining([...array_map(self::negate(...), $before), ...$after]);
    }

    /** Subtracting two rounded lines can produce a one-forint VAT difference that a single delta line cannot represent. */
    public static function correction(array $before, array $after, string $buyerType): array
    {
        $compact = self::changed($before, $after);
        if (self::reproducible($compact, $buyerType)) {
            return $compact;
        }
        // Send complete old and new positions for the changed items, retaining their original validated rounding.
        $affected = array_unique(array_column($compact, 'key'));
        $changed = fn ($line) => in_array($line['key'], $affected, true);

        return array_values([...array_map(self::negate(...), array_filter($before, $changed)), ...array_filter($after, $changed)]);
    }

    public static function reproducible(array $lines, string $buyerType): bool
    {
        foreach ($lines as $line) {
            $qty = $line['qty'];
            $net = abs($line['net']);
            $vat = abs($line['vat']);
            $gross = abs($line['gross']);
            if ($qty === 0 || $line['net'] * ($qty <=> 0) < 0 || $line['vat'] * ($qty <=> 0) < 0
                || BigDecimal::of($line['unit_net'])->multipliedBy(abs($qty))->toScale(0, RoundingMode::HalfUp)->toInt() !== $net) {
                return false;
            }
            $rate = ctype_digit((string) $line['vat_code']) ? (int) $line['vat_code'] * 100 : 0;
            $expected = $buyerType === 'person' ? PricingRules::roundedRate($gross, $rate, true) : PricingRules::roundedRate($net, $rate);
            if ($expected !== $vat || $gross !== $net + $vat) {
                return false;
            }
        }

        return true;
    }

    public static function validate(array $balance, array $delta): void
    {
        // Distinct rounded price positions can exist for a single item. Its total
        // remaining quantity and taxable value must cover a proposed credit.
        $totals = [];
        foreach ([...$balance, ...$delta] as $line) {
            $key = json_encode([$line['key'], $line['vat_code']], JSON_THROW_ON_ERROR);
            foreach (['qty', 'net', 'vat', 'gross'] as $amount) {
                $totals[$key][$amount] = ($totals[$key][$amount] ?? 0) + $line[$amount];
            }
        }
        foreach ($totals as $position) {
            if (min($position) < 0) {
                throw ValidationException::withMessages(['adjustment' => __('szamlazz::automation.insufficient_balance')]);
            }
        }
    }
}
