<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Domain\Finance\Currency;
use App\Support\Access;
use App\Support\Audit;
use App\Support\EndpointPolicy;
use App\Support\Health;
use App\Support\Outbox;
use App\Support\PrivateFiles;
use App\Support\Settings;
use Carbon\Carbon;
use Illuminate\Http\Request;

final class SettingsController extends Controller
{
    public function __construct(private Settings $settings, private Access $access, private Audit $audit, private RecordStore $store) {}

    public function index(Request $request, Health $health): mixed
    {
        $this->access->authorize($request->user(), 'settings.read');
        $data = [];
        foreach (['business', 'publishing', 'mail', 'ai', 'security'] as $section) {
            $data[$section] = $this->settings->get($section);
        }
        $data['health'] = $health->report();

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, EndpointPolicy $policy): mixed
    {
        $this->access->authorize($request->user(), 'settings.write');
        $input = $request->validate(['section' => 'required|in:business,mail,ai,security', 'data' => 'required|array']);
        $rules = match ($input['section']) {
            'business' => ['legal_name' => 'required|string|max:160', 'trading_name' => 'nullable|string|max:160', 'address' => 'nullable|string|max:1000', 'email' => 'nullable|email', 'phone' => 'nullable|string|max:60', 'website' => 'nullable|url:https', 'tax_id' => 'nullable|string|max:120', 'registration_id' => 'nullable|string|max:120', 'currency' => ['required', Currency::rule()], 'invoice_prefix' => 'nullable|regex:/^[A-Z0-9-]{1,12}$/', 'payment_instructions' => 'nullable|string|max:3000', 'terms' => 'nullable|string|max:3000', 'invoice_template' => 'nullable|array', 'logo_file_id' => 'nullable|string|max:100', 'signature' => 'nullable|string|max:300', 'offices' => 'nullable|array|max:50'],
            'mail' => ['host' => 'required|string|max:254', 'port' => 'required|integer|in:465,587', 'encryption' => 'required|in:tls,ssl', 'username' => 'required|string|max:254', 'password' => 'nullable|string|max:1000', 'from_address' => 'required|email', 'from_name' => 'required|string|max:160', 'reply_to' => 'nullable|email'],
            'ai' => ['enabled' => 'required|boolean', 'provider' => 'required|in:openai,anthropic,gemini,ollama,openai-compatible', 'model' => 'required|string|max:100', 'api_key' => 'nullable|string|max:1000', 'endpoint' => 'nullable|url', 'daily_limit' => 'integer|min:1|max:1000', 'public_tools_approved' => 'boolean', 'jurisdiction' => 'nullable|string|max:100'],
            'security' => ['retention_days' => 'integer|min:30|max:36500', 'notification_digest' => 'in:off,daily,weekly', 'allow_self_approval' => 'sometimes|boolean'],
            default => throw new \InvalidArgumentException('Unknown settings section.'),
        };
        $data = validator($input['data'], $rules)->validate();
        if ($input['section'] === 'security' && array_key_exists('allow_self_approval', $data)) {
            $data['allow_self_approval'] = (bool) $data['allow_self_approval'];
            if ($data['allow_self_approval'] !== (($this->settings->get('security')['allow_self_approval'] ?? false) === true)) {
                abort_unless(in_array('owner', $request->user()->roles ?? [], true), 403, 'Only an owner can change whether people may approve their own work.');
            }
        }
        if ($input['section'] === 'mail') {
            $policy->smtp($data['host'], $data['port']);
        }
        if ($input['section'] === 'ai' && isset($data['endpoint']) && ! empty($data['endpoint'])) {
            $policy->options($data['endpoint']);
        }
        if ($input['section'] === 'business' && array_key_exists('logo_file_id', $data)) {
            $data['logo_data'] = null;
            if (! empty($data['logo_file_id'])) {
                $logo = $this->store->get('documents', $data['logo_file_id']);
                abort_unless($logo && ($logo['status'] ?? '') === 'clean' && empty($logo['matter_id']) && in_array($logo['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true), 422, 'Choose a cleared standalone image for the firm logo.');
                $this->access->authorize($request->user(), 'documents.read', $logo);
                $bytes = app(PrivateFiles::class)->read($logo['path']);
                abort_unless(strlen($bytes) <= 2 * 1024 * 1024 && hash_equals($logo['sha256'], hash('sha256', $bytes)), 422, 'Logo must be an unchanged image under 2 MB.');
                $size = getimagesizefromstring($bytes);
                abort_unless($size && $size[0] <= 2048 && $size[1] <= 2048, 422, 'Logo dimensions must be at most 2048 pixels.');
                $image = imagecreatefromstring($bytes);
                abort_unless($image !== false, 422, 'Unable to read logo image.');
                $ratio = min(1, 360 / $size[0], 120 / $size[1]);
                $scaled = imagecreatetruecolor(max(1, (int) round($size[0] * $ratio)), max(1, (int) round($size[1] * $ratio)));
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
                imagecopyresampled($scaled, $image, 0, 0, 0, 0, imagesx($scaled), imagesy($scaled), $size[0], $size[1]);
                ob_start();
                imagepng($scaled, null, 9);
                $png = ob_get_clean();
                imagedestroy($image);
                imagedestroy($scaled);
                abort_unless(strlen($png) <= 65536, 422, 'Use a simpler logo image.');
                $data['logo_data'] = base64_encode($png);
            }
        }
        $saved = $this->store->transaction(function () use ($input, $data, $request) {
            $saved = $this->settings->save($input['section'], $data);
            $this->audit->log($request->user()->id, 'settings.updated', 'settings', $input['section']);

            return $saved;
        });

        return response()->json(['data' => $saved]);
    }

    public function testMail(Request $request, Outbox $outbox): mixed
    {
        $this->access->authorize($request->user(), 'settings.write');
        $job = $outbox->enqueue('email', ['to' => $request->user()->email, 'subject' => 'LawyerCMS email configuration test', 'body' => 'This message confirms your outbound email configuration.']);

        return response()->json(['data' => ['job_id' => $job['id'], 'status' => 'queued']], 202);
    }

    public function people(Request $request): mixed
    {
        abort_if(array_intersect($request->user()->roles ?? [], ['client', 'prospect']) !== [], 403);
        $matter = null;
        if ($matterId = $request->query('matter_id')) {
            $matter = $this->store->get('matters', (string) $matterId);
            abort_unless($matter !== null, 404);
            $this->access->authorize($request->user(), 'matters.read', $matter);
        }
        $records = [];
        foreach ($this->store->each('users') as $record) {
            if (($record['status'] ?? 'active') !== 'active') {
                continue;
            }
            $client = (bool) array_intersect($record['roles'], ['client', 'prospect']);
            if ($client && (! $matter || ! in_array($record['id'], $matter['client_ids'] ?? [], true))) {
                continue;
            }
            if ($matter && ! $this->access->can(new CrmUser($record), 'matters.read', $matter)) {
                continue;
            }
            $records[] = array_intersect_key($record, array_flip(['id', 'name', 'email', 'roles']));
        }

        return response()->json(['data' => $records]);
    }

    public function users(Request $request): mixed
    {
        $this->access->authorize($request->user(), 'users.read');

        return response()->json(['data' => array_map(fn ($u) => (new CrmUser($u))->record(), iterator_to_array($this->store->each('users'), false))]);
    }

    public function updateUser(Request $request, string $id): mixed
    {
        $this->access->authorize($request->user(), 'users.write');
        $data = $request->validate(['roles' => 'array|min:1', 'roles.*' => 'in:owner,admin,partner,lawyer,paralegal,intake,accounts,hr,content,client,collaborator', 'status' => 'in:active,disabled', 'access_expires_at' => 'nullable|date|after:now|before_or_equal:'.now()->addYear()->toDateString()]);
        abort_if($id === $request->user()->id, 422, 'Change another administrator account to avoid locking yourself out.');
        $record = $this->store->transaction(function () use ($id, $data, $request) {
            $record = $this->store->get('users', $id);
            abort_unless($record !== null, 404);
            $before = $record['roles'] ?? [];
            $roles = $data['roles'] ?? $before;
            $changed = array_merge(array_diff($roles, $before), array_diff($before, $roles));
            if (array_intersect($changed, ['owner', 'admin']) || in_array('owner', $before, true)) {
                abort_unless(in_array('owner', $request->user()->roles ?? [], true), 403, 'Only an owner can grant or remove owner or administrator access, or change an owner account.');
            }
            // An unverified address may belong to someone who registered another person's email; access waits for proof of the mailbox.
            abort_if(empty($record['email_verified_at']) && array_diff(array_diff($roles, $before), ['prospect']), 422, 'This account has not verified its email address. Invite the person instead.');
            // Collaborator access always expires; an explicit null must not clear it (?? would treat null as absent and the merge would write it).
            if (in_array('collaborator', $roles, true) && empty($data['access_expires_at'] ?? null)) {
                $data['access_expires_at'] = ($record['access_expires_at'] ?? null) ?: now()->addDays(30)->toISOString();
            }
            if (! empty($data['access_expires_at'])) {
                $data['access_expires_at'] = Carbon::parse($data['access_expires_at'])->toISOString();
            }
            $demoted = ! in_array('owner', $roles, true) || ($data['status'] ?? $record['status'] ?? 'active') !== 'active' || ! empty($data['access_expires_at']);
            if (in_array('owner', $before, true) && ($record['status'] ?? 'active') === 'active' && $demoted) {
                $remaining = 0;
                foreach ($this->store->query('users', [], 10000) as $user) {
                    $remaining += (int) ($user['id'] !== $id && in_array('owner', $user['roles'] ?? [], true) && ($user['status'] ?? 'active') === 'active' && empty($user['access_expires_at']));
                }
                abort_unless($remaining > 0, 422, 'Keep at least one active owner without an access expiry.');
            }
            $record = $this->store->put('users', $id, array_merge($record, $data, ['session_epoch' => ($record['session_epoch'] ?? 0) + 1]), $record['version']);
            $this->audit->log($request->user()->id, 'identity.permissions_changed', 'users', $id);

            return (new CrmUser($record))->record();
        });

        return response()->json(['data' => $record]);
    }

    public function roles(Request $request): mixed
    {
        $this->access->authorize($request->user(), 'users.write');
        if ($request->isMethod('get')) {
            return response()->json(['data' => config('permissions.roles'), 'overrides' => $this->store->query('roles')]);
        }
        $data = $request->validate(['name' => 'required|in:partner,lawyer,paralegal,intake,accounts,hr,content,collaborator', 'permissions' => 'required|array']);
        foreach ($data['permissions'] as $ability => $scope) {
            abort_unless(preg_match('/^[a-z_]+\.(read|write|review|approve|publish|activate|download|\*)$/D', $ability) && in_array($scope, ['assigned', 'team', 'firm']), 422, 'Invalid permission.');
        }
        $record = $this->store->put('roles', $data['name'], ['permissions' => $data['permissions']]);
        $this->audit->log($request->user()->id, 'roles.updated', 'roles', $data['name']);

        return response()->json(['data' => $record]);
    }
}
