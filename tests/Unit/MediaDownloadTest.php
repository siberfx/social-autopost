<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Support\MediaDownload;

it('downloads media from a URL', function () {
    Http::fake(['https://cdn.example.com/a.png' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png; charset=binary'])]);

    expect(MediaDownload::fetch('https://cdn.example.com/a.png', 'LinkedIn'))->toBe(['png-bytes', 'image/png']);
});

it('reads a local file from disk without any request', function () {
    Http::fake();
    $path = tempnam(sys_get_temp_dir(), 'sa');
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

    [$bytes, $type] = MediaDownload::fetch($path, 'LinkedIn');

    expect($bytes)->toBe(file_get_contents($path))
        ->and($type)->toBe('image/png');
    Http::assertNothingSent();

    unlink($path);
});

it('fails for a local file that is not there, as a permanent failure', function () {
    try {
        MediaDownload::fetch('/nowhere/cover.jpg', 'LinkedIn');
        $this->fail('Expected RequestFailed');
    } catch (RequestFailed $e) {
        expect($e->getMessage())->toBe('Could not read the media for LinkedIn: no readable file at /nowhere/cover.jpg.')
            ->and($e->isRetryable())->toBeFalse();
    }
});
