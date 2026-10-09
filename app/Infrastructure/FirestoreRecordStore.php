<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Infrastructure;

use App\Contracts\RecordStore;
use App\Support\Conflict;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** REST-only Firestore storage; writes are buffered until the atomic commit. */
final class FirestoreRecordStore implements RecordStore
{
    private ?string $transactionId = null;

    private array $pending = [];

    private array $reads = [];

    private ?string $token = null;

    private int $tokenExpires = 0;

    private function root(): string
    {
        $project = config('crm.firestore.project');
        if (! $project || ! preg_match('/^[a-z0-9-]+$/D', $project)) {
            throw new \RuntimeException('Configure FIRESTORE_PROJECT_ID.');
        }
        $database = config('crm.firestore.database');
        if (! is_string($database) || ! preg_match('/^(?:\(default\)|[a-z][a-z0-9-]{2,62})$/D', $database)) {
            throw new \InvalidArgumentException('Invalid Firestore database ID.');
        }

        return 'projects/'.$project.'/databases/'.$database.'/documents';
    }

    private function name(string $collection, string $id): string
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $collection) || ! preg_match('/^[a-zA-Z0-9_.@-]{1,128}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid record path.');
        }

        return $this->root().'/'.$collection.'/'.$id;
    }

    private function request(string $method, string $path, array $data = []): array
    {
        $emulator = config('crm.firestore.emulator');
        if ($emulator && ! app()->environment(['testing', 'local'])) {
            throw new \RuntimeException('Firestore emulator is forbidden outside local/testing.');
        }
        $base = $emulator ? 'http://'.$emulator.'/v1/' : 'https://firestore.googleapis.com/v1/';
        $http = Http::acceptJson()->connectTimeout(5)->timeout(20);
        if (! $emulator) {
            if (! $this->token || time() > $this->tokenExpires) {
                $file = config('crm.firestore.credentials');
                if (! $file || ! is_readable($file)) {
                    throw new \RuntimeException('Firestore service account credentials are unavailable.');
                }
                $credentials = new ServiceAccountCredentials(['https://www.googleapis.com/auth/datastore'], json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
                $auth = $credentials->fetchAuthToken();
                $this->token = $auth['access_token'] ?? throw new \RuntimeException('Firestore authentication failed.');
                $this->tokenExpires = time() + ($auth['expires_in'] ?? 3600) - 60;
            }
            $http = $http->withToken($this->token);
        }
        $response = $method === 'GET' ? $http->get($base.$path, $data) : $http->send($method, $base.$path, ['json' => $data]);
        if ($response->status() === 404) {
            return [];
        }
        if (in_array($response->status(), [409, 412])) {
            throw new Conflict('Firestore concurrent transaction.');
        }
        $response->throw();

        return $response->json() ?? [];
    }

    public static function encode(mixed $value): array
    {
        return match (true) {
            $value === null => ['nullValue' => null], is_bool($value) => ['booleanValue' => $value],
            is_int($value) => ['integerValue' => (string) $value], is_float($value) => ['doubleValue' => $value],
            is_string($value) => ['stringValue' => $value],
            is_array($value) && array_is_list($value) => ['arrayValue' => ['values' => array_map(self::encode(...), $value)]],
            is_array($value) => ['mapValue' => ['fields' => array_map(self::encode(...), $value)]],
            default => throw new \InvalidArgumentException('Only JSON values are supported.')
        };
    }

    public static function decode(array $value): mixed
    {
        foreach ($value as $type => $item) {
            return match ($type) {
                'nullValue' => null,'integerValue' => (int) $item,'doubleValue' => (float) $item,
                'arrayValue' => array_map(self::decode(...), $item['values'] ?? []),
                'mapValue' => array_map(self::decode(...), $item['fields'] ?? []),
                default => $item
            };
        }

        return null;
    }

    public function get(string $collection, string $id): ?array
    {
        $name = $this->name($collection, $id);
        if (array_key_exists($name, $this->pending)) {
            return $this->pending[$name];
        }
        if ($this->transactionId && array_key_exists($name, $this->reads)) {
            return $this->reads[$name]['record'];
        }
        $doc = $this->request('GET', $name, $this->transactionId ? ['transaction' => $this->transactionId] : []);
        $record = isset($doc['fields']) ? array_map(self::decode(...), $doc['fields']) : null;
        if ($this->transactionId) {
            $this->reads[$name] = ['record' => $record, 'updateTime' => $doc['updateTime'] ?? null];
        }

        return $record;
    }

    private function structuredQuery(string $collection, array $filters, int $limit, string $orderBy, string $direction): array
    {
        $this->name($collection, 'validate');
        foreach (array_merge(array_keys($filters), [$orderBy]) as $key) {
            if (! preg_match('/^[a-z_][a-z0-9_]*$/D', $key)) {
                throw new \InvalidArgumentException('Invalid query field.');
            }
        }
        $where = [];
        foreach ($filters as $key => $value) {
            $where[] = ['fieldFilter' => ['field' => ['fieldPath' => $key], 'op' => 'EQUAL', 'value' => self::encode($value)]];
        }
        $query = ['from' => [['collectionId' => $collection]], 'limit' => $limit, 'orderBy' => [['field' => ['fieldPath' => $orderBy], 'direction' => $direction === 'asc' ? 'ASCENDING' : 'DESCENDING']]];
        if ($where) {
            $query['where'] = count($where) === 1 ? $where[0] : ['compositeFilter' => ['op' => 'AND', 'filters' => $where]];
        }

        return $query;
    }

    public function query(string $collection, array $filters = [], int $limit = 100, string $orderBy = 'created_at', string $direction = 'desc'): array
    {
        $query = $this->structuredQuery($collection, $filters, max(1, min(10000, $limit)), $orderBy, $direction);
        $body = ['structuredQuery' => $query];
        if ($this->transactionId) {
            $body['transaction'] = $this->transactionId;
        }
        $rows = $this->request('POST', $this->root().':runQuery', $body);
        $records = [];
        foreach ($rows as $row) {
            if (! isset($row['document'])) {
                continue;
            }
            $doc = $row['document'];
            $record = array_map(self::decode(...), $doc['fields']);
            $records[$doc['name']] = $record;
            if ($this->transactionId) {
                $this->reads[$doc['name']] = ['record' => $record, 'updateTime' => $doc['updateTime']];
            }
        }
        foreach ($this->pending as $name => $record) {
            if (str_starts_with($name, $this->root().'/'.$collection.'/')) {
                unset($records[$name]);
                if ($record && collect($filters)->every(fn ($value, $key) => ($record[$key] ?? null) === $value)) {
                    $records[$name] = $record;
                }
            }
        }
        $records = array_values($records);
        usort($records, fn ($a, $b) => ($direction === 'asc' ? 1 : -1) * (($a[$orderBy] ?? null) <=> ($b[$orderBy] ?? null)));

        return array_slice($records, 0, max(1, min(10000, $limit)));
    }

    public function each(string $collection, array $filters = [], int $pageSize = 500): \Generator
    {
        if ($this->transactionId) {
            throw new \LogicException('Use query() for reads inside a transaction.');
        }
        $pageSize = max(1, min(1000, $pageSize));
        // __name__ DESCENDING is Firestore's implicit tie order here, so existing created_at indexes still apply.
        $query = $this->structuredQuery($collection, $filters, $pageSize, 'created_at', 'desc');
        $query['orderBy'][] = ['field' => ['fieldPath' => '__name__'], 'direction' => 'DESCENDING'];
        $cursor = null;
        do {
            if ($cursor) {
                $query['startAt'] = ['values' => $cursor, 'before' => false];
            }
            $count = 0;
            foreach ($this->request('POST', $this->root().':runQuery', ['structuredQuery' => $query]) as $row) {
                if (! isset($row['document'])) {
                    continue;
                }
                $count++;
                $doc = $row['document'];
                $cursor = [$doc['fields']['created_at'] ?? ['nullValue' => null], ['referenceValue' => $doc['name']]];
                yield array_map(self::decode(...), $doc['fields']);
            }
        } while ($count === $pageSize);
    }

    public function create(string $collection, array $data, ?string $id = null): array
    {
        $id ??= (string) Str::uuid();
        if (! $this->transactionId) {
            return $this->transaction(fn () => $this->create($collection, $data, $id));
        }
        if ($this->get($collection, $id)) {
            throw new Conflict('This record already exists.');
        }
        $record = array_merge($data, ['id' => $id, 'version' => 1, 'created_at' => now()->toISOString(), 'updated_at' => now()->toISOString()]);
        $this->pending[$this->name($collection, $id)] = $record;

        return $record;
    }

    public function put(string $collection, string $id, array $data, ?int $expectedVersion = null): array
    {
        if (! $this->transactionId) {
            return $this->transaction(fn () => $this->put($collection, $id, $data, $expectedVersion));
        }
        $old = $this->get($collection, $id);
        if ($expectedVersion !== null && ($old['version'] ?? null) !== $expectedVersion) {
            throw new Conflict;
        }
        if (! $old) {
            return $this->create($collection, $data, $id);
        }
        $record = array_merge($data, ['id' => $id, 'version' => $old['version'] + 1, 'created_at' => $old['created_at'], 'updated_at' => now()->toISOString()]);
        $this->pending[$this->name($collection, $id)] = $record;

        return $record;
    }

    public function delete(string $collection, string $id, ?int $expectedVersion = null): void
    {
        if (! $this->transactionId) {
            $this->transaction(fn () => $this->delete($collection, $id, $expectedVersion));

            return;
        }
        $old = $this->get($collection, $id);
        if ($expectedVersion !== null && ($old['version'] ?? null) !== $expectedVersion) {
            throw new Conflict;
        }
        if ($old) {
            $this->pending[$this->name($collection, $id)] = null;
        }
    }

    public function scan(?string $cursor = null, int $limit = 500): array
    {
        if ($this->transactionId) {
            throw new \LogicException('Snapshot scan must run outside write transactions.');
        }
        $limit = max(1, min(500, $limit));
        $state = RecordSnapshot::cursor($cursor) ?? ['collections' => [], 'collection_token' => null, 'document_token' => null];
        if (! is_array($state['collections'] ?? null)) {
            throw new \InvalidArgumentException('Invalid Firestore snapshot cursor.');
        }
        if (! $state['collections']) {
            $body = ['pageSize' => 100];
            if (! empty($state['collection_token'])) {
                $body['pageToken'] = $state['collection_token'];
            }
            $page = $this->request('POST', $this->root().':listCollectionIds', $body);
            $state['collections'] = $page['collectionIds'] ?? [];
            $state['collection_token'] = $page['nextPageToken'] ?? null;
            $state['document_token'] = null;
        }
        if (! $state['collections']) {
            return ['records' => [], 'cursor' => $state['collection_token'] ? RecordSnapshot::encode($state) : null];
        }
        $collection = $state['collections'][0];
        $this->name($collection, 'validate');
        $params = ['pageSize' => $limit, 'orderBy' => '__name__'];
        if (! empty($state['document_token'])) {
            $params['pageToken'] = $state['document_token'];
        }
        $page = $this->request('GET', $this->root().'/'.$collection, $params);
        $records = [];
        foreach ($page['documents'] ?? [] as $document) {
            $record = array_map(self::decode(...), $document['fields'] ?? []);
            if (($record['id'] ?? null) !== basename($document['name'])) {
                throw new \UnexpectedValueException('The Firestore database contains a non-CRM record.');
            }
            $records[] = ['collection' => $collection, 'record' => $record];
        }
        if (! empty($page['nextPageToken'])) {
            $state['document_token'] = $page['nextPageToken'];
        } else {
            array_shift($state['collections']);
            $state['document_token'] = null;
        }

        return ['records' => $records, 'cursor' => ($state['collections'] || $state['collection_token']) ? RecordSnapshot::encode($state) : null];
    }

    public function restore(array $batch): void
    {
        $batch = RecordSnapshot::batch($batch);
        $this->transaction(function () use ($batch) {
            foreach ($batch as $entry) {
                $collection = $entry['collection'];
                $record = $entry['record'];
                if ($this->get($collection, $record['id'])) {
                    throw new Conflict('Restore destination contains an existing record.');
                }
                $this->pending[$this->name($collection, $record['id'])] = $record;
            }
        });
    }

    private function commitWrites(): array
    {
        $writes = [];
        foreach ($this->pending as $name => $record) {
            $read = $this->reads[$name] ?? ['record' => null, 'updateTime' => null];
            $precondition = $read['updateTime'] ? ['updateTime' => $read['updateTime']] : ['exists' => false];
            $write = $record === null ? ['delete' => $name] : ['update' => ['name' => $name, 'fields' => array_map(self::encode(...), $record)]];
            $write['currentDocument'] = $precondition;
            $writes[] = $write;
        }

        return $writes;
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->transactionId) {
            return $callback();
        }
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->transactionId = $this->request('POST', $this->root().':beginTransaction', ['options' => ['readWrite' => (object) []]])['transaction'];
            $this->pending = [];
            $this->reads = [];
            try {
                $result = $callback();
                $this->request('POST', $this->root().':commit', ['transaction' => $this->transactionId, 'writes' => $this->commitWrites()]);

                return $result;
            } catch (\Throwable $e) {
                try {
                    $this->request('POST', $this->root().':rollback', ['transaction' => $this->transactionId]);
                } catch (\Throwable) {
                }
                if (! $e instanceof Conflict || $attempt === 4) {
                    throw $e;
                }
                usleep(random_int(10000, 80000) * ($attempt + 1));
            } finally {
                $this->transactionId = null;
                $this->pending = [];
                $this->reads = [];
            }
        }
        throw new Conflict('Transaction retries exhausted.');
    }
}
