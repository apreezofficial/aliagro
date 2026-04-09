<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farmer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description');
            $table->decimal('price', 12, 2);
            $table->decimal('discount_price', 12, 2)->nullable();
            $table->string('unit')->default('kg'); // kg, bag, crate, piece, etc.
            $table->integer('quantity_available')->default(0);
            $table->integer('minimum_order')->default(1);
            $table->json('images'); // array of image paths
            $table->string('thumbnail')->nullable();
            $table->enum('status', ['active', 'inactive', 'out_of_stock', 'pending_review'])->default('active');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_organic')->default(false);
            $table->string('harvest_date')->nullable();
            $table->string('expiry_date')->nullable();
            $table->string('location')->nullable();
            $table->decimal('rating', 3, 2)->default(0.00);
            $table->integer('rating_count')->default(0);
            $table->integer('total_sold')->default(0);
            $table->integer('views')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
