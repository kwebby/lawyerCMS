<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers\Publishing;

use App\Domain\Publishing\Website;
use App\Http\Controllers\Controller;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebsiteController extends Controller
{
    public function __construct(private Website $website, private Access $access) {}

    public function show(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), 'pages.read');

        return $this->response($request, $this->website->state());
    }

    public function save(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), 'pages.write');
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1'], 'document' => ['required', 'array']]);

        return $this->response($request, $this->website->save($data['document'], $data['expected_version'], $request->user()->id));
    }

    public function importTheme(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), 'pages.write');
        $this->access->authorize($request->user(), 'themes.read');
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1'], 'theme_id' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/D']]);

        return $this->response($request, $this->website->importTheme($data['theme_id'], $data['expected_version'], $request->user()->id));
    }

    public function transition(Request $request, string $action): JsonResponse
    {
        $this->access->authorize($request->user(), 'pages.'.($action === 'rollback' ? 'publish' : $action));
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1'], 'revision_id' => ['nullable', 'string', 'max:100']]);

        return $this->response($request, $this->website->transition($action, $data['expected_version'], $request->user()->id, $data['revision_id'] ?? null));
    }

    public function pagePack(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), 'pages.write');
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        $result = $this->website->pagePack($request->except('expected_version'), $data['expected_version'], $request->user()->id);
        $result['data']['capabilities'] = $this->capabilities($request);

        return response()->json($result);
    }

    private function response(Request $request, array $state): JsonResponse
    {
        $state['capabilities'] = $this->capabilities($request);

        return response()->json(['data' => $state])->header('Cache-Control', 'private, no-store');
    }

    private function capabilities(Request $request): array
    {
        $result = [];
        foreach (['read', 'write', 'review', 'approve', 'publish'] as $action) {
            $result[$action] = $this->access->can($request->user(), 'pages.'.$action);
        }

        return $result;
    }
}
