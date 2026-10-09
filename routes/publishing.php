<?php

// Author: ramanpal singh | URL: https://kwebby.com

use App\Http\Controllers\Publishing\ContentController;
use App\Http\Controllers\Publishing\PublicController;
use App\Http\Controllers\Publishing\SeoController;
use App\Http\Controllers\Publishing\ThemeController;
use App\Http\Controllers\Publishing\WebsiteEnquiryController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::middleware('auth')->prefix('api/v1')->group(function () {
    foreach (['pages', 'documents'] as $collection) {
        Route::get($collection, [ContentController::class, 'index']);
        Route::post($collection, [ContentController::class, 'store']);
        Route::get($collection.'/{id}', [ContentController::class, 'show']);
        Route::patch($collection.'/{id}', [ContentController::class, 'update']);
        Route::delete($collection.'/{id}', [ContentController::class, 'destroy'])->middleware('fresh');
        Route::get($collection.'/{id}/revisions', [ContentController::class, 'revisions']);
        Route::get($collection.'/{id}/revisions/{revisionId}', [ContentController::class, 'revision']);
        Route::match(['get', 'post'], $collection.'/{id}/comments', [ContentController::class, 'comments']);
        Route::get($collection.'/{id}/export/{format}', [ContentController::class, 'export'])->whereIn('format', ['pdf', 'docx'])->middleware('fresh');
        foreach (['review', 'approve'] as $action) {
            Route::post($collection.'/{id}/'.$action, [ContentController::class, 'transition'])->defaults('action', $action);
        }
    }
    foreach (['publish', 'unpublish'] as $action) {
        Route::post('pages/{id}/'.$action, [ContentController::class, 'transition'])->defaults('action', $action)->middleware('fresh');
    }
    Route::get('pages/{id}/preview', [PublicController::class, 'preview']);
    Route::get('themes', [ThemeController::class, 'index']);
    Route::get('themes/uploads', [ThemeController::class, 'uploads']);
    Route::post('themes/uploads/{id}/retry', [ThemeController::class, 'retry'])->middleware('throttle:5,1');
    Route::post('themes/upload', [ThemeController::class, 'upload'])->middleware('throttle:5,1');
    Route::post('themes/designer', [ThemeController::class, 'design']);
    Route::post('themes/rollback', [ThemeController::class, 'rollback'])->middleware('fresh');
    Route::post('themes/{id}/activate', [ThemeController::class, 'activate'])->middleware('fresh');
    Route::get('themes/{id}/preview', [PublicController::class, 'themePreview']);
    Route::get('publishing/settings', [SeoController::class, 'settings']);
    Route::patch('publishing/settings', [SeoController::class, 'settings'])->middleware('fresh');
    Route::get('seo/audit', [SeoController::class, 'audit']);
    Route::match(['get', 'post'], 'seo/visibility', [SeoController::class, 'visibility']);
});
// Publicly cacheable responses must never carry a session or XSRF cookie, so these routes run without them.
Route::withoutMiddleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])->group(function () {
    Route::get('/', [PublicController::class, 'home'])->name('home');
    Route::get('/p/{slug}', [PublicController::class, 'page'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
    Route::get('/sitemap.xml', [PublicController::class, 'sitemap']);
    Route::get('/sitemap-index.xml', [PublicController::class, 'sitemapIndex']);
    Route::get('/indexnow-key.txt', [PublicController::class, 'indexNowKey']);
    Route::get('/robots.txt', [PublicController::class, 'robots']);
    Route::get('/theme-assets/{id}/{path}', [ThemeController::class, 'asset'])->where('path', '[a-zA-Z0-9_./-]+');
});
Route::get('/contact-request', [WebsiteEnquiryController::class, 'form']);
Route::post('/contact-request', [WebsiteEnquiryController::class, 'store'])->middleware('throttle:5,10');
