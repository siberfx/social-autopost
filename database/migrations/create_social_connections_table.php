<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('network', 32)->unique();
            // The network's own connection data, encrypted as a whole.
            $table->text('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_connections');
    }
};
