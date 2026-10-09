<?php

// Author: ramanpal singh | URL: https://kwebby.com
use App\Http\Controllers\AnalyzerController;
use Illuminate\Support\Facades\Route;

Route::get('/tools/verify/{token}', [AnalyzerController::class, 'verify'])->middleware('throttle:10,1');
Route::post('/tools/upload', [AnalyzerController::class, 'upload'])->middleware('throttle:3,1');
Route::get('/tools/results/{id}', [AnalyzerController::class, 'result'])->middleware('throttle:30,1');
Route::post('/tools/results/{id}/consult', [AnalyzerController::class, 'consult'])->middleware('throttle:3,1');
Route::get('/tools/{tool}', [AnalyzerController::class, 'page']);
Route::post('/tools/{tool}', [AnalyzerController::class, 'request'])->middleware('throttle:3,1');
