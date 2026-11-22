<?php

namespace Sellissimo\SzamlazzHu\Tests\Automation;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Sellissimo\SzamlazzHu\Automation\Adjustments;
use Sellissimo\SzamlazzHu\Automation\CommercialSources;
use Sellissimo\SzamlazzHu\Automation\DocumentBalance;
use Sellissimo\SzamlazzHu\Automation\ProcessOrder;
use Sellissimo\SzamlazzHu\Automation\Workflow;
use Sellissimo\SzamlazzHu\Fiscal\InvoiceBuilder;
use Sellissimo\SzamlazzHu\Fiscal\Placement;
use Sellissimo\SzamlazzHu\Ledger;
use Sellissimo\SzamlazzHu\Settings;
use Sellissimo\SzamlazzHu\Tests\PilotTestCase;

require_once __DIR__.'/../PilotTestCase.php';

final class WorkflowTest extends PilotTestCase
{
    private function configure(string $trigger = 'payment'): array
    {
        config(['statamic.editions.addons.'.Settings::PACKAGE => 'pro']);
        $settings = app(Settings::class);
        $settings->save('default', ['automation_enabled' => true, 'trigger' => $trigger], 'fixture-agent-key');

        return $settings->forSite('default');
    }

    private function receipt(int $qty = 2, bool $digital = false): array
    {
        return ['currency' => 'HUF', 'total' => $qty * 12700, 'tax' => $qty * 2700, 'lines' => [[
            'key' => 'lamp', 'sku' => 'lamp', 'qty' => $qty, 'digital' => $digital, 'title' => 'Lámpa',
            'tax_rate' => 2700, 'tax_class' => 'standard', 'tax_inclusive' => true, 'tax' => $qty * 2700, 'total' => $qty * 12700,
        ]]];
    }

    private function seedOrder(array $settings, array $override = [], bool $digital = false): object
    {
        $id = (string) Str::uuid();
        $receipt = $this->receipt(2, $digital);
        $this->store->table('orders')->insert(array_replace([
            'id' => $id, 'cart_id' => (string) Str::uuid(), 'site' => 'default', 'gateway' => 'pay_on_delivery',
            'email' => 'customer@example.test', 'created_at' => now(), 'order_number' => random_int(100000, 999999),
            'receipt' => json_encode($receipt), 'total' => $receipt['total'], 'currency' => 'HUF', 'status' => 'paid', 'paid_at' => now(),
        ], $override));
        $placement = [
            'mode' => 'test', 'account_id' => $settings['account_id'], 'automation_window' => $settings['automation_window'],
            'digits' => 2, 'receipt' => $receipt, 'settings' => ['payment_methods' => ['pay_on_delivery' => 'Utánvét'], 'vat_codes' => ['standard' => '27']],
            'buyer' => ['type' => 'person', 'country' => 'HU', 'postcode' => '1011', 'city' => 'Budapest', 'name' => 'Teszt Elek',
                'address' => 'Fő utca 1.', 'email' => 'customer@example.test', 'tax_number' => ''],
        ];
        $this->store->table('sz_placements')->insert(['order_id' => $id, 'site' => 'default', 'created_at' => now(),
            'payload' => json_encode(Crypt::encryptString(json_encode($placement)))]);

        return $this->store->table('orders')->where('id', $id)->first();
    }

    private function mockLedger(): Ledger
    {
        $ledger = new class extends Ledger
        {
            public array $docs = [];

            public array $submitted = [];

            public function __construct() {}

            public function history(string $orderId): Collection
            {
                return collect($this->docs[$orderId] ?? []);
            }

            public function automatic(string $orderId, string $window, bool $paid): void
            {
                $this->submitted[] = [$orderId, $window, $paid];
                $this->docs[$orderId][] = (object) ['kind' => 'invoice', 'status' => 'issued', 'payload' => [], 'business_key' => 'invoice'];
            }
        };
        app()->instance(Ledger::class, $ledger);

        return $ledger;
    }

    public function test_missed_event_and_duplicate_notifications_converge_and_old_windows_do_not_resume(): void
    {
        $this->freezeTime();
        $settings = $this->configure();
        $order = $this->seedOrder($settings);
        $old = $this->seedOrder($settings);
        $ledger = $this->mockLedger();
        $workflow = app(Workflow::class);
        Bus::fake();
        $workflow->scan();
        Bus::assertDispatched(ProcessOrder::class, fn ($job) => $job->orderId === $order->id);
        event('sellissimo.order.paid', [$order->id]);
        $workflow->handle($order->id, $settings['automation_window']);
        $workflow->handle($order->id, $settings['automation_window']);
        self::assertSame([[$order->id, $settings['automation_window'], true]], $ledger->submitted);

        config(['statamic.editions.addons.'.Settings::PACKAGE => 'free']);
        $workflow->handle($old->id, $settings['automation_window']);
        $this->assertCount(1, $ledger->submitted);
        config(['statamic.editions.addons.'.Settings::PACKAGE => 'pro']);
        $new = app(Settings::class)->forSite('default')['automation_window'];
        self::assertNotSame($new, $settings['automation_window']);
        $workflow->handle($old->id, $settings['automation_window']);
        $workflow->handle($old->id, $new);
        self::assertCount(1, $ledger->submitted);
    }

    public function test_dispatch_reads_last_parcels_frozen_paid_state_and_digital_orders_require_payment(): void
    {
        $settings = $this->configure('dispatch');
        $workflow = app(Workflow::class);
        $order = $this->seedOrder($settings, ['tracking' => 'one', 'partially_shipped' => true]);
        $at = now()->addDay()->startOfDay()->setHour(12);
        self::assertNull($workflow->trigger($order, $settings));
        $this->store->table('parcels')->insert(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'number' => 2,
            'tracking' => 'two', 'items' => '{}', 'paid_at_dispatch' => false, 'created_at' => $at]);
        $order->partially_shipped = false;
        $order->tracking = 'two';
        $this->travelTo($at->copy()->addDays(2));
        self::assertSame($at->setTimezone('Europe/Budapest')->toDateString(), $workflow->triggerFacts($order, $settings)['fulfilment_date']);
        self::assertFalse($workflow->trigger($order, $settings), 'COD collected after the dispatch is still unpaid on the dispatch invoice.');
        $digital = $this->seedOrder($settings, digital: true);
        self::assertTrue($workflow->trigger($digital, $settings));
        $digital->paid_at = null;
        self::assertNull($workflow->trigger($digital, $settings));
        $digital->status = 'awaiting_collection';
        self::assertNull($workflow->trigger($digital, [...$settings, 'trigger' => 'payment']));
    }

    public function test_refund_parts_and_the_applied_amendment_form_one_review_and_a_reversal_reopens_it(): void
    {
        $settings = $this->configure();
        $order = $this->seedOrder($settings);
        $id = (string) Str::uuid();
        $this->store->table('amendments')->insert(['id' => $id, 'order_id' => $order->id, 'status' => 'awaiting_refund',
            'difference' => -12700, 'previous' => json_encode($this->receipt()), 'proposed' => json_encode($this->receipt(1)),
            'request_hash' => str_repeat('1', 64), 'reason' => 'Remove one lamp', 'author' => 'staff', 'created_at' => now()]);
        $ref = (string) Str::uuid();
        foreach (['succeeded', 'pending'] as $n => $status) {
            $this->store->table('refunds')->insert(['id' => $n ? $ref : (string) Str::uuid(), 'order_id' => $order->id,
                'sku' => '@amendment', 'group_id' => (string) Str::uuid(), 'amendment_id' => $id, 'status' => $status,
                'settled_at' => $n ? null : now(), 'amount' => 6350]);
        }
        $sources = app(CommercialSources::class);
        $key = 'amendment:'.$id;
        self::assertSame([$key], array_keys($sources->forOrder($order)));
        self::assertSame('partly_settled', $sources->forOrder($order)[$key]['code']);
        $this->store->table('refunds')->where('id', $ref)->update(['status' => 'succeeded', 'settled_at' => now()]);
        $this->store->table('amendments')->where('id', $id)->update(['status' => 'applied', 'applied_at' => now()]);
        self::assertTrue($sources->forOrder($order)[$key]['eligible']);
        $original = $this->original($order, $settings);
        $payload = app(Adjustments::class)->build($order, $original->payload, collect([$original]), 'correction', $key);
        self::assertSame([-100, -27, -127], [$payload['net'], $payload['vat'], $payload['gross']]);

        $ledger = $this->mockLedger();
        $ledger->docs[$order->id] = [$original, (object) ['kind' => 'correction', 'status' => 'issued', 'business_key' => $key, 'payload' => $payload]];
        $workflow = app(Workflow::class);
        $workflow->handle($order->id, $settings['automation_window']);
        $this->store->table('refunds')->where('id', $ref)->update(['status' => 'failed', 'reversed_at' => now(), 'settled_at' => null]);
        $workflow->handle($order->id, $settings['automation_window']);
        $review = $this->store->table('szamlazz_reviews')->where('order_id', $order->id)->get();
        self::assertCount(1, $review);
        self::assertStringStartsWith('reversed:'.$key.':', $review[0]->source);
        self::assertNull($review[0]->resolved_at);
        self::assertSame([], $ledger->submitted);
    }

    private function original(object $order, array $settings): object
    {
        $placement = app(Placement::class)->forOrder($order->id);
        $payload = app(InvoiceBuilder::class)->build($order, $placement, $settings);

        return (object) ['id' => (string) Str::uuid(), 'number' => 'TEST-1', 'kind' => 'invoice', 'business_key' => 'invoice',
            'account_id' => $settings['account_id'], 'mode' => 'test', 'status' => 'issued', 'payload' => $payload];
    }

    public function test_free_return_then_full_cancellation_credits_only_remaining_net_and_vat(): void
    {
        $settings = $this->configure();
        $order = $this->seedOrder($settings);
        $original = $this->original($order, $settings);
        config(['statamic.editions.addons.'.Settings::PACKAGE => 'free']);
        $adjustments = app(Adjustments::class);
        self::assertSame('reversal', $adjustments->build($order, $original->payload, collect([$original]), 'reversal', null)['kind']);
        $id = (string) Str::uuid();
        $this->store->table('return_requests')->insert(['id' => $id, 'order_id' => $order->id, 'line_key' => 'lamp', 'quantity' => 1,
            'reason' => 'Returned', 'status' => 'received', 'refund_entitlement' => 12700, 'created_at' => now(), 'updated_at' => now()]);
        $this->store->table('returns')->insert(['id' => $id, 'order_id' => $order->id, 'line_key' => 'lamp', 'quantity' => 1,
            'restocked' => 0, 'created_at' => now()]);
        $this->store->table('refunds')->insert(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'return_request_id' => $id,
            'sku' => 'lamp', 'amount' => 12700, 'status' => 'succeeded', 'settled_at' => now()]);
        $source = 'return:'.$id;
        $credit = $adjustments->build($order, $original->payload, collect([$original]), 'correction', $source);
        self::assertSame([-1, -100, -27, -127], [$credit['lines'][0]['qty'], $credit['net'], $credit['vat'], $credit['gross']]);
        $correction = (object) [...(array) $original, 'kind' => 'correction', 'business_key' => $source, 'id' => (string) Str::uuid(),
            'payload' => $credit];
        $order->status = 'cancelled';
        $this->store->table('refunds')->insert(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'sku' => '@all',
            'amount' => 12700, 'status' => 'succeeded', 'settled_at' => now()]);
        $final = $adjustments->build($order, $original->payload, collect([$original, $correction]), 'reversal', 'cancellation');
        self::assertSame(['correction', 'TEST-1', -100, -27, -127], [$final['kind'], $final['original_number'], $final['net'], $final['vat'], $final['gross']]);
        self::assertSame(0, $original->payload['gross'] + $credit['gross'] + $final['gross']);
    }

    public function test_one_forint_vat_difference_reverses_and_replaces_the_affected_item_at_valid_rounded_amounts(): void
    {
        // At 27% VAT the original business invoice rounds 0.54 to 1 Ft. The revised single unit rounds 0.27 to zero.
        $before = ['key' => 'item:part', 'qty' => 2, 'unit_net' => '1', 'unit' => 'db', 'vat_code' => '27',
            'name' => 'Part', 'net' => 2, 'vat' => 1, 'gross' => 3];
        $after = [...$before, 'qty' => 1, 'net' => 1, 'vat' => 0, 'gross' => 1];
        $lines = DocumentBalance::correction([$before], [$after], 'business');
        self::assertSame([-2, 1], array_column($lines, 'qty'));
        self::assertSame([-3, 1], array_column($lines, 'gross'));
        self::assertTrue(DocumentBalance::reproducible($lines, 'business'));
    }

    public function test_different_equal_price_replacement_without_a_payment_amendment_enters_review(): void
    {
        $settings = $this->configure();
        $order = $this->seedOrder($settings);
        $id = (string) Str::uuid();
        $this->store->table('return_requests')->insert(['id' => $id, 'order_id' => $order->id, 'line_key' => 'lamp', 'quantity' => 1,
            'reason' => 'Change colour', 'status' => 'exchanged', 'replacement' => 'lamp-blue', 'created_at' => now(), 'updated_at' => now()]);
        $candidate = app(CommercialSources::class)->forOrder($order)['exchange:'.$id];
        self::assertFalse($candidate['eligible']);
        self::assertSame('exchange_review', $candidate['code']);
    }

    public function test_linked_external_correction_requires_line_evidence_before_another_internal_document(): void
    {
        $settings = $this->configure();
        $order = $this->seedOrder($settings);
        $original = $this->original($order, $settings);
        $external = (object) [...(array) $original, 'kind' => 'correction', 'business_key' => 'refund:external',
            'payload' => ['external' => true, 'original_number' => $original->number, 'net' => -100, 'vat' => -27, 'gross' => -127]];
        $this->expectException(ValidationException::class);
        app(Adjustments::class)->build($order, $original->payload, collect([$original, $external]), 'reversal', null);
    }

    public function test_money_movement_alone_cannot_choose_a_vat_treatment(): void
    {
        $settings = $this->configure();
        $order = $this->seedOrder($settings);
        $original = $this->original($order, $settings);
        $refund = (string) Str::uuid();
        $this->store->table('refunds')->insert(['id' => $refund, 'order_id' => $order->id, 'sku' => '@provider',
            'amount' => 5000, 'status' => 'succeeded', 'settled_at' => now()]);
        $this->expectException(ValidationException::class);
        app(Adjustments::class)->build($order, $original->payload, collect([$original]), 'correction', 'refund:'.$refund);
    }
}
