<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the upload_sessions table used for resumable chunked uploads.
 *
 * Each row tracks the state of a multi-chunk upload from start to
 * completion. Chunks are stored as a JSON array of boolean values
 * where true indicates that chunk index has been received.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('upload_sessions')) {
            return;
        }

        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id')->unique()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('original_name');
            $table->string('disk');
            $table->string('folder');
            $table->string('mime_type');
            $table->unsignedBigInteger('total_size');
            $table->unsignedSmallInteger('total_chunks');
            $table->json('received_chunks')->default('[]');
            $table->string('status', 20)->default('pending');
            $table->string('assembled_path')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
    }
};
