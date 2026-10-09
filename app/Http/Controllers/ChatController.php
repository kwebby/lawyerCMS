<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Domain\Communications\NotificationDelivery;
use App\Support\Access;
use App\Support\Audit;
use Illuminate\Http\Request;

final class ChatController extends Controller
{
    public function __construct(private RecordStore $store, private Access $access, private Audit $audit) {}

    private function conversation(Request $request, string $id): array
    {
        $record = $this->store->get('conversations', $id);
        abort_unless($record !== null, 404);
        abort_unless(in_array($request->user()->id, $record['member_ids'] ?? [], true), 403);
        $this->access->authorize($request->user(), 'conversations.read', $record);
        if (isset($record['matter_id'])) {
            $matter = $this->store->get('matters', $record['matter_id']);
            abort_unless($matter !== null, 403);
            $this->access->authorize($request->user(), 'matters.read', $matter);
        }

        return $record;
    }

    public function index(Request $request): mixed
    {
        $records = array_filter($this->store->query('conversations', [], 1000), fn ($c) => in_array($request->user()->id, $c['member_ids'] ?? [], true));
        $records = array_filter($records, function ($c) use ($request) {
            try {
                $this->conversation($request, $c['id']);

                return true;
            } catch (\Throwable) {
                return false;
            }
        });
        $records = array_map(function ($record) use ($request) {
            $read = $this->store->get('chat_reads', hash('sha256', $record['id'].':'.$request->user()->id));
            $record['unread_count'] = max(0, $record['last_sequence'] - ($read['sequence'] ?? 0));

            return $record;
        }, array_values($records));

        return response()->json(['data' => $records]);
    }

    public function create(Request $request): mixed
    {
        $this->access->authorize($request->user(), 'conversations.write');
        $data = $request->validate(['title' => 'required|string|max:160', 'member_ids' => 'required|array|max:50', 'member_ids.*' => 'string', 'client_visible' => 'boolean', 'matter_id' => 'nullable|string']);
        $members = array_values(array_unique(array_merge($data['member_ids'], [$request->user()->id])));
        $matter = isset($data['matter_id']) ? $this->store->get('matters', $data['matter_id']) : null;
        if (isset($data['matter_id'])) {
            abort_unless($matter !== null, 404);
            $this->access->authorize($request->user(), 'matters.write', $matter);
        }
        $clientIds = [];
        foreach ($members as $id) {
            $user = $this->store->get('users', $id);
            abort_unless($user && ($user['status'] ?? 'active') === 'active', 422, 'Invalid conversation member.');
            $isClient = (bool) array_intersect($user['roles'], ['client', 'prospect']);
            if ($isClient) {
                abort_unless(($data['client_visible'] ?? false) && $matter && in_array($id, $matter['client_ids'] ?? [], true), 422, 'Client membership requires an explicit matter grant.');
                $clientIds[] = $id;
            }
            if ($matter) {
                $this->access->authorize(new CrmUser($user), 'matters.read', $matter);
            }
        }
        $record = $this->store->create('conversations', array_merge($data, ['member_ids' => $members, 'client_ids' => $clientIds, 'owner_id' => $request->user()->id, 'last_sequence' => 0]));

        return response()->json(['data' => $record], 201);
    }

    public function messages(Request $request, string $id): mixed
    {
        $conversation = $this->conversation($request, $id);
        $data = $request->validate(['after' => 'sometimes|integer|min:0']);
        $after = min((int) ($data['after'] ?? 0), $conversation['last_sequence']);
        $bucket = intdiv($after, 100);
        $messages = [];
        // Fixed buckets keep polling bounded across SQL and Firestore, including histories beyond 10,000 messages.
        foreach ([$bucket, $bucket + 1] as $page) {
            if ($page * 100 >= $conversation['last_sequence']) {
                break;
            }
            foreach ($this->store->query('messages', ['conversation_id' => $id, 'sequence_bucket' => $page], 100, 'sequence', 'asc') as $message) {
                if ($message['sequence'] > $after) {
                    $messages[] = $message;
                }
                if (count($messages) === 100) {
                    break 2;
                }
            }
        }
        $next = $messages ? end($messages)['sequence'] : $after;

        return response()->json(['data' => $messages, 'last_sequence' => $conversation['last_sequence'], 'next_after' => $next, 'has_more' => $next < $conversation['last_sequence']]);
    }

    public function send(Request $request, string $id): mixed
    {
        $data = $request->validate(['body' => 'required|string|max:10000', 'idempotency_key' => 'required|string|min:8|max:128', 'attachment_ids' => 'array|max:10', 'attachment_ids.*' => 'string']);
        $record = $this->store->transaction(function () use ($request, $id, $data) {
            $conversation = $this->conversation($request, $id);
            $key = hash('sha256', $id.':'.$request->user()->id.':'.$data['idempotency_key']);
            $requestHash = hash('sha256', json_encode(['body' => $data['body'], 'attachment_ids' => $data['attachment_ids'] ?? []], JSON_THROW_ON_ERROR));
            if ($previous = $this->store->get('messages', $key)) {
                abort_unless(hash_equals($previous['request_hash'] ?? hash('sha256', json_encode(['body' => $previous['body'], 'attachment_ids' => $previous['attachment_ids'] ?? []], JSON_THROW_ON_ERROR)), $requestHash), 409, 'The message key was used for different content.');

                return $previous;
            }
            foreach ($data['attachment_ids'] ?? [] as $fileId) {
                $file = $this->store->get('documents', $fileId);
                abort_unless($file && ($file['status'] ?? '') === 'clean', 422, 'Attachment not available.');
                foreach ($conversation['member_ids'] as $member) {
                    $u = $this->store->get('users', $member);
                    $this->access->authorize(new CrmUser($u), 'documents.read', $file);
                }
            }
            $sequence = $conversation['last_sequence'] + 1;
            $message = $this->store->create('messages', ['conversation_id' => $id, 'body' => $data['body'], 'attachment_ids' => $data['attachment_ids'] ?? [], 'sequence' => $sequence, 'sequence_bucket' => intdiv($sequence - 1, 100), 'request_hash' => $requestHash, 'matter_id' => $conversation['matter_id'] ?? null, 'sender_id' => $request->user()->id, 'sender_name' => $request->user()->name, 'member_ids' => $conversation['member_ids'], 'client_ids' => $conversation['client_ids'], 'owner_id' => $request->user()->id], $key);
            $this->store->put('conversations', $id, array_merge($conversation, ['last_sequence' => $sequence, 'last_message_at' => now()->toISOString()]), $conversation['version']);
            app(NotificationDelivery::class)->emit(['id' => $message['id'], 'type' => 'message.received', 'payload' => ['conversation_id' => $id, 'user_ids' => array_values(array_diff($conversation['member_ids'], [$request->user()->id]))]]);

            return $message;
        });

        return response()->json(['data' => $record], 201);
    }

    public function read(Request $request, string $id): mixed
    {
        $data = $request->validate(['sequence' => 'required|integer|min:0']);
        $record = $this->store->transaction(function () use ($request, $id, $data) {
            $conversation = $this->conversation($request, $id);
            $key = hash('sha256', $id.':'.$request->user()->id);
            $old = $this->store->get('chat_reads', $key);
            $sequence = max($old['sequence'] ?? 0, min((int) $data['sequence'], $conversation['last_sequence']));

            return $this->store->put('chat_reads', $key, ['conversation_id' => $id, 'user_id' => $request->user()->id, 'sequence' => $sequence], $old['version'] ?? null);
        });

        return response()->json(['data' => $record]);
    }

    public function edit(Request $request, string $id, string $messageId): mixed
    {
        $this->conversation($request, $id);
        $data = $request->validate(['body' => 'nullable|string|max:10000', 'expected_version' => 'required|integer']);
        $message = $this->store->get('messages', $messageId);
        abort_unless($message && $message['conversation_id'] === $id, 404);
        abort_unless($message['sender_id'] === $request->user()->id, 403);
        $record = $this->store->transaction(function () use ($request, $id, $messageId, $message, $data) {
            $this->conversation($request, $id);
            $this->store->create('message_revisions', ['message_id' => $messageId, 'body' => $message['body'], 'actor_id' => $request->user()->id]);
            $this->audit->log($request->user()->id, 'message.changed', 'messages', $messageId);

            return $this->store->put('messages', $messageId, array_merge($message, ['body' => $data['body'] ?? '', 'deleted_at' => empty($data['body']) ? now()->toISOString() : null, 'edited_at' => now()->toISOString()]), $data['expected_version']);
        });

        return response()->json(['data' => $record]);
    }
}
