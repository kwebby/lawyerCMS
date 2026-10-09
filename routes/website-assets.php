<?php

// Author: ramanpal singh | URL: https://kwebby.com

use App\Http\Controllers\Publishing\WebsiteAssetController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::middleware('auth')->prefix('api/v1/website')->group(function (): void {
    Route::get('fonts', [WebsiteAssetController::class, 'fonts']);
    Route::get('fonts/settings', [WebsiteAssetController::class, 'fontSettings']);
    Route::patch('fonts/settings', [WebsiteAssetController::class, 'fontSettings'])->middleware('fresh');
    Route::get('fonts/catalog', [WebsiteAssetController::class, 'fontCatalog'])->middleware('throttle:20,1');
    Route::post('fonts/install', [WebsiteAssetController::class, 'installFont'])->middleware(['fresh', 'throttle:5,1']);
    Route::get('media', [WebsiteAssetController::class, 'index']);
    Route::post('media', [WebsiteAssetController::class, 'store'])->middleware('throttle:20,1');
    Route::patch('media/{id}', [WebsiteAssetController::class, 'update']);
    Route::post('media/{id}/retry', [WebsiteAssetController::class, 'retry'])->middleware('throttle:5,1');
    Route::delete('media/{id}', [WebsiteAssetController::class, 'destroy'])->middleware('fresh');
    Route::get('media/{id}/preview', [WebsiteAssetController::class, 'preview']);
});
Route::get('website/media/{id}', [WebsiteAssetController::class, 'publicImage'])->whereUuid('id');

Route::get('website/fonts/{id}/{file}', [WebsiteAssetController::class, 'fontAsset'])->where('id', 'google-[a-z0-9-]+')->where('file', '(?:face-[0-9]+-[a-f0-9]{16}\\.woff2|OFL\\.txt|LICENSE\\.txt)')->withoutMiddleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);
