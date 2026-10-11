<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Events;

use Illuminate\Database\Eloquent\Model;
use Siberfx\SocialAutopost\Models\SharedPost;

final class Shared
{
    public function __construct(
        public readonly string $network,
        public readonly Model $shareable,
        public readonly SharedPost $post,
    ) {}
}
