<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->morphs('authenticatable');
            $table->char('token_hash', 64)->nullable();
            $table->string('access_token_id')->nullable()->index();
            $table->string('name');
            $table->string('ip_address', 45)->nullable();
            $table->boolean('remembered');
            $table->timestamp('verified_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
