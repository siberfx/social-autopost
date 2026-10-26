<?php

declare(strict_types=1);

use Siberfx\SocialAutopost\Support\StatusMessages;

it('loads the package language lines', function () {
    expect(__('social-autopost::messages.share'))->toBe('Share')
        ->and(StatusMessages::fromCallback('LinkedIn', 'connected'))->toBe([
            'type' => 'success',
            'message' => 'LinkedIn connected.',
        ]);
});

it('uses the app locale when a translation exists for it', function () {
    app('translator')->addLines(['messages.connected' => ':label bağlandı.'], 'tr', 'social-autopost');
    app()->setLocale('tr');

    expect(StatusMessages::fromCallback('LinkedIn', 'connected')['message'])->toBe('LinkedIn bağlandı.');
});
