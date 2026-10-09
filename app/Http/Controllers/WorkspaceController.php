<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers;

use App\Contracts\RecordStore;
use App\Domain\Publishing\Themes;
use App\Support\Access;
use App\Support\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class WorkspaceController extends Controller
{
    public function __construct(private RecordStore $store, private Access $access, private Settings $settings) {}

    public function records(Request $request, string $collection): array
    {
        $ability = match ($collection) {
            'payroll_runs' => 'payroll','ai_runs' => 'ai_runs',default => $collection
        };
        // Notifications are a per-user feed: read the newest of this user's own rather than scanning everyone's.
        $records = $collection === 'notifications' ? $this->store->query('notifications', ['user_id' => $request->user()->id], 500) : $this->store->each($collection);

        return array_map(fn ($r) => array_diff_key($r, array_flip(['password', 'mfa_secret', 'mfa_pending', 'recovery_codes', 'remember_token', 'path', 'blocks_path', 'result_path', 'payload', 'api_key', 'secret', 'published_snapshot'])), $this->access->filter($request->user(), $ability.'.read', $records));
    }

    private function data(Request $request, string $section): array
    {
        $map = ['dashboard' => 'tasks', 'leads' => 'leads', 'contacts' => 'contacts', 'matters' => 'matters', 'proceedings' => 'proceedings', 'tasks' => 'tasks', 'documents' => 'documents', 'chat' => 'conversations', 'invoices' => 'invoices', 'payroll' => 'payroll_runs', 'pages' => 'pages', 'themes' => 'themes', 'ai' => 'ai_runs', 'notifications' => 'notifications', 'settings' => 'settings', 'team' => 'users', 'audit' => 'audit'];
        $map['website'] = 'pages';
        abort_unless(isset($map[$section]), 404);
        if ($section === 'website') {
            $this->access->authorize($request->user(), 'pages.read');
        }
        $records = in_array($section, ['settings', 'website'], true) ? [] : $this->records($request, $map[$section]);
        if ($section === 'themes') {
            $this->access->authorize($request->user(), 'themes.read');
            $records = app(Themes::class)->all();
        }
        $stats = $section === 'dashboard' ? $this->stats($request, $records) : [];
        $settings = ['business' => array_intersect_key($this->settings->get('business'), array_flip(['legal_name', 'trading_name', 'currency', 'logo']))];

        return ['user' => $request->user()->record(), 'section' => $section, 'records' => $records, 'data' => $records, 'stats' => $stats, 'settings' => $settings, 'notifications' => $this->records($request, 'notifications'), 'csrf_token' => csrf_token()];
    }

    /** Only the dashboard renders these, so other sections skip the extra full listings. */
    private function stats(Request $request, array $tasks): array
    {
        $matters = $this->records($request, 'matters');
        $leads = $this->records($request, 'leads');
        $invoices = $this->records($request, 'invoices');

        return ['active_matters' => count(array_filter($matters, fn ($r) => ! in_array($r['status'] ?? 'active', ['closed', 'archived']))), 'open_leads' => count($leads), 'pending_tasks' => count(array_filter($tasks, fn ($r) => ! in_array($r['status'] ?? '', ['done', 'cancelled', 'completed'], true))), 'overdue_tasks' => count(array_filter($tasks, fn ($r) => ($r['due_at'] ?? '9999') < now()->toISOString() && ! in_array($r['status'] ?? '', ['done', 'cancelled', 'completed'], true))), 'tasks' => $tasks, 'matters' => $matters, 'recent_matters' => array_slice($matters, 0, 5), 'leads' => $leads, 'invoices' => $invoices];
    }

    public function page(Request $request, string $section = 'dashboard'): mixed
    {
        return Inertia::render('Workspace', $this->data($request, $section));
    }

    public function api(Request $request, string $section): mixed
    {
        return response()->json($this->data($request, $section));
    }
}
