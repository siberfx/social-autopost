<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Posts;

use Closure;
use InvalidArgumentException;

/**
 * What a model wants posted: a link with its preview, plus the post text, and
 * optionally an image or a video. Every network posts the media it supports;
 * Instagram and TikTok post nothing without it.
 */
final class LinkPost
{
    private const DEFAULT_COMMENTARY = "{title}\n\n{url}";

    private string $description = '';

    private string $commentary = self::DEFAULT_COMMENTARY;

    private ?string $image = null;

    private ?string $video = null;

    /** @var array<string, Closure> One override per network key; forNetwork() checks what it returns. */
    private array $overrides = [];

    private function __construct(
        private readonly string $url,
        private readonly string $title,
    ) {}

    /** @throws InvalidArgumentException When the URL is not an absolute http(s) URL. */
    public static function make(string $url, string $title): self
    {
        return new self(self::absolute($url, 'Posts need'), $title);
    }

    /**
     * A public image URL: Instagram posts it (JPEG), TikTok as a photo post,
     * Facebook as a Page photo, and LinkedIn and X upload it and post it with
     * the text (X needs the media.write scope).
     *
     * @throws InvalidArgumentException When the URL is not an absolute http(s) URL.
     */
    public function image(string $url): self
    {
        $copy = clone $this;
        $copy->image = self::absolute($url, 'Images need');

        return $copy;
    }

    /**
     * A public video URL: Instagram posts it as a Reel, TikTok as a video,
     * Facebook as a Page video — instead of the image when both are set.
     * LinkedIn and X do not post video yet: they use the image, or the link alone.
     *
     * @throws InvalidArgumentException When the URL is not an absolute http(s) URL.
     */
    public function video(string $url): self
    {
        $copy = clone $this;
        $copy->video = self::absolute($url, 'Videos need');

        return $copy;
    }

    public function imageUrl(): ?string
    {
        return $this->image;
    }

    public function videoUrl(): ?string
    {
        return $this->video;
    }

    /** @throws InvalidArgumentException */
    private static function absolute(string $url, string $who): string
    {
        $normalised = self::normalise($url);
        $scheme = strtolower((string) parse_url($normalised, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true) || filter_var($normalised, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("{$who} an absolute http(s) URL, got [{$url}].");
        }

        return $normalised;
    }

    /**
     * Converts an internationalised host to punycode (when ext-intl is
     * installed) and percent-encodes every byte outside printable ASCII, so
     * https://şirket.com.tr/blog/çalışma becomes a URL the networks accept.
     */
    private static function normalise(string $url): string
    {
        $url = trim($url);

        if (function_exists('idn_to_ascii')
            && preg_match('~^([a-z][a-z0-9+.\-]*://)([^/?#]*)(.*)$~is', $url, $parts) === 1
            && preg_match('/[^\x00-\x7F]/', $parts[2]) === 1) {
            $at = strrpos($parts[2], '@');
            $userinfo = $at === false ? '' : substr($parts[2], 0, $at + 1);
            $hostAndPort = $at === false ? $parts[2] : substr($parts[2], $at + 1);
            $port = preg_match('/:\d*$/', $hostAndPort, $match) === 1 ? $match[0] : '';
            $host = idn_to_ascii(substr($hostAndPort, 0, strlen($hostAndPort) - strlen($port)), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if ($host !== false) {
                $url = $parts[1].$userinfo.$host.$port.$parts[3];
            }
        }

        return (string) preg_replace_callback('/[^\x21-\x7E]/', fn (array $byte): string => rawurlencode($byte[0]), $url);
    }

    public function description(string $description): self
    {
        $copy = clone $this;
        $copy->description = $description;

        return $copy;
    }

    /** Post text; {url} and {title} are filled in. Reserved characters are escaped when sending. */
    public function commentary(string $template): self
    {
        $copy = clone $this;
        $copy->commentary = $template;

        return $copy;
    }

    /**
     * Tailor the post for one network, or several: the closure gets the post
     * and returns the version that network publishes — hashtags on LinkedIn,
     * tighter text for X, a caption for Instagram.
     *
     *     LinkPost::make($url, $title)
     *         ->on('x', fn (LinkPost $post) => $post->commentary('{title} {url}'))
     *         ->on(['instagram', 'tiktok'], fn (LinkPost $post) => $post->commentary($caption));
     *
     * @param  string|list<string>  $networks
     * @param  Closure(self): self  $override
     */
    public function on(string|array $networks, Closure $override): self
    {
        $copy = clone $this;

        foreach ((array) $networks as $network) {
            $copy->overrides[$network] = $override;
        }

        return $copy;
    }

    /**
     * The post as one network publishes it: the on() override applied, or the
     * post as it is. Called once per network before publishing.
     *
     * @throws InvalidArgumentException When the override does not return a LinkPost.
     */
    public function forNetwork(string $network): self
    {
        $override = $this->overrides[$network] ?? null;

        if ($override === null) {
            return $this;
        }

        $resolved = $override($this);

        if (! $resolved instanceof self) {
            throw new InvalidArgumentException("The post's on('{$network}') override must return the post.");
        }

        $copy = clone $resolved;
        $copy->overrides = [];

        return $copy;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function title(): string
    {
        return mb_substr($this->title, 0, 200);
    }

    public function descriptionText(): string
    {
        return mb_substr($this->description, 0, 256);
    }

    public function commentaryText(): string
    {
        return strtr($this->commentary, ['{url}' => $this->url, '{title}' => $this->title()]);
    }
}
