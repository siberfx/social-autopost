<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table): void {
            // The page a network with pages posted to (a LinkedIn URN or a Facebook Page id); null otherwise.
            $table->string('target')->nullable()->after('network');

            $table->index(['shareable_type', 'shareable_id', 'network', 'target'], 'social_posts_target_index');
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table): void {
            $table->dropIndex('social_posts_target_index');
            $table->dropColumn('target');
        });
    }
};
