<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the file_uploads table used for tracking every stored file.
 *
 * Includes:
 *  - Soft deletes to allow safe recovery of accidentally deleted records.
 *  - Polymorphic linkage columns (model_type/model_id) for optional owner tracking.
 *  - is_used flag to distinguish referenced files from orphaned ones.
 *  - Indexes on the most common filter columns for query performance.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('file_uploads')) {
            return;
        }

        Schema::create('file_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('name');
            $table->string('path');
            $table->string('disk')->default('public');
            $table->string('mime_type')->index();
            $table->string('type', 20)->default('other')->index();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();

            // Store extra information like generated thumbnails sizes to prevent breakage on config changes
            $table->json('metadata')->nullable();

            // Optional: link this file to any owning model (polymorphic)
            $table->nullableMorphs('model');

            // Tracks whether any model currently references this file.
            // false/null = orphaned candidate; true = in active use.
            $table->boolean('is_used')->nullable()->default(false)->index();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['disk', 'path']);
            $table->index(['type', 'is_used']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_uploads');
    }
};