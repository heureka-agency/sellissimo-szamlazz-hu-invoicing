<?php

namespace Sellissimo\SzamlazzHu\Tests\Fiscal;

use Sellissimo\Core\Contracts\PaymentGateway;
use Sellissimo\Core\Money;
use Sellissimo\Core\Registry;
use Sellissimo\SzamlazzHu\Fiscal\Placement;
use Sellissimo\SzamlazzHu\Settings;
use Sellissimo\SzamlazzHu\Tests\PilotTestCase;
use Statamic\Facades\User;

require_once dirname(__DIR__).'/PilotTestCase.php';

final class PlacementPrivacyTest extends PilotTestCase
{
    public function test_draft_payment_link_collects_and_validates_the_business_buyer_fields(): void
    {
        config(['sellissimo.currencies.default' => 'HUF', 'sellissimo.taxes.HU.standard' => 2700]);
        app(Settings::class)->save('default', ['mode' => 'test', 'vat_codes' => ['standard' => '27'],
            'payment_methods' => ['demo' => 'Bankkártya']], 'fixture-key');
        $this->entry('Digital guide', ['sellissimo_download' => 'books/guide.txt', 'sellissimo_sku' => 'DRAFT-GUIDE',
            'sellissimo_prices' => ['HUF' => 1000 * 10 ** Money::fractionDigits('HUF')]]);
        $this->actingAs(User::make()->id('draft-editor')->email('staff@example.test')->makeSuper());
        $input = ['email' => 'buyer@example.test', 'site' => 'default', 'revision' => 0, 'items' => [['sku' => 'DRAFT-GUIDE', 'qty' => 1]],
            'address' => ['country' => 'HU', 'name' => 'Contact Person', 'street' => 'Test utca 1.', 'city' => 'Budapest', 'postal_code' => '1011']];
        $this->post(cp_route('sellissimo.drafts.save'), $input)->assertSessionHasNoErrors()->assertRedirect();
        $draft = $this->store->table('drafts')->firstOrFail();
        $link = $this->get(cp_route('sellissimo.drafts.edit', $draft->id))->inertiaProps('draft.link');
        auth()->logout();
        $page = $this->get($link)->assertOk()->assertSee('checkout_steps[szamlazz_buyer][tax_number]', false);
        $payment = ['gateway' => 'demo', 'digest' => $page->viewData('digest')];
        $buyer = ['buyer_type' => 'business', 'business_name' => 'Pilot company'];
        $this->postJson($link, $payment + ['checkout_steps' => ['szamlazz_buyer' => $buyer]])
            ->assertUnprocessable()->assertJsonValidationErrors('checkout_steps.szamlazz_buyer.tax_number');
        $buyer['tax_number'] = '12345676-2-42';
        $this->post($link, $payment + ['checkout_steps' => ['szamlazz_buyer' => $buyer]])->assertRedirect('https://example.test/pay');
        $order = $this->store->table('orders')->where('cart_id', $draft->cart_id)->firstOrFail();
        self::assertStringNotContainsString($buyer['tax_number'], $order->receipt);
        self::assertStringNotContainsString($buyer['tax_number'], $this->store->table('drafts')->where('id', $draft->id)->value('input'));
        self::assertSame($buyer['tax_number'], app(Placement::class)->forOrder($order->id)['buyer']['tax_number']);
    }

    public function test_company_identity_is_available_to_invoicing_and_absent_from_the_order_and_gateway(): void
    {
        config(['sellissimo.currencies.default' => 'HUF', 'sellissimo.payments' => ['capture'],
            'sellissimo.taxes.HU.standard' => 2700, 'statamic.editions.addons.'.Settings::PACKAGE => 'free']);
        app(Settings::class)->save('default', ['mode' => 'test', 'vat_codes' => ['standard' => '27'],
            'payment_methods' => ['capture' => 'Bankkártya']], 'fixture-key');
        $gateway = $this->createMock(PaymentGateway::class);
        $gateway->expects(self::once())->method('start')->willReturnCallback(function (object $order) {
            self::assertStringNotContainsString('12345676-2-42', json_encode($order));
            self::assertStringNotContainsString('Restricted buyer name', json_encode($order));

            return ['id' => 'fixture-session', 'url' => 'https://example.test/pay'];
        });
        app(Registry::class)->payment('capture', $gateway);
        $this->entry('Digital guide', ['sellissimo_download' => 'books/guide.txt', 'sellissimo_sku' => 'INVOICE-GUIDE',
            'sellissimo_prices' => ['HUF' => 1000 * 10 ** Money::fractionDigits('HUF')]]);
        [$id, $headers] = $this->cart(['INVOICE-GUIDE' => 1]);
        $input = ['email' => 'buyer@example.test', 'gateway' => 'capture', 'address' => ['country' => 'HU',
            'name' => 'Contact Person', 'street' => 'Test utca 1.', 'city' => 'Budapest', 'postal_code' => '1011'],
            'checkout_steps' => ['szamlazz_buyer' => ['buyer_type' => 'business', 'business_name' => 'Restricted buyer name', 'tax_number' => '12345676-2-42']]];
        $quote = $this->postJson(route('sellissimo.api.quote', $id), $input, $headers)->assertOk();
        $this->postJson(route('sellissimo.api.checkout', $id), $input + ['quote_token' => $quote->json('quote_token')], $headers)
            ->assertSuccessful()->assertDontSee('12345676-2-42');
        $order = $this->store->table('orders')->where('cart_id', $id)->firstOrFail();
        self::assertStringNotContainsString('12345676-2-42', $order->receipt);
        self::assertStringNotContainsString('Restricted buyer name', $order->receipt);
        self::assertStringNotContainsString('12345676-2-42', $this->store->table('sz_placements')->where('order_id', $order->id)->value('payload'));
        $placement = app(Placement::class)->forOrder($order->id);
        self::assertSame('12345676-2-42', $placement['buyer']['tax_number']);
        self::assertSame('Restricted buyer name', $placement['buyer']['name']);
    }
}
