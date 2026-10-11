<?php

declare(strict_types=1);

use Siberfx\SocialAutopost\Posts\LinkPost;

it('accepts an absolute http and https URL', function () {
    expect(LinkPost::make('https://example.com/a', 'A')->url())->toBe('https://example.com/a');
    expect(LinkPost::make('http://example.com/a', 'A')->url())->toBe('http://example.com/a');
});

it('refuses a relative URL or another scheme', function (string $url) {
    LinkPost::make($url, 'A');
})->with(['/blog/a', 'ftp://example.com/a', 'example.com/a'])->throws(InvalidArgumentException::class);

it('percent-encodes a non-ASCII path', function () {
    $post = LinkPost::make('https://example.com/blog/çalışma', 'A');

    expect($post->url())->toBe('https://example.com/blog/%C3%A7al%C4%B1%C5%9Fma');
});

it('fills the commentary template and keeps the default when none is set', function () {
    $default = LinkPost::make('https://example.com/a', 'Hello');
    expect($default->commentaryText())->toBe("Hello\n\nhttps://example.com/a");

    $custom = $default->commentary('New: {title} {url}');
    expect($custom->commentaryText())->toBe('New: Hello https://example.com/a');
    expect($default->commentaryText())->toBe("Hello\n\nhttps://example.com/a");
});

it('cuts the title to 200 and the description to 256 characters', function () {
    $post = LinkPost::make('https://example.com/a', str_repeat('t', 300))
        ->description(str_repeat('d', 300));

    expect(mb_strlen($post->title()))->toBe(200);
    expect(mb_strlen($post->descriptionText()))->toBe(256);
});

it('fills {title} with the title cut to 200 characters, the same as the title itself', function () {
    $post = LinkPost::make('https://example.com/a', str_repeat('a', 250))->commentary('{title}');

    expect($post->commentaryText())->toHaveLength(200)
        ->and($post->commentaryText())->toBe($post->title());
});
