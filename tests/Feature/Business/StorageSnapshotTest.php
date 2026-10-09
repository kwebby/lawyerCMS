<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

use App\Infrastructure\FirestoreRecordStore;
use App\Support\Conflict;
use Illuminate\Support\Facades\Http;

final class StorageSnapshotTest extends BusinessTestCase
{
    public function test_keyset_snapshot_and_restore_preserve_versions_and_timestamps(): void
    {
        $one = $this->store->create('contacts', ['name' => 'First', 'owner_id' => $this->owner->id]);
        $this->store->put('contacts', $one['id'], array_replace($one, ['name' => 'Revised']), $one['version']);
        $records = [];
        $cursor = null;
        do {
            $page = $this->store->scan($cursor, 1);
            $records = array_merge($records, $page['records']);
            $cursor = $page['cursor'];
        } while ($cursor !== null);
        $this->assertCount(3, $records);
        foreach ($records as $entry) {
            $this->store->delete($entry['collection'], $entry['record']['id']);
        }
        $this->assertSame([], $this->store->scan()['records']);
        $this->store->restore($records);
        $after = $this->store->scan()['records'];
        $this->assertEquals($records, $after);
        $this->assertSame(2, $this->store->get('contacts', $one['id'])['version']);
    }

    public function test_restore_rejects_collisions_atomically(): void
    {
        $duplicate = $this->store->get('users', $this->owner->id);
        $new = array_replace($duplicate, ['id' => 'new-restore-user']);
        try {
            $this->store->restore([['collection' => 'users', 'record' => $new], ['collection' => 'users', 'record' => $duplicate]]);
            $this->fail('Expected collision.');
        } catch (Conflict $e) {
            $this->assertNull($this->store->get('users', 'new-restore-user'));
        }
    }

    public function test_firestore_restore_commits_literal_database_resource_names_and_exact_version(): void
    {
        config(['crm.firestore.project' => 'snapshot-test', 'crm.firestore.database' => '(default)', 'crm.firestore.emulator' => '127.0.0.1:9999']);
        $record = ['id' => 'invoice-123', 'version' => 7, 'created_at' => '2026-10-01T10:00:00.000000Z', 'updated_at' => '2026-10-02T10:00:00.000000Z', 'total_minor' => '10000'];
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), ':beginTransaction')) {
                return Http::response(['transaction' => 'transaction-token']);
            }
            if ($request->method() === 'GET') {
                return Http::response([], 404);
            }
            if (str_ends_with($request->url(), ':commit')) {
                return Http::response(['commitTime' => now()->toISOString()]);
            }

            return Http::response([], 500);
        });
        (new FirestoreRecordStore)->restore([['collection' => 'invoices', 'record' => $record]]);
        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), ':commit') && $request['writes'][0]['update']['name'] === 'projects/snapshot-test/databases/(default)/documents/invoices/invoice-123' && $request['writes'][0]['update']['fields']['version'] === ['integerValue' => '7'] && $request['writes'][0]['currentDocument'] === ['exists' => false];
        });
    }

    public function test_firestore_snapshot_pages_collections_and_documents_with_opaque_cursor(): void
    {
        config(['crm.firestore.project' => 'snapshot-test', 'crm.firestore.database' => '(default)', 'crm.firestore.emulator' => '127.0.0.1:9999']);
        $record = ['id' => 'invoice-123', 'version' => 7, 'created_at' => '2026-10-01T10:00:00.000000Z', 'updated_at' => '2026-10-02T10:00:00.000000Z', 'total_minor' => '10000'];
        Http::fake(function ($request) use ($record) {
            if (str_contains($request->url(), ':listCollectionIds')) {
                return Http::response(['collectionIds' => ['invoices', 'users']]);
            }
            if (str_contains($request->url(), '/invoices?')) {
                return Http::response(['documents' => [['name' => 'projects/snapshot-test/databases/(default)/documents/invoices/invoice-123', 'fields' => array_map(FirestoreRecordStore::encode(...), $record)]]]);
            }
            if (str_contains($request->url(), '/users?')) {
                return Http::response(['documents' => []]);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });
        $store = new FirestoreRecordStore;
        $first = $store->scan(null, 1);
        $this->assertSame([['collection' => 'invoices', 'record' => $record]], $first['records']);
        $this->assertNotNull($first['cursor']);
        $last = $store->scan($first['cursor'], 1);
        $this->assertSame([], $last['records']);
        $this->assertNull($last['cursor']);
    }
}
