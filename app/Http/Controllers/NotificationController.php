<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers;

use App\Contracts\RecordStore;
use Illuminate\Http\Request;

final class NotificationController extends Controller
{
    public function __construct(private RecordStore $store) {}

    public function index(Request $request): mixed
    {
        $records = $this->store->query('notifications', ['user_id' => $request->user()->id], 500);
        if ($category = $request->query('category')) {
            $records = array_values(array_filter($records, fn ($r) => $r['category'] === $category));
        }
        if ($request->boolean('unread')) {
            $records = array_values(array_filter($records, fn ($r) => empty($r['read_at'])));
        }
        usort($records, fn ($a, $b) => (int) empty($b['read_at']) <=> (int) empty($a['read_at']) ?: (int) ($b['action_required'] ?? false) <=> (int) ($a['action_required'] ?? false) ?: strcmp($a['due_at'] ?? '9999', $b['due_at'] ?? '9999'));

        return response()->json(['data' => $records]);
    }

    public function read(Request $request, string $id): mixed
    {
        $record = $this->store->get('notifications', $id);
        abort_unless($record && $record['user_id'] === $request->user()->id, 404);

        return response()->json(['data' => $this->store->put('notifications', $id, array_merge($record, ['read_at' => now()->toISOString()]), $record['version'])]);
    }
}
