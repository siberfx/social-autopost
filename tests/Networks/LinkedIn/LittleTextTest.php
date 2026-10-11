<?php

declare(strict_types=1);

use Siberfx\SocialAutopost\Networks\LinkedIn\LittleText;
use Siberfx\SocialAutopost\Tests\TestCase;

uses(TestCase::class);

it('escapes every reserved character with a backslash', function () {
    expect(LittleText::escape('a|{}@[]()<>#\\*_~b'))
        ->toBe('a\|\{\}\@\[\]\(\)\<\>\#\\\\\*\_\~b');
});

it('leaves ordinary text, newlines and multibyte characters alone', function () {
    expect(LittleText::escape("Çok güzel — ünlü 😀\nhttps://example.com/a-b?x=1&y=2"))
        ->toBe("Çok güzel — ünlü 😀\nhttps://example.com/a-b?x=1&y=2");
});

it('limits the escaped length without splitting an escape sequence', function () {
    $escaped = LittleText::escape(str_repeat('#', 2000));

    expect(mb_strlen($escaped))->toBe(3000)
        ->and($escaped)->toBe(str_repeat('\#', 1500));

    $odd = LittleText::escape('x'.str_repeat('#', 2000), 4);

    expect($odd)->toBe('x\#')
        ->and(mb_strlen($odd))->toBe(3);
});
