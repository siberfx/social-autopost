<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Siberfx\SocialAutopost\Concerns\PostsToSocial;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Posts\LinkPost;

/** A model that goes live when its status becomes "published". */
final class Post extends Model implements ShareableOnSocial
{
    use PostsToSocial;

    protected $table = 'posts';

    protected $guarded = [];

    public function isLiveForSocial(): bool
    {
        return $this->status === 'published';
    }

    public function toSocialPost(): LinkPost
    {
        return LinkPost::make("https://example.test/posts/{$this->getKey()}", (string) $this->title)
            ->description('A short summary.')
            ->commentary("{title}\n\n{url}");
    }
}
