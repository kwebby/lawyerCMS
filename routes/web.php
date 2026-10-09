<?php

// Author: ramanpal singh | URL: https://kwebby.com
use App\Http\Controllers\AiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CommunicationSettingsController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RecoveryController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/login', fn (Request $r, AuthController $c) => $c->form($r))->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:8,1');
Route::get('/register', fn (Request $r, AuthController $c) => $c->form($r, 'Register'));
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,1');
Route::match(['get', 'post'], '/setup', [AuthController::class, 'setup'])->middleware('throttle:10,1');
Route::get('/verify/{token}', [AuthController::class, 'verify'])->middleware(['auth', 'throttle:10,1']);
Route::get('/forgot-password', fn () => Inertia::render('Auth/Forgot'));
Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1');
Route::match(['get', 'post'], '/password/reset/{token}', [AuthController::class, 'reset'])->middleware('throttle:5,1');
Route::match(['get', 'post'], '/invite/{token}', [AuthController::class, 'accept'])->middleware('throttle:5,1');
Route::middleware('auth')->group(function () {
    Route::match(['get', 'post'], '/mfa', [AuthController::class, 'mfa'])->middleware('throttle:10,1');
    Route::get('/mfa/recovery', [RecoveryController::class, 'show']);
    Route::post('/mfa/recovery-codes', [RecoveryController::class, 'generate'])->middleware('fresh');
    Route::match(['get', 'post'], '/mfa/recover', [RecoveryController::class, 'recover'])->middleware('throttle:5,1');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/app/{section?}/{workflow?}', [WorkspaceController::class, 'page']);
    Route::get('/portal/{section?}/{workflow?}', [WorkspaceController::class, 'page']);
    Route::prefix('api/v1')->group(function () {
        Route::get('workspace/{section}', [WorkspaceController::class, 'api']);
        Route::post('auth/confirm', [AuthController::class, 'confirm'])->middleware('throttle:5,1');
        Route::post('invitations', [AuthController::class, 'invite'])->middleware('fresh');
        Route::get('users', [SettingsController::class, 'users']);
        Route::get('people', [SettingsController::class, 'people']);
        Route::patch('users/{id}', [SettingsController::class, 'updateUser'])->middleware('fresh');
        Route::get('roles', [SettingsController::class, 'roles']);
        Route::post('roles', [SettingsController::class, 'roles'])->middleware('fresh');
        Route::get('settings', [SettingsController::class, 'index']);
        Route::patch('settings', [SettingsController::class, 'update'])->middleware('fresh');
        Route::post('settings/mail/test', [SettingsController::class, 'testMail'])->middleware(['fresh', 'throttle:3,1']);
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::match(['get', 'patch'], 'notification-preferences', [CommunicationSettingsController::class, 'preferences']);
        Route::get('email-templates', [CommunicationSettingsController::class, 'templates']);
        Route::post('email-templates', [CommunicationSettingsController::class, 'templates'])->middleware('fresh');
        Route::post('notifications/{id}/read', [NotificationController::class, 'read']);
        Route::post('files', [FileController::class, 'upload'])->middleware('throttle:20,1');
        Route::get('files/{id}/download', [FileController::class, 'download']);
        Route::get('chat', [ChatController::class, 'index']);
        Route::post('chat', [ChatController::class, 'create']);
        Route::get('chat/{id}/messages', [ChatController::class, 'messages']);
        Route::post('chat/{id}/messages', [ChatController::class, 'send'])->middleware('throttle:60,1');
        Route::patch('chat/{id}/messages/{messageId}', [ChatController::class, 'edit']);
        Route::post('chat/{id}/read', [ChatController::class, 'read']);
        Route::get('ai/runs', [AiController::class, 'index']);
        Route::post('ai/runs', [AiController::class, 'create'])->middleware('throttle:10,1');
        Route::get('ai/runs/{id}', [AiController::class, 'show']);
        Route::post('ai/runs/{id}/review', [AiController::class, 'approve'])->middleware('fresh');
    });
});
if (file_exists(__DIR__.'/analyzers.php')) {
    require __DIR__.'/analyzers.php';
}
require __DIR__.'/business.php';
require __DIR__.'/publishing.php';
require __DIR__.'/website.php';
require __DIR__.'/website-assets.php';
