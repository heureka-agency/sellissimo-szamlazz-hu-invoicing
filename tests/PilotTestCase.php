<?php

namespace Sellissimo\SzamlazzHu\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Sellissimo\Core\Contracts\PaymentGateway;
use Sellissimo\Core\Registry;
use Sellissimo\Core\Store;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Tests\TestCase;

abstract class PilotTestCase extends TestCase
{
    protected Store $store;

    protected PaymentGateway $gateway;

    private string $catalogDirectory;

    private ?string $sqliteFile = null;

    private array $entries = [];

    private array $migrations = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogDirectory = sys_get_temp_dir().'/szamlazz-catalog-'.Str::uuid();
        File::ensureDirectoryExists($this->catalogDirectory);
        Stache::store('collections')->directory($this->catalogDirectory);
        Stache::store('entries')->directory($this->catalogDirectory);
        $driver = getenv('SELLISSIMO_TEST_DRIVER') ?: 'sqlite';
        $connection = $driver === 'sqlite'
            ? ['driver' => 'sqlite', 'database' => $this->sqliteFile = tempnam(sys_get_temp_dir(), 'szamlazz-'), 'busy_timeout' => 10000, 'transaction_mode' => 'IMMEDIATE']
            : ['driver' => $driver, 'host' => '127.0.0.1', 'port' => getenv('SELLISSIMO_TEST_PORT'), 'database' => 'sellissimo_test',
                'username' => $driver === 'mysql' ? 'root' : 'postgres', 'password' => getenv('SELLISSIMO_TEST_PASSWORD') ?: '',
                'prefix' => 't'.bin2hex(random_bytes(5)).'_', 'prefix_indexes' => true];
        config(['database.connections.sellissimo_tests' => $connection, 'sellissimo.connection' => 'sellissimo_tests',
            'sellissimo.collection' => 'products', 'sellissimo.collections' => [], 'sellissimo.payments' => ['demo'],
            'sellissimo.currencies.default' => 'HUF']);
        Collection::make('products')->title('Products')->save();
        $this->store = app(Store::class);
        foreach ([base_path('vendor/sellissimo/core'), dirname(__DIR__)] as $package) {
            foreach (glob($package.'/database/migrations/*.php') as $file) {
                $migration = require $file;
                $migration->up();
                $this->migrations[] = $migration;
            }
        }
        $this->gateway = $this->createStub(PaymentGateway::class);
        $this->gateway->method('start')->willReturnCallback(fn ($order) => ['id' => 'test_'.$order->id, 'url' => 'https://example.test/pay']);
        $this->gateway->method('outcome')->willReturnCallback(fn ($order) => [
            'status' => 'paid', 'amount' => (int) $order->total, 'currency' => $order->currency, 'reference' => 'test-payment',
        ]);
        app(Registry::class)->payment('demo', $this->gateway);
        Storage::fake('sellissimo_private')->put('books/guide.txt', 'Test download');
    }

    protected function entry(string $title, array $data): void
    {
        $entry = Entry::make()->collection('products')->locale('default')->slug('invoice-test-'.Str::uuid())->data(['title' => $title, ...$data]);
        $entry->save();
        $this->entries[] = $entry;
    }

    protected function cart(array $items): array
    {
        $cart = $this->postJson(route('sellissimo.api.create'), ['site' => 'default'])->assertCreated()->json();
        $headers = ['Authorization' => 'Bearer '.$cart['token']];
        foreach ($items as $sku => $quantity) {
            $this->putJson(route('sellissimo.api.item', $cart['id']), compact('sku', 'quantity'), $headers)->assertNoContent();
        }

        return [$cart['id'], $headers];
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->entries) as $entry) {
            $entry->delete();
        }
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }
        DB::purge('sellissimo_tests');
        if ($this->sqliteFile) {
            unlink($this->sqliteFile);
        }
        File::deleteDirectory($this->catalogDirectory);
        parent::tearDown();
    }
}
