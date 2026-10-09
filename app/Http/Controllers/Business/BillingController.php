<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers\Business;

use App\Domain\Finance\BillingWorkService;
use App\Domain\Finance\RecurringInvoiceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class BillingController extends Controller
{
    public function __construct(private BillingWorkService $work, private RecurringInvoiceService $recurring) {}

    public function workIndex(Request $r, string $kind)
    {
        return response()->json(['data' => $this->work->list($r->user(), $this->collection($kind), $r->query())]);
    }

    public function workShow(Request $r, string $kind, string $id)
    {
        return response()->json(['data' => $this->work->find($r->user(), $this->collection($kind), $id)]);
    }

    public function workCreate(Request $r, string $kind)
    {
        return response()->json(['data' => $this->work->save($r->user(), $this->collection($kind), $r->all())], 201);
    }

    public function workUpdate(Request $r, string $kind, string $id)
    {
        return response()->json(['data' => $this->work->save($r->user(), $this->collection($kind), $r->all(), $id)]);
    }

    public function workTransition(Request $r, string $kind, string $id, string $action)
    {
        return response()->json(['data' => $this->work->transition($r->user(), $this->collection($kind), $id, $action, $r->all())]);
    }

    public function workArchive(Request $r, string $kind, string $id)
    {
        $data = $r->validate(['version' => 'required|integer|min:1']);

        return response()->json(['data' => $this->work->transition($r->user(), $this->collection($kind), $id, 'archive', $data)]);
    }

    public function draftInvoice(Request $r)
    {
        return response()->json(['data' => $this->work->draftInvoice($r->user(), $r->all())], 201);
    }

    public function recurringIndex(Request $r)
    {
        return response()->json(['data' => $this->recurring->list($r->user())]);
    }

    public function recurringShow(Request $r, string $id)
    {
        return response()->json(['data' => $this->recurring->find($r->user(), $id)]);
    }

    public function recurringCreate(Request $r)
    {
        return response()->json(['data' => $this->recurring->save($r->user(), $r->all())], 201);
    }

    public function recurringUpdate(Request $r, string $id)
    {
        return response()->json(['data' => $this->recurring->save($r->user(), $r->all(), $id)]);
    }

    public function recurringApprove(Request $r, string $id)
    {
        return response()->json(['data' => $this->recurring->approve($r->user(), $id)]);
    }

    public function recurringPause(Request $r, string $id)
    {
        return response()->json(['data' => $this->recurring->pause($r->user(), $id)]);
    }

    private function collection(string $kind): string
    {
        return match ($kind) {
            'time-entries' => 'time_entries', 'expenses' => 'expenses', default => abort(404)
        };
    }
}
