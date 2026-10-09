<?php

// Author: ramanpal singh | URL: https://kwebby.com

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Domain\Finance\InvoiceService;
use App\Support\Conflict;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.'.config('database.default').'.database') !== 'counsel_contract_test') {
    throw new RuntimeException('Concurrency workers run only against the isolated contract database.');
}
$store = app(RecordStore::class);
$user = new CrmUser($store->get('users', $argv[2]));
$service = app(InvoiceService::class);
for ($attempt = 0; $attempt < 10; $attempt++) {
    try {
        $record = $argv[1] === 'issue' ? $service->issue($user, $argv[3]) : $service->payment($user, $argv[3], ['amount_minor' => '10000', 'method' => 'bank_transfer', 'reference' => 'Concurrency contract', 'idempotency_key' => $argv[4]]);
        echo json_encode(['id' => $record['id'], 'number' => $record['number'] ?? null], JSON_THROW_ON_ERROR);
        exit(0);
    } catch (Conflict $e) {
        if ($attempt === 9) {
            throw $e;
        }
        usleep(random_int(10000, 50000));
    } catch (Throwable $e) {
        fwrite(STDERR, get_class($e).': '.$e->getMessage());
        exit(1);
    }
}
