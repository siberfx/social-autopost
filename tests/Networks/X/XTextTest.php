<?php

declare(strict_types=1);

use Siberfx\SocialAutopost\Networks\X\XText;
use Siberfx\SocialAutopost\Posts\LinkPost;

it('keeps the link as the last line and counts it as 23 characters', function () {
    $post = LinkPost::make('https://example.com/a', 'Title')->description('Desc');

    expect(XText::compose($post))->toBe("Title\n\nDesc\n\nhttps://example.com/a");
});

it('posts only the title and link when there is no description', function () {
    $post = LinkPost::make('https://example.com/a', 'Title');

    expect(XText::compose($post))->toBe("Title\n\nhttps://example.com/a");
});

it('fits a long title and description within 280 characters and keeps the link whole', function () {
    $url = 'https://example.com/'.str_repeat('a', 120);
    $post = LinkPost::make($url, str_repeat('T', 200))->description(str_repeat('d', 1000));

    $text = XText::compose($post);

    expect(XText::weigh($text, $url))->toBeLessThanOrEqual(XText::LIMIT)
        ->and($text)->toStartWith(str_repeat('T', 200)."\n\n")
        ->and($text)->toEndWith("\n\n".$url)
        ->and($text)->toContain('…');
});

it('counts a long URL as 23 characters, so more description fits', function () {
    $url = 'https://example.com/'.str_repeat('a', 300);
    $post = LinkPost::make($url, 'Title')->description(str_repeat('d', 1000));

    $text = XText::compose($post);

    expect(mb_strlen($text))->toBeGreaterThan(XText::LIMIT)
        ->and(XText::weigh($text, $url))->toBeLessThanOrEqual(XText::LIMIT)
        ->and(XText::weigh($text, $url))->toBeGreaterThan(XText::LIMIT - 5);
});

it('keeps a 200-character title whole and fits the description after it', function () {
    $post = LinkPost::make('https://example.com/a', str_repeat('T', 200))->description('Desc');

    expect(XText::compose($post))->toBe(str_repeat('T', 200)."\n\nDesc\n\nhttps://example.com/a");
});

it('uses a custom commentary as written when it fits', function () {
    $post = LinkPost::make('https://example.com/a', 'Title')
        ->description('Desc')
        ->commentary('New on the blog: {title} {url}');

    expect(XText::compose($post))->toBe('New on the blog: Title https://example.com/a');
});

it('falls back to the default layout when a custom commentary does not fit', function () {
    $post = LinkPost::make('https://example.com/a', 'Title')
        ->description('Desc')
        ->commentary(str_repeat('x', 300)."\n\n{url}");

    expect(XText::compose($post))->toBe("Title\n\nDesc\n\nhttps://example.com/a");
});

it('treats the default commentary as the default layout, so the description is kept', function () {
    $post = LinkPost::make('https://example.com/a', 'Title')
        ->description('Desc')
        ->commentary("{title}\n\n{url}");

    expect(XText::compose($post))->toBe("Title\n\nDesc\n\nhttps://example.com/a");
});
