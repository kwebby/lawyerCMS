<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers;

use App\Auth\MfaLockout;
use App\Support\RecoveryCodes;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class RecoveryController extends Controller
{
    public function show(Request $request): mixed
    {
        abort_unless($request->session()->get('auth.mfa'), 403);

        return Inertia::render('Auth/Recovery', ['codes' => $request->session()->get('recovery_codes', [])]);
    }

    public function generate(Request $request, RecoveryCodes $codes): mixed
    {
        abort_unless($request->session()->get('auth.mfa'), 403);

        return redirect('/mfa/recovery')->with('recovery_codes', $codes->generate($request->user()->id));
    }

    public function recover(Request $request, RecoveryCodes $codes, MfaLockout $lockout): mixed
    {
        if ($request->isMethod('get')) {
            return Inertia::render('Auth/Recover');
        }
        $data = $request->validate(['code' => 'required|string|regex:/^[A-Fa-f0-9]{8}(?:-[A-Fa-f0-9]{8}){3}$/D']);
        // Recovery codes share the authenticator's per-account failure limit.
        $epoch = $lockout->attempt($request->user()->id, fn () => $codes->consume($request->user()->id, $data['code']));
        $request->session()->regenerate();
        $request->session()->put(['auth.epoch' => $epoch, 'auth.mfa' => false]);

        return redirect('/mfa');
    }
}
