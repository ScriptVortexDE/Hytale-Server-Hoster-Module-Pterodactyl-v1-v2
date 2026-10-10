<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('ext_hytale_hosting_accounts')) {
            Schema::create('ext_hytale_hosting_accounts', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 64)->unique();
                $table->boolean('enabled')->default(true);
                $table->char('owner_uuid', 36)->nullable();
                $table->char('profile_uuid', 36)->nullable();
                $table->string('profile_username', 64)->nullable();
                $table->json('profiles')->nullable();
                // Encrypted with the Panel's APP_KEY through the model casts.
                $table->text('access_token')->nullable();
                $table->timestamp('access_token_expires_at')->nullable();
                $table->text('refresh_token')->nullable();
                $table->timestamp('refresh_token_obtained_at')->nullable();
                $table->boolean('needs_reauth')->default(false);
                $table->timestamp('limited_until')->nullable();
                $table->string('last_error', 255)->nullable();
                $table->timestamp('last_error_at')->nullable();
                $table->unsignedBigInteger('sessions_issued')->default(0);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('ext_hytale_hosting_servers')) {
            Schema::create('ext_hytale_hosting_servers', function (Blueprint $table) {
                $table->increments('id');
                // Keyed by UUID rather than a foreign key so the row survives long
                // enough to end the game session when the server is deleted.
                $table->char('server_uuid', 36)->unique();
                $table->char('token_hash', 64);
                $table->timestamp('token_rotated_at')->nullable();
                $table->unsignedInteger('account_id')->nullable();
                $table->text('last_session_token')->nullable();
                $table->timestamp('last_session_expires_at')->nullable();
                $table->timestamp('last_issued_at')->nullable();
                $table->string('last_issued_ip', 45)->nullable();
                $table->unsignedBigInteger('issue_count')->default(0);
                $table->timestamp('locked_at')->nullable();
                $table->string('locked_reason', 255)->nullable();
                $table->timestamps();

                $table->foreign('account_id')->references('id')->on('ext_hytale_hosting_accounts')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('ext_hytale_hosting_audit')) {
            Schema::create('ext_hytale_hosting_audit', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('server_uuid', 36)->nullable()->index();
                $table->unsignedInteger('account_id')->nullable();
                $table->string('event', 64);
                $table->string('outcome', 16);
                $table->string('ip', 45)->nullable();
                $table->string('message', 255)->nullable();
                $table->timestamp('created_at')->useCurrent()->index();

                $table->index(['server_uuid', 'event', 'created_at']);
            });
        }

        if (!Schema::hasTable('ext_hytale_hosting_settings')) {
            Schema::create('ext_hytale_hosting_settings', function (Blueprint $table) {
                $table->string('key', 64)->primary();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_hytale_hosting_settings');
        Schema::dropIfExists('ext_hytale_hosting_audit');
        Schema::dropIfExists('ext_hytale_hosting_servers');
        Schema::dropIfExists('ext_hytale_hosting_accounts');
    }
};
