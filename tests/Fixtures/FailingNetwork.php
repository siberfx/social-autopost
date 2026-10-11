<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Posts\LinkPost;

/** A network that is connected but refuses every post with the status in $status (default 503, retryable). */
final class FailingNetwork extends FakeNetwork
{
    public static int $status = 503;

    public function key(): string
    {
        return 'broken';
    }

    public function label(): string
    {
        return 'Broken';
    }

    public function publish(Model&ShareableOnSocial $model, LinkPost $post): ?string
    {
        throw new RequestFailed('Broken refused the post.', self::$status);
    }
}
