<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Domain\Finance\InvoiceService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Explicit opt-in: shared real database, no enclosing PHPUnit transaction. */
final class FinanceConcurrencyTest extends TestCase
{
    public function test_parallel_issuance_and_duplicate_payment_have_single_effects(): void
    {
        if (getenv('RUN_FINANCE_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Run explicitly against MySQL or PostgreSQL counsel_contract_test.');
        }
        $connection = config('database.default');
        $database = config('database.connections.'.$connection);
        $this->assertContains($connection, ['mysql', 'pgsql']);
        $this->assertSame('counsel_contract_test', $database['database']);
        $store = app(RecordStore::class);
        $tag = 'concurrency-'.Str::uuid();
        $user = new CrmUser($store->create('users', ['name' => $tag, 'roles' => ['owner'], 'email' => $tag.'@example.test', 'status' => 'active', 'password' => Hash::make(Str::random(32))]));
        $businessCreated = $store->get('settings', 'business') === null;
        if ($businessCreated) {
            $store->create('settings', ['legal_name' => 'Concurrency test firm', 'address' => 'Isolated test database', 'invoice_prefix' => 'TEST'], 'business');
        }
        $initialSequences = array_column($store->query('invoice_sequences', [], 10000), 'id');
        $invoiceIds = [];
        try {
            for ($i = 0; $i < 6; $i++) {
                $invoiceIds[] = app(InvoiceService::class)->save($user, ['recipient' => ['name' => 'Concurrency client'], 'currency' => 'USD', 'items' => [['description' => 'Service', 'unit_minor' => '10000']], 'notes' => $tag])['id'];
            }
            $env = ['APP_ENV' => 'testing', 'CRM_STORE' => 'sql', 'DB_CONNECTION' => $connection, 'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => $database['database'], 'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => (string) ($database['password'] ?? ''), 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
            $workers = [];
            foreach ($invoiceIds as $id) {
                $worker = new Process([PHP_BINARY, base_path('tests/fixtures/finance_worker.php'), 'issue', $user->id, $id], base_path(), $env);
                $worker->setTimeout(30)->start();
                $workers[] = $worker;
            }
            $numbers = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $numbers[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR)['number'];
            }
            $this->assertCount(6, array_unique($numbers));
            $workers = [];
            for ($i = 0; $i < 6; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/fixtures/finance_worker.php'), 'payment', $user->id, $invoiceIds[0], $tag], base_path(), $env);
                $worker->setTimeout(30)->start();
                $workers[] = $worker;
            }
            $paymentIds = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $paymentIds[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR)['id'];
            }
            $this->assertCount(1, array_unique($paymentIds));
            $this->assertSame('10000', $store->get('invoices', $invoiceIds[0])['paid_minor']);
            $this->assertCount(1, $store->query('payments', ['invoice_id' => $invoiceIds[0]]));
        } finally {
            foreach ($workers ?? [] as $worker) {
                if ($worker->isRunning()) {
                    $worker->wait();
                }
            }
            // Remove this run's records only, preserving any unrelated fixtures.
            foreach ($store->query('payments', [], 10000) as $r) {
                if (in_array($r['invoice_id'] ?? null, $invoiceIds, true)) {
                    $store->delete('payments', $r['id']);
                }
            }
            foreach ($store->query('jobs', [], 10000) as $r) {
                if (in_array($r['payload']['invoice_id'] ?? null, $invoiceIds, true)) {
                    $store->delete('jobs', $r['id']);
                }
            }
            foreach ($store->query('audit', [], 10000) as $r) {
                if (($r['actor_id'] ?? null) === $user->id) {
                    $store->delete('audit', $r['id']);
                }
            }
            foreach ($invoiceIds as $id) {
                $store->delete('invoices', $id);
            }
            foreach ($store->query('invoice_sequences', [], 10000) as $r) {
                if (! in_array($r['id'], $initialSequences, true)) {
                    $store->delete('invoice_sequences', $r['id']);
                }
            }
            $store->delete('users', $user->id);
            if ($businessCreated) {
                $store->delete('settings', 'business');
            }
        }
    }
}
