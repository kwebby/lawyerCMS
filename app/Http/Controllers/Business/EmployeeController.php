<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers\Business;

use App\Domain\Finance\EmployeeInputs;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class EmployeeController extends Controller
{
    public function index(Request $request, EmployeeInputs $inputs, string $kind): mixed
    {
        return response()->json(['data' => $inputs->list($request->user(), $kind)]);
    }

    public function store(Request $request, EmployeeInputs $inputs, string $kind): mixed
    {
        return response()->json(['data' => $inputs->save($request->user(), $kind, $request->all())], 201);
    }

    public function decide(Request $request, EmployeeInputs $inputs, string $kind, string $id): mixed
    {
        return response()->json(['data' => $inputs->decide($request->user(), $kind, $id, $request->all())]);
    }

    public function generate(Request $request, EmployeeInputs $inputs): mixed
    {
        return response()->json(['data' => $inputs->generate($request->user(), $request->all())], 201);
    }
}
