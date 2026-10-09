<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature;

use App\Infrastructure\FirestoreRecordStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FirestoreStoreTest extends TestCase
{
    public function test_firestore_values_preserve_nested_records_and_exact_money_strings(): void
    {
        $record = ['title' => 'Test', 'money' => '9007199254740993', 'version' => 2, 'enabled' => true, 'optional' => null, 'tags' => ['a', 'b'], 'nested' => ['k' => 'v']];
        $this->assertSame($record, FirestoreRecordStore::decode(FirestoreRecordStore::encode($record)));
    }

    public function test_rest_adapter_buffers_writes_and_uses_atomic_commit_without_grpc(): void
    {
        config(['crm.firestore.project' => 'test-project', 'crm.firestore.database' => '(default)', 'crm.firestore.emulator' => '127.0.0.1:8080']);
        $committed = [];
        Http::fake(function (Request $request) use (&$committed) {
            $url = $request->url();
            if (str_ends_with($url, ':beginTransaction')) {
                return Http::response(['transaction' => 'tx-test']);
            }
            if (str_ends_with($url, ':commit')) {
                $committed = $request->data()['writes'];

                return Http::response(['commitTime' => '2026-01-01T00:00:00Z']);
            }
            if (str_ends_with($url, ':rollback')) {
                return Http::response([]);
            }

            return Http::response(['error' => ['status' => 'NOT_FOUND']], 404);
        });
        $store = new FirestoreRecordStore;
        $store->transaction(function () use ($store) {
            $record = $store->create('invoices', ['amount_minor' => '9007199254740993'], 'invoice-1');
            $this->assertSame($record, $store->get('invoices', 'invoice-1'));
            $store->put('invoices', 'invoice-1', array_merge($record, ['status' => 'issued']), 1);
            $store->create('jobs', ['type' => 'invoice.issued'], 'job-1');
        });
        $this->assertCount(2, $committed);
        $this->assertSame(['exists' => false], $committed[0]['currentDocument']);
        $this->assertSame('2', $committed[0]['update']['fields']['version']['integerValue']);
        $this->assertSame('9007199254740993', $committed[0]['update']['fields']['amount_minor']['stringValue']);
    }

    public function test_firestore_rolls_back_a_failed_application_transaction(): void
    {
        config(['crm.firestore.project' => 'test-project', 'crm.firestore.database' => '(default)', 'crm.firestore.emulator' => '127.0.0.1:8080']);
        Http::fake(fn (Request $r) => str_ends_with($r->url(), ':beginTransaction') ? Http::response(['transaction' => 'tx-test']) : Http::response([]));
        try {
            (new FirestoreRecordStore)->transaction(fn () => throw new \RuntimeException('fail'));
        } catch (\RuntimeException) {
        }
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), ':rollback'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), ':commit'));
    }
}
