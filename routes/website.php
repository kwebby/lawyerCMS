<?php

// Author: ramanpal singh | URL: https://kwebby.com

use App\Http\Controllers\Publishing\PublicController;
use App\Http\Controllers\Publishing\WebsiteController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('api/v1/website')->group(function () {
    Route::get('/', [WebsiteController::class, 'show']);
    Route::get('/preview', [PublicController::class, 'websitePreview']);
    Route::patch('/draft', [WebsiteController::class, 'save']);
    Route::post('/page-pack', [WebsiteController::class, 'pagePack']);
    Route::post('/theme', [WebsiteController::class, 'importTheme']);
    foreach (['review', 'approve'] as $action) {
        Route::post('/'.$action, [WebsiteController::class, 'transition'])->defaults('action', $action);
    }
    foreach (['publish', 'rollback'] as $action) {
        Route::post('/'.$action, [WebsiteController::class, 'transition'])->defaults('action', $action)->middleware('fresh');
    }
});
