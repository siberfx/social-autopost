<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Models\SharedPost;
use Siberfx\SocialAutopost\SocialManager;
use Throwable;

final class ShareCommand extends Command
{
    protected $signature = 'social:share
        {network : The network key, e.g. linkedin}
        {model : Morph alias or model class}
        {id : Primary key}
        {--page=* : LinkedIn and Facebook: a page id to post to instead of the selected pages (repeatable)}
        {--force : Share again even if it was already posted there}';

    protected $description = 'Share a model to one social network now';

    public function handle(SocialManager $social): int
    {
        $network = $this->stringArgument('network');

        if (! $social->isEnabled($network)) {
            $this->components->error("The [{$network}] network is switched off or unknown.");

            return self::FAILURE;
        }

        $type = $this->stringArgument('model');
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, ShareableOnSocial::class)) {
            $this->components->error("[{$type}] is not a shareable model.");

            return self::FAILURE;
        }

        $id = $this->stringArgument('id');

        /** @var (Model&ShareableOnSocial)|null $model */
        $model = $class::query()->find($id);

        if ($model === null) {
            $this->components->error("Record [{$id}] not found.");

            return self::FAILURE;
        }

        $pages = array_values(array_filter((array) $this->option('page'), fn ($page) => is_string($page) && $page !== ''));

        if ($pages !== []) {
            return $this->shareToPages($social, $model, $network, $pages);
        }

        if (! $this->option('force') && $social->wasPosted($model, $network)) {
            $this->components->warn('This was already posted there. Use --force to share it again.');

            return self::FAILURE;
        }

        try {
            $post = $social->shareTo($model, $network, SharedPost::TRIGGER_CONSOLE);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info($post->post_id !== null
            ? "Shared to {$network}: {$post->post_id}"
            : "Shared to {$network} (the network returned no post id).");

        return self::SUCCESS;
    }

    /** @param  list<string>  $pages */
    private function shareToPages(SocialManager $social, Model&ShareableOnSocial $model, string $network, array $pages): int
    {
        if (! $this->option('force')) {
            $pages = array_values(array_filter($pages, fn (string $page) => ! $social->wasPosted($model, $network, $page)));

            if ($pages === []) {
                $this->components->warn('This was already posted to those pages. Use --force to share it again.');

                return self::FAILURE;
            }
        }

        try {
            $records = $social->shareToPages($model, $network, $pages, SharedPost::TRIGGER_CONSOLE);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($records as $record) {
            $page = $record->target ?? $network;

            $record->status === SharedPost::STATUS_POSTED
                ? $this->components->info("Shared to {$page}: ".($record->post_id ?? '(no post id)'))
                : $this->components->error("Failed on {$page}: {$record->error}");
        }

        return $records->contains(fn (SharedPost $record) => $record->status === SharedPost::STATUS_FAILED) ? self::FAILURE : self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_scalar($value) ? (string) $value : '';
    }
}
