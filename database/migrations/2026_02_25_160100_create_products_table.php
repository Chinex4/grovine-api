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
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image_url');
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('stock')->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_recommended')->default(false);
            $table->boolean('is_rush_hour_offer')->default(false);
            $table->timestamp('rush_hour_starts_at')->nullable();
            $table->timestamp('rush_hour_ends_at')->nullable();
            $table->timestamps();

            $table->index(['category_id', 'is_active']);
            $table->index(['is_recommended', 'is_active']);
            $table->index(['is_rush_hour_offer', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
