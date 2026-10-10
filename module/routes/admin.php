<?php

use HytaleHosting\Core\Http\Controllers\AdminController;
use HytaleHosting\Core\Http\Middleware\RequireRootAdmin;
use Illuminate\Support\Facades\Route;

// Mounted at /extensions/hytale-hosting by both adapters, behind the Panel's
// session authentication and two-factor middleware.
Route::middleware(RequireRootAdmin::class)->prefix('/admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('index');
    Route::get('/audit', [AdminController::class, 'audit'])->name('audit');
    Route::post('/settings', [AdminController::class, 'saveSettings'])->name('settings');

    Route::post('/accounts/login', [AdminController::class, 'startLogin'])->name('accounts.login');
    Route::get('/accounts/login/{id}', [AdminController::class, 'pollLogin'])->name('accounts.poll');
    Route::post('/accounts/{account}/refresh', [AdminController::class, 'refreshAccount'])->whereNumber('account')->name('accounts.refresh');
    Route::post('/accounts/{account}', [AdminController::class, 'updateAccount'])->whereNumber('account')->name('accounts.update');
    Route::post('/accounts/{account}/delete', [AdminController::class, 'deleteAccount'])->whereNumber('account')->name('accounts.delete');

    Route::post('/servers/sync', [AdminController::class, 'syncServers'])->name('servers.sync');
    Route::post('/servers/{uuid}/rotate', [AdminController::class, 'rotateServer'])->name('servers.rotate');
    Route::post('/servers/{uuid}/unlock', [AdminController::class, 'unlockServer'])->name('servers.unlock');
});
