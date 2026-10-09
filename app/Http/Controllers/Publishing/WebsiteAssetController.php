<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers\Publishing;

use App\Domain\Publishing\WebsiteFontCatalog;
use App\Domain\Publishing\WebsiteFonts;
use App\Domain\Publishing\WebsiteMedia;
use App\Http\Controllers\Controller;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WebsiteAssetController extends Controller
{
    public function __construct(private WebsiteMedia $media, private WebsiteFonts $fonts, private Access $access, private WebsiteFontCatalog $catalog) {}

    public function fonts(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), 'pages.read');
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $this->fonts->catalog($data['q'] ?? null), 'meta' => ['source' => 'installed', 'self_hosted' => true, 'families' => count($this->fonts->catalog())]]);
    }

    public function fontSettings(Request $request): JsonResponse
    {
        $this->fontAdministrator($request);
        if ($request->isMethod('patch')) {
            $data = $request->validate(['api_key' => ['required', 'string', 'min:10', 'max:200', 'regex:/^[A-Za-z0-9_-]+$/D']]);

            return response()->json(['data' => $this->catalog->configure($data['api_key'], $request->user()->id)]);
        }

        return response()->json(['data' => $this->catalog->configuration()]);
    }

    public function fontCatalog(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), 'pages.read');
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $this->catalog->catalog($data['q'] ?? null), 'meta' => ['configured' => true, 'source' => 'google-fonts']]);
    }

    public function installFont(Request $request): JsonResponse
    {
        $this->fontAdministrator($request);
        $data = $request->validate(['family' => ['required', 'string', 'max:100']]);

        return response()->json(['data' => $this->catalog->install($data['family'], $request->user()->id)], 201);
    }

    public function fontAsset(string $id, string $file): Response
    {
        $asset = $this->catalog->asset($id, $file);

        return response($asset['contents'])->withHeaders(['Content-Type' => $asset['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=31536000, immutable', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function fontAdministrator(Request $request): void
    {
        $this->access->authorize($request->user(), 'settings.write');
        abort_unless(count(array_intersect($request->user()->roles ?? [], ['owner', 'admin'])) > 0, 403);
    }

    public function index(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), 'pages.read');

        return response()->json(['data' => $this->media->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), 'pages.write');
        $data = $request->validate(['image' => ['required', 'file', 'max:10240'], ...$this->metadataRules()]);

        return response()->json(['data' => $this->media->upload($request->file('image'), $data, $request->user()->id)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), 'pages.write');
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1'], ...$this->metadataRules()]);

        return response()->json(['data' => $this->media->update($id, $data, $request->user()->id)]);
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), 'pages.write');

        return response()->json(['data' => $this->media->retry($id, $request->user()->id)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), 'pages.write');
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        $this->media->delete($id, $data['expected_version'], $request->user()->id);

        return response()->json(['deleted' => true]);
    }

    public function preview(Request $request, string $id): Response
    {
        $this->access->authorize($request->user(), 'pages.read');

        return $this->image($request, $id, false);
    }

    public function publicImage(Request $request, string $id): Response
    {
        return $this->image($request, $id, true);
    }

    private function image(Request $request, string $id, bool $public): Response
    {
        $data = $request->validate(['width' => ['nullable', 'integer', 'in:768,1280']]);
        $asset = $this->media->contents($id, $public, isset($data['width']) ? (int) $data['width'] : null);

        return response($asset['contents'])->withHeaders(['Content-Type' => $asset['mime'], 'Content-Length' => (string) strlen($asset['contents']), 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store, max-age=0', 'X-Robots-Tag' => 'noindex, nofollow', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function metadataRules(): array
    {
        return ['alt' => ['sometimes', 'nullable', 'string', 'max:500'], 'caption' => ['sometimes', 'nullable', 'string', 'max:1000'], 'rights' => ['sometimes', 'nullable', 'string', 'max:1000'], 'focal_x' => ['sometimes', 'integer', 'between:0,100'], 'focal_y' => ['sometimes', 'integer', 'between:0,100']];
    }
}
