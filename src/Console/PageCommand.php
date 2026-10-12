<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Siberfx\SocialAutopost\Support\Theme;
use Siberfx\SocialAutopost\Support\Ui;

/**
 * Writes a ready-made "Social connections" page into the app: one card for
 * every network switched on, plus the callback URLs to register. The page is
 * the app's own file from then on, to restyle or move as it likes.
 */
final class PageCommand extends Command
{
    private const THEMES = ['tailwind', 'bootstrap'];

    protected $signature = 'social:page
        {--theme= : tailwind or bootstrap (default: the configured UI theme)}
        {--layout= : A Blade layout to @extends, e.g. layouts.app (default: a standalone page)}
        {--section=content : The layout section the page fills}
        {--livewire : Use the Livewire cards, which disconnect without a page reload}
        {--view=social.connections : Where to write the view, as a view name}
        {--force : Overwrite the view when it already exists}';

    protected $description = 'Write a Social connections page with a card for every enabled network';

    public function handle(): int
    {
        if (! Ui::enabled()) {
            $this->components->error('The UI is switched off (SOCIAL_UI=false), so there are no cards to put on a page.');

            return self::FAILURE;
        }

        $theme = $this->stringOption('theme') ?? Theme::name();

        if (! in_array($theme, self::THEMES, true)) {
            $this->components->error('Choose tailwind or bootstrap.');

            return self::FAILURE;
        }

        if ($this->option('livewire') && ! class_exists(Livewire::class)) {
            $this->components->error('Livewire is not installed: drop --livewire, or composer require livewire/livewire.');

            return self::FAILURE;
        }

        $view = $this->stringOption('view') ?? 'social.connections';
        $path = resource_path('views/'.str_replace('.', '/', $view).'.blade.php');

        if (File::exists($path) && ! $this->option('force')) {
            $this->components->error("{$this->relative($path)} already exists. Use --force to overwrite it.");

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->page($theme));

        $this->components->info("Wrote {$this->relative($path)}.");
        $this->line('Next steps:');
        $this->line('  1. Add a route for it, behind the same gate as the package routes, e.g. in routes/web.php:');
        $this->line("     Route::view('/admin/social', '{$view}')->middleware(['auth', 'can:manage-social-autopost'])->name('social.connections');");
        $this->line('  2. Send people back to it after connecting: set routes.after_connect to \'/admin/social\' in config/social-autopost.php.');

        if ($this->stringOption('layout') === null) {
            $this->line('  3. The page is standalone and loads '.($theme === 'tailwind' ? 'Tailwind' : 'Bootstrap').' from a CDN. Use --layout=layouts.app to put it in your own layout instead.');
        }

        return self::SUCCESS;
    }

    private function page(string $theme): string
    {
        $card = $this->option('livewire')
            ? '<livewire:social-autopost.connection :network="$network" :key="\'social-\'.$network" />'
            : '<x-social-autopost::connection :network="$network" />';

        $body = str_replace('%%CARD%%', $card, File::get(__DIR__.'/../../stubs/page/'.$theme.'.stub'));
        $layout = $this->stringOption('layout');

        if ($layout !== null) {
            $section = $this->stringOption('section') ?? 'content';

            return "@extends('{$layout}')\n\n@section('{$section}')\n{$body}@endsection\n";
        }

        $assets = $theme === 'tailwind'
            ? '<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.13"></script>'
            : '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">';
        $bodyClass = $theme === 'tailwind' ? ' class="bg-gray-50 dark:bg-gray-950"' : ' class="bg-light"';

        return <<<BLADE
            <!doctype html>
            <html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="csrf-token" content="{{ csrf_token() }}">
                <title>Social connections · {{ config('app.name') }}</title>
                {$assets}
            </head>
            <body{$bodyClass}>
            {$body}</body>
            </html>

            BLADE;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/\\');
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
