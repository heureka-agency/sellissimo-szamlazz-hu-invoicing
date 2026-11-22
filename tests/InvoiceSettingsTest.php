<?php

namespace Sellissimo\SzamlazzHu\Tests;

use Sellissimo\Core\Checkout;
use Sellissimo\Core\Money;
use Sellissimo\Core\PaymentSettings;
use Sellissimo\Core\Registry;
use Sellissimo\SzamlazzHu\Agent\Client;
use Sellissimo\SzamlazzHu\Fiscal\Placement;
use Sellissimo\SzamlazzHu\Ledger;
use Sellissimo\SzamlazzHu\Settings;
use Statamic\Auth\PermissionCache;
use Statamic\Facades\Site;
use Statamic\Facades\User;

require_once __DIR__.'/PilotTestCase.php';

final class InvoiceSettingsTest extends PilotTestCase
{
    private function url(string $action = 'settings', string $site = 'default'): string
    {
        return cp_route('szamlazz.'.$action, ['site' => $site]);
    }

    private function staff(bool $manage = true): void
    {
        $operator = User::make()->id('mapping-editor')->email('operator@example.test');
        $permissions = ['access cp', 'access default site'];
        if ($manage) {
            $permissions[] = 'manage sellissimo invoice settings';
        }
        app(PermissionCache::class)->put($operator->id(), collect($permissions));
        $this->actingAs($operator);
    }

    private function form(array $overrides = []): array
    {
        return $overrides + ['mode' => 'test', 'language' => 'hu', 'due_days' => '8'];
    }

    private function multisite(): void
    {
        config(['statamic.system.multisite' => true, 'statamic.editions.pro' => true]);
        Site::setSites([
            'default' => ['name' => 'Hungary', 'url' => '/', 'locale' => 'hu_HU'],
            'south' => ['name' => 'Second store', 'url' => '/south', 'locale' => 'en_US'],
        ]);
    }

    public function test_options_follow_core_classes_and_site_gateways_and_keep_inactive_mappings(): void
    {
        $this->multisite();
        $this->staff();
        config(['sellissimo.taxes.HU.from_config' => 500, 'sellissimo.tax_settings.default.shipping_class' => 'shipping_class']);
        $this->store->table('tax_classes')->insert(['handle' => 'periodicals', 'name' => 'Printed periodicals', 'inclusive' => false]);
        app(Registry::class)->payment('custom_provider', $this->gateway);
        app(Registry::class)->payment('south_only', $this->gateway);
        app(PaymentSettings::class)->save('default', ['methods' => ['custom_provider', 'provider_offline']], 0, 'staff');
        app(PaymentSettings::class)->save('south', ['methods' => ['south_only']], 0, 'staff');
        app(Settings::class)->save('default', ['payment_methods' => ['retired' => 'Cash desk'],
            'vat_codes' => ['obsolete' => 'AAM']], 'fixture-agent-key');
        $page = $this->get($this->url())->assertOk();
        self::assertSame(['default' => 'Hungary'], $page->viewData('sites'));
        self::assertSame('Printed periodicals', $page->viewData('vatClasses')['periodicals']);
        self::assertArrayHasKey('from_config', $page->viewData('vatClasses'));
        self::assertArrayHasKey('shipping_class', $page->viewData('vatClasses'));
        self::assertArrayHasKey('standard', $page->viewData('vatClasses'));
        self::assertArrayHasKey('obsolete', $page->viewData('vatClasses'));
        self::assertSame(['obsolete'], $page->viewData('inactiveVat'));
        self::assertSame(['custom_provider', 'provider_offline', 'retired'], array_keys($page->viewData('gateways')));
        self::assertSame(['provider_offline', 'retired'], $page->viewData('inactiveGateways'));
        self::assertSame(['obsolete' => 'AAM'], $page->viewData('settings')['vat_codes']);
        $this->get($this->url('settings', 'south'))->assertNotFound();
        $this->post($this->url('save', 'south'), $this->form())->assertNotFound();
        self::assertNull($this->store->table('sz_settings')->where('site', 'south')->first());

        $this->postJson($this->url('save'), $this->form(['payment_methods' => [['handle' => 'south_only', 'value' => 'Card']]]))
            ->assertUnprocessable()->assertJsonValidationErrors('payment_methods.0.handle');
        $this->staff(false);
        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url('save'), $this->form())->assertForbidden();
    }

    public function test_structured_and_legacy_forms_preserve_existing_credentials_and_historical_records(): void
    {
        $this->actingAs(User::make()->id('owner')->email('merchant@example.test')->makeSuper());
        config(['sellissimo.currencies.default' => 'HUF', 'sellissimo.taxes.HU.standard' => 2700,
            'sellissimo.taxes.HU.exempt' => 0, 'statamic.editions.addons.'.Settings::PACKAGE => 'free']);
        $settings = app(Settings::class);
        $this->post($this->url('save'), $this->form(['vat_codes' => "standard=27\nexempt=AAM", 'payment_methods' => 'demo=Old card label',
            'credential' => 'fixture-agent-key', 'automation_enabled' => '1', 'send_email' => '1', 'trigger' => 'dispatch']))
            ->assertSessionHasNoErrors()->assertRedirect($this->url());
        $before = $settings->forSite('default');
        self::assertFalse($before['automation_enabled']);
        self::assertFalse($before['send_email']);
        self::assertSame(['standard' => '27', 'exempt' => 'AAM'], $before['vat_codes']);
        $settings->save('default', ['payment_methods' => $before['payment_methods'] + ['retired' => 'Old cash desk']], null);
        $agent = $this->createMock(Client::class);
        $agent->expects(self::once())->method('submit')->willReturn(['number' => 'FIXTURE-1', 'pdf' => '%PDF-1.4 fixture']);
        $this->app->instance(Client::class, $agent);
        $this->entry('Guide', ['sellissimo_download' => 'books/guide.txt', 'sellissimo_sku' => 'MAPPING-GUIDE',
            'sellissimo_prices' => ['HUF' => 1000 * 10 ** Money::fractionDigits('HUF')]]);
        $order = $this->place();
        $ledger = app(Ledger::class);
        $preview = $ledger->preview($order, 'invoice', null);
        $docId = $ledger->issue($order->id, 'invoice', null, [], Ledger::fingerprint($preview), 'operator');
        $originalPlacement = $this->store->table('sz_placements')->where('order_id', $order->id)->value('payload');
        $originalDocument = $this->store->table('sz_documents')->find($docId);

        $this->post($this->url('save'), $this->form(['credential' => '', 'vat_codes' => [['handle' => 'exempt', 'value' => '']],
            'payment_methods' => [['handle' => 'demo', 'value' => 'Új kártyás fizetés']]]))
            ->assertSessionHasNoErrors()->assertRedirect($this->url());
        $after = $settings->forSite('default');
        self::assertSame($before['account_id'], $after['account_id']);
        self::assertSame('fixture-agent-key', $settings->key('default', 'test', $after['account_id']));
        self::assertSame(['standard' => '27'], $after['vat_codes']);
        self::assertSame(['demo' => 'Új kártyás fizetés', 'retired' => 'Old cash desk'], $after['payment_methods']);
        self::assertSame($originalPlacement, $this->store->table('sz_placements')->where('order_id', $order->id)->value('payload'));
        self::assertEquals($originalDocument, $this->store->table('sz_documents')->find($docId));
        self::assertSame('Old card label', $ledger->history($order->id)->sole()->payload['payment_method']);
        $next = $this->place();
        self::assertSame('Új kártyás fizetés', $ledger->preview($next, 'invoice', null)['payment_method']);
        self::assertArrayNotHasKey('exempt', app(Placement::class)->forOrder($next->id)['settings']['vat_codes']);
        config(['statamic.editions.addons.'.Settings::PACKAGE => 'pro']);
        $this->post($this->url('save'), $this->form(['automation_enabled' => '1', 'trigger' => 'dispatch', 'send_email' => '1']))
            ->assertSessionHasNoErrors()->assertRedirect($this->url());
        $pro = $settings->forSite('default');
        self::assertTrue($pro['automation_enabled']);
        self::assertNotEmpty($pro['automation_window']);
        self::assertTrue($pro['send_email']);
        self::assertSame('dispatch', $pro['trigger']);
        self::assertSame($after['payment_methods'], $pro['payment_methods']);
    }

    public function test_failed_edits_show_field_errors_and_restore_only_non_secret_values(): void
    {
        $this->staff();
        $settings = app(Settings::class);
        $settings->save('default', ['vat_codes' => ['standard' => '27'], 'payment_methods' => ['demo' => 'Original']],
            'original-secret-key');
        $before = $settings->forSite('default');
        foreach ([
            ['vat_codes', [['handle' => 'standard', 'value' => '99']], 'vat_codes.0.value'],
            ['vat_codes', [['handle' => 'standard', 'value' => '27'], ['handle' => 'standard', 'value' => '5']], 'vat_codes.1.handle'],
            ['payment_methods', [['handle' => 'demo', 'value' => str_repeat('x', 81)]], 'payment_methods.0.value'],
            ['vat_codes', 'bad legacy data', 'vat_codes'],
            ['payment_methods', [['handle' => 'demo', 'value' => ['nested']]], 'payment_methods.0'],
            ['language', 'unsupported', 'language'],
        ] as [$field, $value, $error]) {
            $input = $this->form([$field => $value, 'due_days' => '19', 'credential' => 'replacement-secret-key']);
            $response = $this->from($this->url())->post($this->url('save'), $input);
            $response->assertRedirect($this->url())->assertSessionHasErrors($error);
            self::assertSame('19', session()->getOldInput('due_days'));
            self::assertSame($value, session()->getOldInput($field));
            self::assertArrayNotHasKey('credential', session()->getOldInput());
            self::assertSame($before, $settings->forSite('default'));
        }
        $this->post($this->url('save'), $this->form(['mode' => 'live', 'credential' => 'original-secret-key']))
            ->assertSessionHasErrors('credential');
        self::assertSame('live', session()->getOldInput('mode'));
        self::assertArrayNotHasKey('credential', session()->getOldInput());
        self::assertSame($before, $settings->forSite('default'));
        self::assertSame(1, $this->store->table('sz_accounts')->count());

        $this->postJson($this->url('save'), $this->form(['credential' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors('credential')->assertDontSee('short');
        $this->get($this->url())->assertOk()->assertDontSee('original-secret-key')->assertDontSee('replacement-secret-key');
    }

    public function test_standard_field_feedback_uses_the_control_panel_language(): void
    {
        $this->staff();
        foreach (['en', 'hu'] as $locale) {
            $this->app->setLocale($locale);
            $fields = ['mode' => '?', 'language' => '?', 'due_days' => 400, 'trigger' => '?',
                'electronic' => 'invalid', 'automation_enabled' => 'invalid', 'send_email' => 'invalid'];
            $result = $this->postJson($this->url('save'), $fields)->assertUnprocessable();
            foreach (array_keys($fields) as $field) {
                $result->assertJsonPath("errors.$field.0", __("szamlazz::settings_validation.$field", [], $locale));
            }
            self::assertStringContainsString($locale === 'hu' ? 'fizetési határidőt' : 'whole number', $result->json('errors.due_days.0'));
        }
    }

    private function place(): object
    {
        [$id, $headers] = $this->cart(['MAPPING-GUIDE' => 1]);
        $input = ['email' => 'customer@example.test', 'gateway' => 'demo', 'address' => [
            'country' => 'HU', 'name' => 'Pilot Customer', 'street' => 'Minta utca 1.', 'city' => 'Budapest', 'postal_code' => '1011',
        ], 'checkout_steps' => ['szamlazz_buyer' => ['buyer_type' => 'person']]];
        $quote = $this->postJson(route('sellissimo.api.quote', $id), $input, $headers)->assertOk();
        $this->postJson(route('sellissimo.api.checkout', $id), $input + ['quote_token' => $quote->json('quote_token')], $headers)->assertSuccessful();
        $order = $this->store->table('orders')->where('cart_id', $id)->firstOrFail();

        return app(Checkout::class)->reconcile($order->id);
    }
}
