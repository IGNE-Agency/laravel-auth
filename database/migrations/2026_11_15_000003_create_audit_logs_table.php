<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->timestamp('occurred_at')->index();
            $table->string('event')->index();
            $table->nullableMorphs('actor');
            $table->nullableMorphs('subject');
            $table->string('guard')->nullable();
            $table->string('channel')->nullable();
            $table->json('auth_methods')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->char('credential_hash', 64)->nullable();
            $table->string('request_id')->nullable();
            $table->json('metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
