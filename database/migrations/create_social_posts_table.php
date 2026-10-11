<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table): void {
            $table->id();
            $table->morphs('shareable');
            $table->string('network', 32)->index();
            $table->string('post_id')->nullable();
            $table->string('status', 16);
            $table->string('trigger', 16);
            $table->text('error')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['shareable_type', 'shareable_id', 'network', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
