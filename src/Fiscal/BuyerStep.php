<?php

namespace Sellissimo\SzamlazzHu\Fiscal;

use Sellissimo\Core\Contracts\CheckoutStep;
use Sellissimo\SzamlazzHu\Settings;

final class BuyerStep implements CheckoutStep
{
    public const HANDLE = 'szamlazz_buyer';

    public function label(): string
    {
        return __('szamlazz::fiscal.billing');
    }

    public function applies(array $publicQuote, string $site): bool
    {
        return self::domestic($publicQuote['address'] ?? [], $site, $publicQuote['currency'] ?? '');
    }

    public static function domestic(array $address, string $site, string $currency): bool
    {
        return $currency === 'HUF' && strtoupper($address['country'] ?? '') === 'HU'
            && filled(app(Settings::class)->forSite($site)['account_id'] ?? null);
    }

    public function fields(array $publicQuote, string $site): array
    {
        return [
            'buyer_type' => [
                'label' => __('szamlazz::fiscal.type'), 'type' => 'select', 'required' => true, 'private' => true,
                'options' => ['person' => __('szamlazz::fiscal.person'), 'business' => __('szamlazz::fiscal.business')],
            ],
            'business_name' => [
                'label' => __('szamlazz::fiscal.company'), 'private' => true,
                'rules' => ['required_if:checkout_steps.szamlazz_buyer.buyer_type,business', 'max:150'],
            ],
            'tax_number' => [
                'label' => __('szamlazz::fiscal.tax_number'), 'private' => true,
                'rules' => ['required_if:checkout_steps.szamlazz_buyer.buyer_type,business', 'regex:/^\d{8}-[1-5]-\d{2}$/D'],
            ],
        ];
    }
}
