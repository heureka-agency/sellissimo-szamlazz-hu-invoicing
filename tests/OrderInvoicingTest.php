<?php

namespace Sellissimo\SzamlazzHu\Tests;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Sellissimo\Core\Checkout;
use Sellissimo\Core\Money;
use Sellissimo\SzamlazzHu\Agent\Client;
use Sellissimo\SzamlazzHu\Agent\Rejected;
use Sellissimo\SzamlazzHu\Automation\ProcessOrder;
use Sellissimo\SzamlazzHu\Automation\Workflow;
use Sellissimo\SzamlazzHu\Ledger;
use Sellissimo\SzamlazzHu\Settings;
use Statamic\Auth\PermissionCache;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Symfony\Component\Process\Process;

require_once __DIR__.'/PilotTestCase.php';

class OrderInvoicingTest extends PilotTestCase
{
    private bool $useProvider = false;

    private function installConnection(bool $pro = false, bool $demonstration = false): void
    {
        $this->useProvider = $demonstration && (bool) getenv('SZAMLAZZ_TEST_KEY_FILE');
        config(['statamic.editions.addons.'.Settings::PACKAGE => $pro ? 'pro' : 'free',
            'sellissimo.currencies.default' => 'HUF', 'sellissimo.taxes.HU.standard' => 2700]);
        app(Settings::class)->save('default', ['mode' => 'test', 'vat_codes' => ['standard' => '27'], 'payment_methods' => ['demo' => 'bankkártya'],
            'automation_enabled' => $pro], $this->key());
        $this->store->table('order_sequences')->where('name', 'orders')->update(['last_number' => random_int(100000000, 900000000)]);
        $this->entry('Sellissimo próbarendelés', ['sellissimo_download' => 'books/guide.txt', 'sellissimo_sku' => 'HU-TEST',
            'sellissimo_prices' => ['HUF' => 1000 * (10 ** Money::fractionDigits('HUF'))]]);
    }

    private function key(): string
    {
        return $this->useProvider ? trim(file_get_contents(getenv('SZAMLAZZ_TEST_KEY_FILE'))) : 'fixture-key';
    }

    private function agent(bool $loseReply = false): object
    {
        $live = $this->useProvider;
        $double = new class($live, $loseReply) extends Client
        {
            public int $calls = 0;

            public ?string $number = null;

            public function __construct(private bool $live, private bool $loseReply)
            {
                parent::__construct();
            }

            public function submit(#[\SensitiveParameter] string $key, array $payload, string $externalId): array
            {
                $this->calls++;
                if ($this->live && ($dir = getenv('SZAMLAZZ_TEST_EVIDENCE_DIR'))) {
                    file_put_contents($dir.'/'.($this->loseReply ? 'pro' : 'free').'-request.json', json_encode(compact('payload', 'externalId'), JSON_THROW_ON_ERROR), LOCK_EX);
                }
                $response = $this->live ? parent::submit($key, $payload, $externalId) : ['number' => 'TEST-1', 'pdf' => '%PDF-1.4 fixture'];
                $this->number = $response['number'];
                if ($this->loseReply) {
                    throw new \RuntimeException('Simulated connection loss after provider issuance');
                }

                return $response;
            }

            public function lookup(#[\SensitiveParameter] string $key, array $payload, ?string $number, ?string $externalId): array
            {
                return $this->live ? parent::lookup($key, $payload, $number, $externalId) : ['number' => $this->number, 'pdf' => '%PDF-1.4 fixture'];
            }
        };
        $this->app->instance(Client::class, $double);

        return $double;
    }

    private function order(): object
    {
        [$cart,$headers] = $this->cart(['HU-TEST' => 1]);
        $fields = ['email' => 'pilot@example.invalid', 'gateway' => 'demo', 'address' => ['country' => 'HU', 'name' => 'Sellissimo Teszt Vevő',
            'street' => 'Teszt utca 1.', 'city' => 'Budapest', 'postal_code' => '1011'], 'checkout_steps' => ['szamlazz_buyer' => ['buyer_type' => 'person']]];
        $quote = $this->postJson(route('sellissimo.api.quote', $cart), $fields, $headers)->assertOk();
        $this->postJson(route('sellissimo.api.checkout', $cart), $fields + ['quote_token' => $quote->json('quote_token')], $headers)->assertSuccessful();
        $order = $this->store->table('orders')->where('cart_id', $cart)->firstOrFail();

        return app(Checkout::class)->reconcile($order->id);
    }

    private function staff(): void
    {
        $this->actingAs(User::make()->id('invoice-tester')->email('operator@example.test')->makeSuper());
    }

    private function verifyPdf(string $id, string $flow, string $number): void
    {
        $response = $this->get(cp_route('szamlazz.pdf', $id))->assertOk()->assertHeader('content-type', 'application/pdf');
        self::assertStringStartsWith('%PDF-', $response->getContent());
        if ($this->useProvider && ($path = getenv('SZAMLAZZ_TEST_EVIDENCE_DIR'))) {
            file_put_contents($path.'/'.$flow.'.pdf', $response->getContent(), LOCK_EX);
            file_put_contents($path.'/'.$flow.'.json', json_encode(['number' => $number, 'pdf_bytes' => strlen($response->getContent()),
                'sha256' => hash('sha256', $response->getContent())], JSON_THROW_ON_ERROR), LOCK_EX);
        }
    }

    public function test_free_checkout_review_issue_and_download(): void
    {
        $this->installConnection(demonstration: true);
        $agent = $this->agent();
        $order = $this->order();
        $this->staff();
        $this->get(cp_route('szamlazz.settings', ['site' => 'default']))->assertOk()->assertSee('Free')->assertDontSee($this->key());
        $this->get(cp_route('szamlazz.queue'))->assertForbidden();
        $this->get(cp_route('szamlazz.order', $order->id))->assertOk();
        $fields = ['kind' => 'invoice'];
        $preview = $this->post(cp_route('szamlazz.preview', $order->id), $fields)->assertOk()->assertSee('Teszt Vevő');
        $confirm = $fields + ['digest' => $preview->viewData('digest'), 'confirm' => '1'];
        self::assertSame(1270, $preview->viewData('payload')['gross']);
        $this->post(cp_route('szamlazz.issue', $order->id), $confirm)->assertRedirect();
        $this->post(cp_route('szamlazz.issue', $order->id), $confirm)->assertRedirect();
        $docs = app(Ledger::class)->history($order->id);
        self::assertCount(1, $docs);
        self::assertSame('issued', $docs[0]->status, $docs[0]->reason ?? '');
        self::assertSame(1, $agent->calls);
        $this->verifyPdf($docs[0]->id, 'free-checkout', $docs[0]->number);
    }

    public function test_rejected_operation_can_be_corrected_then_reversed_in_free(): void
    {
        $this->installConnection();
        $order = $this->order();
        $this->staff();
        $agent = new class extends Client
        {
            public int $calls = 0;

            public function submit(#[\SensitiveParameter] string $key, array $payload, string $externalId): array
            {
                if (++$this->calls === 1) {
                    throw new Rejected('Definitive test rejection.');
                }

                return ['number' => 'PILOT-'.$this->calls, 'pdf' => '%PDF-1.4 mock'];
            }
        };
        $this->app->instance(Client::class, $agent);
        foreach (['invoice', 'invoice', 'reversal'] as $index => $kind) {
            $fields = ['kind' => $kind];
            if ($index === 1) {
                $fields['due_date'] = now('Europe/Budapest')->addDays(5)->toDateString();
            }
            if ($kind === 'reversal') {
                $fields['source'] = 'manual-reversal';
            }
            $preview = $this->post(cp_route('szamlazz.preview', $order->id), $fields)->assertOk();
            if ($kind === 'reversal') {
                self::assertSame(-1270, $preview->viewData('payload')['gross']);
                self::assertSame('PILOT-2', $preview->viewData('payload')['original_number']);
            }
            $this->post(cp_route('szamlazz.issue', $order->id), $fields + ['confirm' => '1', 'digest' => $preview->viewData('digest')])->assertRedirect();
        }
        self::assertSame(2, app(Ledger::class)->history($order->id)->where('status', 'issued')->count());
        self::assertSame('Definitive test rejection.', $this->store->table('sz_attempts')->sole()->reason);
        $this->get(cp_route('szamlazz.order', $order->id))->assertOk()->assertSee('PILOT-3');
    }

    public function test_simultaneous_confirmations_claim_one_issuance(): void
    {
        $this->installConnection();
        $order = $this->order();
        $ledger = app(Ledger::class);
        $digest = Ledger::fingerprint($ledger->preview($order, 'invoice', null));
        $barrier = sys_get_temp_dir().'/sz-invoice-'.Str::uuid();
        $processes = [];
        try {
            foreach ([1, 2] as $worker) {
                $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/issue-worker.php'], base_path());
                $process->setInput(json_encode([config('database.connections.sellissimo_tests'), config('app.key'),
                    $order->id, $digest, $barrier, $worker], JSON_THROW_ON_ERROR));
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 12;
            while ((! is_file($barrier.'.1') || ! is_file($barrier.'.2')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            touch($barrier);
            $ids = [];
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
                $ids[] = $process->getOutput();
            }
            self::assertSame($ids[0], $ids[1]);
            self::assertSame([$ids[0]], file($barrier.'.calls', FILE_IGNORE_NEW_LINES));
            self::assertSame('issued', $ledger->history($order->id)->sole()->status);
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            foreach (glob($barrier.'*') as $path) {
                unlink($path);
            }
        }
    }

    public function test_viewers_cannot_issue_manage_credentials_or_access_another_sites_pdf(): void
    {
        $this->installConnection();
        $order = $this->order();
        $this->agent();
        $this->staff();
        $ledger = app(Ledger::class);
        $payload = $ledger->preview($order, 'invoice', null);
        $id = $ledger->issue($order->id, 'invoice', null, [], Ledger::fingerprint($payload), 'operator');
        config(['statamic.system.multisite' => true, 'statamic.editions.pro' => true]);
        Site::setSites([
            'default' => ['name' => 'HU', 'url' => '/', 'locale' => 'hu_HU'],
            'second' => ['name' => 'Other', 'url' => '/second', 'locale' => 'en_US'],
        ]);
        $viewer = User::make()->id('read-only')->email('viewer@example.test');
        app(PermissionCache::class)->put($viewer->id(), collect(['access cp', 'access default site', 'view sellissimo invoices']));
        $this->actingAs($viewer);
        $this->get(cp_route('szamlazz.pdf', $id))->assertOk();
        $this->postJson(cp_route('szamlazz.issue', $order->id))->assertForbidden();
        $this->getJson(cp_route('szamlazz.settings'))->assertForbidden();
        $this->store->table('orders')->where('id', $order->id)->update(['site' => 'second']);
        $this->get(cp_route('szamlazz.pdf', $id))->assertNotFound();
        $this->get(cp_route('szamlazz.order', $order->id))->assertNotFound();
    }

    public function test_unconfigured_store_and_historical_order_stay_usable(): void
    {
        $this->installConnection();
        $order = $this->order();
        $this->store->table('sz_placements')->where('order_id', $order->id)->delete();
        $this->staff();
        $this->postJson(cp_route('szamlazz.preview', $order->id), ['kind' => 'invoice'])->assertUnprocessable();
        $this->get(cp_route('szamlazz.order', $order->id))->assertOk()->assertSee(__('szamlazz::ui.external'));
        $this->assertCount(0, app(Ledger::class)->history($order->id));
    }

    public function test_pro_payment_and_lost_reply_recovery_do_not_resubmit(): void
    {
        Queue::fake();
        $this->installConnection(pro: true, demonstration: true);
        $agent = $this->agent(true);
        $order = $this->order();
        Queue::assertPushed(ProcessOrder::class);
        $window = app(Settings::class)->forSite('default')['automation_window'];
        $workflow = app(Workflow::class);
        $workflow->handle($order->id, $window);
        $ledger = app(Ledger::class);
        $first = $ledger->history($order->id)->sole();
        self::assertSame('uncertain', $first->status, $first->reason ?? '');
        $workflow->handle($order->id, $window);
        config(['statamic.editions.addons.'.Settings::PACKAGE => 'free']);
        app(Settings::class)->observeEdition();
        $ledger->reconcileDue();
        $doc = $ledger->history($order->id)->sole();
        self::assertSame('issued', $doc->status, $doc->reason ?? '');
        self::assertSame($agent->number, $doc->number);
        self::assertSame(1, $agent->calls);
        $this->staff();
        $this->verifyPdf($doc->id, 'pro-recovered-checkout', $doc->number);
    }
}
