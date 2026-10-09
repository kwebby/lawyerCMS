<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature;

use App\Contracts\RecordStore;
use App\Infrastructure\FirestoreRecordStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecordStoreIterationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sql_iteration_pages_through_every_record_including_timestamp_ties(): void
    {
        $store = app(RecordStore::class);
        Carbon::setTestNow('2026-01-01 09:00:00');
        foreach (range(1, 7) as $i) {
            $store->create('contacts', ['name' => 'Tied '.$i, 'status' => $i % 2 ? 'active' : 'archived']);
        }
        Carbon::setTestNow('2026-01-02 09:00:00');
        foreach (range(1, 3) as $i) {
            $store->create('contacts', ['name' => 'Later '.$i, 'status' => 'active']);
        }
        Carbon::setTestNow();

        $expected = array_column($store->query('contacts', [], 10000), 'id');
        $this->assertSame($expected, array_column(iterator_to_array($store->each('contacts', [], 3), false), 'id'));
        $this->assertCount(10, array_unique($expected));
        $this->assertSame(array_column($store->query('contacts', ['status' => 'active'], 10000), 'id'), array_column(iterator_to_array($store->each('contacts', ['status' => 'active'], 2), false), 'id'));
    }

    public function test_firestore_iteration_continues_after_each_full_page_without_a_transaction(): void
    {
        config(['crm.firestore.project' => 'test-project', 'crm.firestore.database' => '(default)', 'crm.firestore.emulator' => '127.0.0.1:8080']);
        $root = 'projects/test-project/databases/(default)/documents/contacts/';
        $document = fn (string $id, string $created) => ['document' => ['name' => $root.$id, 'fields' => array_map(FirestoreRecordStore::encode(...), ['id' => $id, 'created_at' => $created, 'version' => 1])]];
        $queries = [];
        Http::fake(function (Request $request) use (&$queries, $document) {
            $queries[] = $request->data()['structuredQuery'];

            return Http::response(match (count($queries)) {
                1 => [$document('c', '2026-01-03'), $document('b', '2026-01-02')],
                2 => [$document('a', '2026-01-01'), ['readTime' => '2026-01-04T00:00:00Z']],
            });
        });

        $records = iterator_to_array((new FirestoreRecordStore)->each('contacts', ['status' => 'active'], 2), false);

        $this->assertSame(['c', 'b', 'a'], array_column($records, 'id'));
        $this->assertCount(2, $queries);
        $this->assertSame(['created_at', '__name__'], array_column(array_column($queries[0]['orderBy'], 'field'), 'fieldPath'));
        $this->assertArrayNotHasKey('startAt', $queries[0]);
        $this->assertSame(['values' => [['stringValue' => '2026-01-02'], ['referenceValue' => $root.'b']], 'before' => false], $queries[1]['startAt']);
        Http::assertNotSent(fn (Request $r) => isset($r->data()['transaction']));
    }

    public function test_firestore_iteration_refuses_to_run_inside_a_transaction(): void
    {
        config(['crm.firestore.project' => 'test-project', 'crm.firestore.database' => '(default)', 'crm.firestore.emulator' => '127.0.0.1:8080']);
        Http::fake(fn (Request $r) => str_ends_with($r->url(), ':beginTransaction') ? Http::response(['transaction' => 'tx-test']) : Http::response([]));
        $store = new FirestoreRecordStore;

        $this->expectException(\LogicException::class);
        $store->transaction(fn () => iterator_to_array($store->each('contacts')));
    }
}
