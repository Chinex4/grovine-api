<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('chef_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('DRAFT')->index();
            $table->string('title')->nullable();
            $table->string('slug')->nullable()->unique();
            $table->text('short_description')->nullable();
            $table->longText('instructions')->nullable();
            $table->string('video_url')->nullable();
            $table->string('cover_image_url')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('servings')->nullable();
            $table->decimal('estimated_cost', 12, 2)->nullable();
            $table->boolean('is_recommended')->default(false);
            $table->boolean('is_quick_recipe')->default(false);
            $table->unsignedBigInteger('views_count')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['chef_id', 'created_at']);
            $table->index(['status', 'is_recommended', 'is_quick_recipe', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};

