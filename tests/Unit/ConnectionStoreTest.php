<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Siberfx\SocialAutopost\Contracts\ConnectionStore;

it('round trips a connection and keeps the token out of the database', function () {
    $store = app(ConnectionStore::class);

    $store->put('fake', ['token' => 'super-secret-token', 'page_id' => '42']);

    expect($store->get('fake'))->toBe(['token' => 'super-secret-token', 'page_id' => '42']);

    $raw = DB::table('social_connections')->where('network', 'fake')->value('payload');
    expect($raw)->not->toContain('super-secret-token');
});

it('replaces the data of one network without touching another', function () {
    $store = app(ConnectionStore::class);

    $store->put('fake', ['token' => 'first']);
    $store->put('broken', ['token' => 'other']);
    $store->put('fake', ['token' => 'second']);

    expect($store->get('fake'))->toBe(['token' => 'second']);
    expect($store->get('broken'))->toBe(['token' => 'other']);
});

it('forgets one network', function () {
    $store = app(ConnectionStore::class);

    $store->put('fake', ['token' => 'x']);
    $store->forget('fake');

    expect($store->get('fake'))->toBeNull();
});

it('reads as disconnected when the stored payload cannot be decrypted', function () {
    $store = app(ConnectionStore::class);
    $store->put('fake', ['token' => 'x']);

    DB::table('social_connections')->where('network', 'fake')->update(['payload' => 'not-encrypted']);

    expect($store->get('fake'))->toBeNull();
});
