<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Networks\X\PostsClient;
use Siberfx\SocialAutopost\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates a post through the tweets endpoint with the bearer token', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '1900', 'text' => 'Hi']], 201)]);

    $id = app(PostsClient::class)->create('access-1', "Hi\n\nhttps://example.com/a");

    expect($id)->toBe('1900');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.x.com/2/tweets'
        && $request->method() === 'POST'
        && $request->header('Authorization')[0] === 'Bearer access-1'
        && $request['text'] === "Hi\n\nhttps://example.com/a");
});

it('reports a 401 as unauthorized with the reason X gives', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['title' => 'Unauthorized', 'detail' => 'Unauthorized'], 401)]);

    try {
        app(PostsClient::class)->create('access-1', 'Hi');
        $this->fail('Expected RequestFailed.');
    } catch (RequestFailed $e) {
        expect($e->isUnauthorized())->toBeTrue()
            ->and($e->getMessage())->toBe('X rejected the request: Unauthorized')
            ->and($e->isRetryable())->toBeFalse();
    }
});

it('reports other errors with the reason, the status and whether a retry can help', function (int $status, bool $retryable) {
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['detail' => 'Something happened'], $status)]);

    try {
        app(PostsClient::class)->create('access-1', 'Hi');
        $this->fail('Expected RequestFailed.');
    } catch (RequestFailed $e) {
        expect($e->status)->toBe($status)
            ->and($e->getMessage())->toBe('X rejected the request: Something happened')
            ->and($e->isRetryable())->toBe($retryable);
    }
})->with([
    'rate limited' => [429, true],
    'server error' => [503, true],
    'forbidden' => [403, false],
    'bad request' => [400, false],
]);

it('treats a 2xx without a post id as posted, with no id', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['data' => []], 201)]);

    expect(app(PostsClient::class)->create('access-1', 'Hi'))->toBeNull();
});

it('reports a network failure as a retryable request failure', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    try {
        app(PostsClient::class)->create('access-1', 'Hi');
        $this->fail('Expected RequestFailed.');
    } catch (RequestFailed $e) {
        expect($e->status)->toBe(0)
            ->and($e->getMessage())->toStartWith('Could not reach X:')
            ->and($e->isRetryable())->toBeTrue();
    }
});
