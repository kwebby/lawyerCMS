<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers\Business;

use App\Domain\Operations\OperationsService;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireFreshAuthentication;
use Illuminate\Http\Request;

final class OperationsController extends Controller
{
    public function __construct(private OperationsService $operations) {}

    public function index(Request $request, string $collection)
    {
        return response()->json(['data' => $this->operations->list($request->user(), $collection, $request->query())]);
    }

    public function show(Request $request, string $collection, string $id)
    {
        return response()->json(['data' => $this->operations->find($request->user(), $collection, $id)]);
    }

    public function store(Request $request, string $collection)
    {
        return $this->withSharingConfirmation($request, fn () => response()->json(['data' => $this->operations->save($request->user(), $collection, $request->all())], 201));
    }

    public function update(Request $request, string $collection, string $id)
    {
        return $this->withSharingConfirmation($request, fn () => response()->json(['data' => $this->operations->save($request->user(), $collection, $request->all(), $id)]));
    }

    public function destroy(Request $request, string $collection, string $id)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->operations->delete($request->user(), $collection, $id, $data['version']);

        return response()->noContent();
    }

    private function withSharingConfirmation(Request $request, \Closure $next)
    {
        if (array_intersect(array_keys($request->all()), ['client_ids', 'team_ids', 'denied_user_ids', 'owner_id'])) {
            return app(RequireFreshAuthentication::class)->handle($request, $next);
        }

        return $next();
    }

    public function conflictMatches(Request $request, string $id)
    {
        return response()->json(['data' => $this->operations->conflictMatches($request->user(), $id)]);
    }

    public function conflictReview(Request $request, string $id)
    {
        return response()->json(['data' => $this->operations->conflictReview($request->user(), $id, $request->all())]);
    }

    public function engagement(Request $request, string $id)
    {
        return response()->json(['data' => $this->operations->engagement($request->user(), $id, $request->all())]);
    }

    public function convert(Request $request, string $id)
    {
        return response()->json(['data' => $this->operations->convert($request->user(), $id, $request->all())], 201);
    }
}
