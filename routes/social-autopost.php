<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Route;
use Siberfx\SocialAutopost\Http\Controllers\ConnectionController;
use Siberfx\SocialAutopost\Http\Controllers\ShareController;

/** Network keys are lowercase words, so "share" never collides with a network. */
$network = '[a-z0-9_-]+';

Route::group([
    'prefix' => (string) config('social-autopost.routes.prefix', 'social'),
    'as' => (string) config('social-autopost.routes.name', 'social-autopost.'),
    'middleware' => (array) config('social-autopost.routes.middleware', ['web', 'auth', 'can:manage-social-autopost']),
], function () use ($network): void {
    // Every network at once: the signed share button without a network (all enabled, connected networks).
    Route::post('share', [ShareController::class, '__invoke'])
        ->middleware(ValidateSignature::class)
        ->name('share');

    // Every enabled network's card as JSON, for React, Vue or any other front end.
    Route::get('connections', [ConnectionController::class, 'index'])
        ->name('connections.index');

    Route::get('{network}/redirect', [ConnectionController::class, 'redirect'])
        ->where('network', $network)
        ->name('redirect');

    Route::get('{network}/callback', [ConnectionController::class, 'callback'])
        ->where('network', $network)
        ->name('callback');

    Route::delete('{network}/connection', [ConnectionController::class, 'disconnect'])
        ->where('network', $network)
        ->name('connection.destroy');

    Route::post('{network}/share', [ShareController::class, '__invoke'])
        ->where('network', $network)
        ->middleware(ValidateSignature::class)
        ->name('share.network');
});
