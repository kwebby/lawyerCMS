<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Outbox;
use App\Support\RecoveryCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use OTPHP\TOTP;

final class AuthController extends Controller
{
    public function __construct(private RecordStore $store, private Audit $audit, private Outbox $outbox) {}

    private function identity(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    public function form(Request $request, string $screen = 'Login'): mixed
    {
        if ($request->user()) {
            return redirect('/app');
        }

        return Inertia::render('Auth/'.$screen, ['installed' => (bool) $this->store->get('settings', 'installation')]);
    }

    public function setup(Request $request): mixed
    {
        abort_if($this->store->get('settings', 'installation') !== null, 404);
        if ($request->isMethod('get')) {
            return Inertia::render('Auth/Setup');
        }
        $data = $request->validate(['bootstrap_token' => 'required|string', 'name' => 'required|string|max:120', 'email' => 'required|email|max:254', 'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()], 'firm_name' => 'required|string|max:160']);
        $token = config('crm.bootstrap_token');
        abort_unless(is_string($token) && strlen($token) >= 32 && hash_equals($token, $data['bootstrap_token']), 403, 'Invalid installation token.');
        $user = $this->store->transaction(function () use ($data) {
            abort_if($this->store->get('settings', 'installation') !== null, 409, 'Installation already completed.');
            $user = $this->createUser($data, ['owner'], true);
            $this->store->create('settings', ['completed_at' => now()->toISOString(), 'owner_id' => $user['id']], 'installation');
            $this->store->create('settings', ['legal_name' => $data['firm_name'], 'currency' => 'USD', 'invoice_prefix' => 'INV', 'terms' => 'Payment due within 30 days.'], 'business');
            $this->audit->log($user['id'], 'installation.completed', 'settings', 'installation');

            return $user;
        });
        $this->signIn($request, $user);

        return redirect(config('crm.require_mfa') ? '/mfa' : '/app');
    }

    public function login(Request $request): mixed
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        // Keep credential verification and the session epoch in one atomic snapshot.
        // A concurrent reset must revoke this login rather than giving old credentials a new epoch.
        $user = $this->store->transaction(function () use ($data) {
            $index = $this->store->get('identity_emails', $this->identity($data['email']));
            $user = $index ? $this->store->get('users', $index['user_id']) : null;
            if (! $user || ($user['status'] ?? 'active') !== 'active' || (! empty($user['access_expires_at']) && Carbon::parse($user['access_expires_at'])->isPast()) || ! Hash::check($data['password'], $user['password'])) {
                return null;
            }
            if (Hash::needsRehash($user['password'])) {
                $user = $this->store->put('users', $user['id'], array_merge($user, ['password' => Hash::make($data['password'])]), $user['version']);
            }

            return $user;
        });
        if (! $user) {
            return back()->withErrors(['email' => 'The email or password is incorrect.']);
        }
        $this->signIn($request, $user);
        $this->audit->log(Auth::id(), 'identity.login', 'users', Auth::id());

        return redirect()->intended(config('crm.require_mfa') && ! array_intersect(['client', 'prospect'], Auth::user()->roles) ? '/mfa' : '/app');
    }

    private function signIn(Request $request, array $user): void
    {
        Auth::login(new CrmUser($user));
        $request->session()->regenerate();
        $request->session()->put(['auth.epoch' => $user['session_epoch'] ?? 0, 'auth.confirmed_at' => time(), 'auth.mfa' => false]);
    }

    private function existingAccount(string $email): ?array
    {
        $index = $this->store->get('identity_emails', $this->identity($email));

        return $index ? $this->store->get('users', $index['user_id']) : null;
    }

    /** A self-registered prospect that never proved its address may have been created by someone else, so an invitation can take it over. */
    private function claimable(array $user): bool
    {
        return empty($user['email_verified_at']) && array_values($user['roles'] ?? []) === ['prospect'];
    }

    private function createUser(array $data, array $roles, bool $verified = false): array
    {
        $email = strtolower(trim($data['email']));
        $key = $this->identity($email);
        if ($this->store->get('identity_emails', $key)) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists.']);
        }
        $user = $this->store->create('users', ['name' => $data['name'], 'email' => $email, 'password' => Hash::make($data['password']), 'roles' => $roles, 'status' => 'active', 'session_epoch' => 0, 'access_expires_at' => $data['access_expires_at'] ?? null, 'email_verified_at' => $verified ? now()->toISOString() : null]);
        $this->store->create('identity_emails', ['user_id' => $user['id']], $key);

        return $user;
    }

    public function register(Request $request): mixed
    {
        abort_unless($this->store->get('settings', 'installation') !== null, 404);
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:254', 'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()]]);
        $user = $this->store->transaction(function () use ($data) {
            $user = $this->createUser($data, ['prospect']);
            $this->issueToken($user, 'verify', 60);

            return $user;
        });
        $this->signIn($request, $user);

        return redirect('/portal');
    }

    private function issueToken(array $user, string $kind, int $minutes): void
    {
        $token = Str::random(64);
        $this->store->create('identity_tokens', ['user_id' => $user['id'], 'kind' => $kind, 'issued_epoch' => $user['session_epoch'] ?? 0, 'expires_at' => now()->addMinutes($minutes)->toISOString(), 'used_at' => null], hash('sha256', $token));
        $url = url($kind === 'reset' ? '/password/reset/'.$token : '/verify/'.$token);
        $this->outbox->enqueue('email', ['to' => $user['email'], 'subject' => $kind === 'reset' ? 'Reset your LawyerCMS password' : 'Verify your email', 'body' => 'Use this private link before it expires: '.$url]);
    }

    public function verify(Request $request, string $token): mixed
    {
        // Verification must come from the account holder's own session: a link someone else triggered for your address
        // must not verify their account (and so qualify it for firm access) just because you clicked it.
        $this->consumeToken($token, 'verify', function ($user) {
            return array_merge($user, ['email_verified_at' => now()->toISOString()]);
        }, $request->user()->id);

        return redirect('/portal')->with('status', 'Email verified.');
    }

    private function consumeToken(string $token, string $kind, callable $transform, ?string $userId = null): void
    {
        $this->store->transaction(function () use ($token, $kind, $transform, $userId) {
            $id = hash('sha256', $token);
            $record = $this->store->get('identity_tokens', $id);
            abort_unless($record && $record['kind'] === $kind && ! $record['used_at'] && $record['expires_at'] > now()->toISOString(), 422, 'This link has expired or has already been used.');
            abort_if($userId !== null && $record['user_id'] !== $userId, 403, 'Sign in to the account this link was sent for, then open the link again.');
            $user = $this->store->get('users', $record['user_id']);
            abort_unless($user !== null, 422);
            if ($kind === 'reset') {
                abort_unless(($record['issued_epoch'] ?? 0) === ($user['session_epoch'] ?? 0), 422, 'This reset link was invalidated by an account security change.');
            }
            $this->store->put('users', $user['id'], $transform($user), $user['version']);
            $this->store->put('identity_tokens', $id, array_merge($record, ['used_at' => now()->toISOString()]), $record['version']);
        });
    }

    public function forgot(Request $request): mixed
    {
        $data = $request->validate(['email' => 'required|email']);
        $index = $this->store->get('identity_emails', $this->identity($data['email']));
        if ($index) {
            $this->store->transaction(fn () => $this->issueToken($this->store->get('users', $index['user_id']), 'reset', 30));
        }

        return response()->json(['message' => 'If an account exists, a reset link will be sent.']);
    }

    public function reset(Request $request, string $token): mixed
    {
        if ($request->isMethod('get')) {
            return Inertia::render('Auth/Reset', ['token' => $token]);
        }
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()]]);
        $this->consumeToken($token, 'reset', fn ($user) => array_merge($user, ['password' => Hash::make($data['password']), 'session_epoch' => ($user['session_epoch'] ?? 0) + 1]));

        return redirect('/login');
    }

    public function confirm(Request $request): mixed
    {
        $data = $request->validate(['password' => 'required|string']);
        abort_unless(Hash::check($data['password'], $request->user()->getAuthPassword()), 422, 'Incorrect password.');
        $request->session()->put('auth.confirmed_at', time());

        return response()->json(['data' => ['confirmed' => true]]);
    }

    public function mfa(Request $request): mixed
    {
        $user = $this->store->get('users', $request->user()->id);
        $enrolling = empty($user['mfa_secret']);
        abort_if($enrolling && empty($user['email_verified_at']), 403, 'Verify your email address before setting up an authenticator.');
        if ($request->isMethod('get')) {
            if ($enrolling && empty($user['mfa_pending'])) {
                $user = $this->store->put('users', $user['id'], array_merge($user, ['mfa_pending' => Crypt::encryptString(TOTP::generate()->getSecret())]), $user['version']);
            }
            $secret = Crypt::decryptString($user[$enrolling ? 'mfa_pending' : 'mfa_secret']);
            $totp = TOTP::createFromSecret($secret);
            $totp->setIssuer('LawyerCMS');
            $totp->setLabel($user['email']);

            return Inertia::render('Auth/Mfa', ['enrollment_required' => $enrolling, 'secret' => $enrolling ? $secret : null, 'provisioning_uri' => $enrolling ? $totp->getProvisioningUri() : null]);
        }
        $data = $request->validate(['code' => 'required|digits:6']);
        $key = $enrolling ? 'mfa_pending' : 'mfa_secret';
        abort_unless(isset($user[$key]), 422);
        $totp = TOTP::createFromSecret(Crypt::decryptString($user[$key]));
        $step = (int) floor(time() / 30);
        $matchedStep = null;
        foreach ([$step - 1, $step, $step + 1] as $candidate) {
            if (hash_equals($totp->at($candidate * 30), $data['code'])) {
                $matchedStep = $candidate;
                break;
            }
        }
        abort_unless($matchedStep !== null, 422, 'Invalid authenticator code.');
        $this->store->transaction(function () use ($user, $enrolling, $matchedStep, $key) {
            $current = $this->store->get('users', $user['id']);
            abort_unless(isset($current[$key]) && hash_equals($current[$key], $user[$key]), 422, 'Authenticator enrollment changed. Reload and retry.');
            abort_if(($current['mfa_last_step'] ?? 0) >= $matchedStep, 422, 'This code has already been used.');
            if ($enrolling) {
                $current['mfa_secret'] = $current['mfa_pending'];
                unset($current['mfa_pending']);
            }
            $current['mfa_last_step'] = $matchedStep;
            $this->store->put('users', $user['id'], $current, $current['version']);
            $this->audit->log($user['id'], 'identity.mfa', 'users', $user['id']);
        });
        $request->session()->put('auth.mfa', true);
        if ($enrolling) {
            return redirect('/mfa/recovery')->with('recovery_codes', app(RecoveryCodes::class)->generate($user['id']));
        }

        return redirect('/app');
    }

    public function invite(Request $request, Access $access): mixed
    {
        $access->authorize($request->user(), 'users.write');
        $data = $request->validate(['email' => 'required|email', 'roles' => 'required|array|min:1', 'roles.*' => 'required|string|in:partner,lawyer,paralegal,intake,accounts,hr,content,collaborator,client', 'expires_days' => 'integer|min:1|max:14', 'access_expires_at' => 'nullable|date|after:now|before_or_equal:'.now()->addYear()->toDateString()]);
        if (in_array('collaborator', $data['roles'], true)) {
            $data['access_expires_at'] = isset($data['access_expires_at']) ? Carbon::parse($data['access_expires_at'])->toISOString() : now()->addDays(30)->toISOString();
        }
        $token = Str::random(64);
        $invitation = $this->store->transaction(function () use ($data, $request, $token) {
            $existing = $this->existingAccount($data['email']);
            abort_if($existing && ! $this->claimable($existing), 422, 'An account with this email already exists. Change its access in Team settings instead.');
            $record = $this->store->create('invitations', ['email' => strtolower($data['email']), 'roles' => $data['roles'], 'access_expires_at' => $data['access_expires_at'] ?? null, 'owner_id' => $request->user()->id, 'expires_at' => now()->addDays($data['expires_days'] ?? 3)->toISOString(), 'used_at' => null], hash('sha256', $token));
            $this->outbox->enqueue('email', ['to' => $data['email'], 'subject' => 'You are invited to LawyerCMS', 'body' => 'Accept your invitation: '.url('/invite/'.$token)]);

            return $record;
        });

        return response()->json(['data' => array_diff_key($invitation, ['id' => true])], 201);
    }

    public function accept(Request $request, string $token): mixed
    {
        if ($request->isMethod('get')) {
            return Inertia::render('Auth/Invite', ['token' => $token]);
        }
        $data = $request->validate(['name' => 'required|string|max:120', 'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()]]);
        $user = $this->store->transaction(function () use ($data, $token) {
            $id = hash('sha256', $token);
            $invite = $this->store->get('invitations', $id);
            abort_unless($invite && ! $invite['used_at'] && $invite['expires_at'] > now()->toISOString(), 422, 'Invitation is unavailable.');
            abort_if(! empty($invite['access_expires_at']) && Carbon::parse($invite['access_expires_at'])->isPast(), 422, 'Invitation access period has expired.');
            $existing = $this->existingAccount($invite['email']);
            if ($existing) {
                // Accepting proves control of the mailbox: replace whatever an unverified self-registration set up.
                abort_unless($this->claimable($existing), 422, 'An account with this email already exists. Sign in instead.');
                $user = array_merge($existing, ['name' => $data['name'], 'password' => Hash::make($data['password']), 'roles' => $invite['roles'], 'status' => 'active', 'access_expires_at' => $invite['access_expires_at'] ?? null, 'email_verified_at' => now()->toISOString(), 'session_epoch' => ($existing['session_epoch'] ?? 0) + 1]);
                unset($user['mfa_secret'], $user['mfa_pending'], $user['mfa_last_step'], $user['recovery_codes']);
                $user = $this->store->put('users', $existing['id'], $user, $existing['version']);
                $this->audit->log($user['id'], 'identity.invitation_claimed_account', 'users', $user['id']);
            } else {
                $user = $this->createUser(array_merge($data, ['email' => $invite['email'], 'access_expires_at' => $invite['access_expires_at'] ?? null]), $invite['roles'], true);
            }
            $this->store->put('invitations', $id, array_merge($invite, ['used_at' => now()->toISOString()]), $invite['version']);

            return $user;
        });
        $this->signIn($request, $user);

        return redirect('/app');
    }

    public function logout(Request $request): mixed
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
