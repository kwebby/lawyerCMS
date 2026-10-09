<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers\Publishing;

use App\Contracts\RecordStore;
use App\Domain\Publishing\Themes;
use App\Http\Controllers\Controller;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ThemeController extends Controller
{
    public function __construct(private Themes $themes, private Access $access, private RecordStore $store) {}

    public function index(Request $request)
    {
        $this->access->authorize($request->user(), 'themes.read');

        return response()->json(['data' => $this->themes->all(), 'website_managed' => ! empty($this->store->get('settings', 'website-state')['published'])]);
    }

    public function uploads(Request $request)
    {
        $this->access->authorize($request->user(), 'themes.write');

        return response()->json(['data' => $this->themes->uploads()]);
    }

    public function retry(Request $request, string $id)
    {
        $this->access->authorize($request->user(), 'themes.write');

        return response()->json(['data' => $this->themes->retry($id, $request->user()->id)]);
    }

    public function upload(Request $request)
    {
        $this->access->authorize($request->user(), 'themes.write');
        $request->validate(['theme' => ['required', 'file', 'max:25600']]);

        return response()->json(['data' => $this->themes->import($request->file('theme')->getRealPath(), $request->user()->id)], 201);
    }

    public function design(Request $request)
    {
        $this->access->authorize($request->user(), 'themes.write');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'theme_version' => ['sometimes', 'string', 'regex:/^\d+\.\d+\.\d+$/'], 'tokens' => ['required', 'array'], 'templates' => ['required', 'array'], 'navigation' => ['sometimes', 'array'], 'author' => ['nullable', 'string', 'max:120'], 'license' => ['nullable', 'string', 'max:120']]);

        return response()->json(['data' => $this->themes->design($data, $request->user()->id)], 201);
    }

    public function activate(Request $request, string $id)
    {
        $this->access->authorize($request->user(), 'themes.activate');

        return response()->json(['data' => $this->store->transaction(function () use ($request, $id) {
            $this->assertLegacyActivation();

            return $this->themes->activate($id, $request->user()->id);
        })]);
    }

    public function rollback(Request $request)
    {
        $this->access->authorize($request->user(), 'themes.activate');

        return response()->json(['data' => $this->store->transaction(function () use ($request) {
            $this->assertLegacyActivation();

            return $this->themes->rollback($request->user()->id);
        })]);
    }

    private function assertLegacyActivation(): void
    {
        if (! empty($this->store->get('settings', 'website-state')['published'])) {
            throw ValidationException::withMessages(['theme' => 'Apply a theme preset in Website settings, then publish.']);
        }
    }

    public function asset(string $id, string $path)
    {
        $asset = $this->themes->asset($id, $path);

        return response($asset['contents'])->withHeaders(['Content-Type' => $asset['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=31536000, immutable', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'ETag' => '"'.$asset['sha256'].'"']);
    }
}
