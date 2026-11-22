<?php

use Illuminate\Contracts\Console\Kernel;
use Sellissimo\SzamlazzHu\Agent\Client;
use Sellissimo\SzamlazzHu\Ledger;
use Sellissimo\SzamlazzHu\Settings;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$connection, $key, $orderId, $digest, $barrier, $worker] = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['app.key' => $key, 'database.connections.worker' => $connection, 'sellissimo.connection' => 'worker',
    'statamic.editions.addons.'.Settings::PACKAGE => 'free']);
$app->forgetInstance('encrypter');
$app->instance(Client::class, new class($barrier) extends Client
{
    public function __construct(private string $barrier) {}

    public function submit(#[SensitiveParameter] string $key, array $payload, string $externalId): array
    {
        file_put_contents($this->barrier.'.calls', $externalId."\n", FILE_APPEND | LOCK_EX);
        usleep(150000);

        return ['number' => 'CONCURRENT-1', 'pdf' => '%PDF-1.4 synthetic'];
    }
});
touch($barrier.'.'.$worker);
$deadline = microtime(true) + 10;
while (! is_file($barrier) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($barrier)) {
    exit(1);
}
echo app(Ledger::class)->issue($orderId, 'invoice', null, [], $digest, 'operator-'.$worker);
