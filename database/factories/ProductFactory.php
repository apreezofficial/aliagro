<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'farmer_id'          => User::factory()->create(['role' => 'farmer'])->id,
            'category_id'        => Category::factory(),
            'name'               => ucwords($name),
            'slug'               => Str::slug($name) . '-' . Str::random(4),
            'description'        => fake()->paragraph(),
            'price'              => fake()->randomFloat(2, 500, 50000),
            'discount_price'     => null,
            'unit'               => fake()->randomElement(['kg', 'bag', 'crate', 'piece', 'basket']),
            'quantity_available' => fake()->numberBetween(10, 500),
            'minimum_order'      => 1,
            'images'             => ['/storage/products/sample.jpg'],
            'thumbnail'          => '/storage/products/sample.jpg',
            'status'             => 'active',
            'is_organic'         => fake()->boolean(30),
            'location'           => fake()->city() . ', Nigeria',
        ];
    }
}
