<?php

namespace Sellissimo\SzamlazzHu\Fiscal;

use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Sellissimo\Core\Money;
use Sellissimo\Core\Registry;
use Sellissimo\Core\Store;
use Sellissimo\SzamlazzHu\Settings;

final class Placement
{
    public function register(): void
    {
        $registry = app(Registry::class);
        $registry->checkoutStep(BuyerStep::HANDLE, new BuyerStep, beforeReview: true);
        $registry->billingFields(BuyerStep::HANDLE, fn (array $address, string $site, string $currency) => BuyerStep::domestic($address, $site, $currency) ? ['name', 'street', 'city', 'postal_code'] : []);
        Event::listen('sellissimo.order.placement', [$this, 'capture']);
    }

    public function capture(object $order, array $receipt, array $privateAnswers): void
    {
        $settings = app(Settings::class)->forSite($order->site);
        if (empty($settings['account_id'])) {
            return;
        }
        $answers = $privateAnswers[BuyerStep::HANDLE] ?? [];
        $address = $receipt['address'] ?? [];
        $business = ($answers['buyer_type'] ?? null) === 'business';
        $facts = Arr::only($receipt, ['currency', 'address', 'shipping_address', 'shipping', 'charges', 'total', 'tax', 'subtotal', 'discount']);
        $facts['voucher'] = ! empty($receipt['voucher']) || ! empty($order->voucher_id);
        $facts['lines'] = array_map(fn (array $row) => Arr::only($row, ['key', 'sku', 'title', 'price', 'qty', 'digital',
            'voucher_product', 'subtotal', 'discount', 'tax', 'total', 'tax_class', 'tax_rate', 'tax_inclusive']), $receipt['lines']);
        $payload = [
            'account_id' => $settings['account_id'], 'mode' => $settings['mode'],
            'automation_window' => $settings['automation_window'] ?? null,
            'digits' => Money::fractionDigits($order->currency),
            'receipt' => $facts, 'settings' => Arr::only($settings, ['payment_methods', 'vat_codes']),
            'buyer' => [
                'type' => $answers['buyer_type'] ?? null, 'name' => $business ? ($answers['business_name'] ?? '') : ($address['name'] ?? ''),
                'tax_number' => $business ? ($answers['tax_number'] ?? '') : '',
                'email' => $order->email, 'country' => $address['country'] ?? '', 'postcode' => $address['postal_code'] ?? '',
                'city' => $address['city'] ?? '', 'address' => trim(($address['street'] ?? '').' '.($address['street2'] ?? '')),
            ],
        ];
        // Keep private fields and the fiscal record on the same transactional connection as the order.
        app(Store::class)->table('sz_placements')->insert([
            'order_id' => $order->id, 'site' => $order->site, 'created_at' => now(),
            'payload' => json_encode(Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR),
        ]);
    }

    public function forOrder(string $id): array
    {
        $payload = app(Store::class)->table('sz_placements')->where('order_id', $id)->value('payload');
        if ($payload === null) {
            throw new DomainException(__('szamlazz::fiscal.no_snapshot'));
        }

        return json_decode(Crypt::decryptString(json_decode($payload, true, flags: JSON_THROW_ON_ERROR)), true, flags: JSON_THROW_ON_ERROR);
    }
}
