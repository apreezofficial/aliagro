<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private User $farmer;
    private User $consumer;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->farmer = User::factory()->create(['role' => 'farmer']);
        $this->consumer = User::factory()->create(['role' => 'consumer']);
        $this->category = Category::create([
            'name' => 'Vegetables', 'slug' => 'vegetables', 'is_active' => true,
        ]);
    }

    public function test_anyone_can_list_products(): void
    {
        Product::factory()->count(3)->create([
            'farmer_id'   => $this->farmer->id,
            'category_id' => $this->category->id,
            'status'      => 'active',
        ]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    public function test_farmer_can_create_product(): void
    {
        $token = $this->farmer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/products', [
                'category_id'        => $this->category->id,
                'name'               => 'Fresh Tomatoes',
                'description'        => 'Freshly harvested tomatoes from our farm.',
                'price'              => 2500,
                'unit'               => 'basket',
                'quantity_available' => 50,
                'images'             => [UploadedFile::fake()->image('tomato.jpg')],
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'product']);

        $this->assertDatabaseHas('products', ['name' => 'Fresh Tomatoes']);
    }

    public function test_consumer_cannot_create_product(): void
    {
        $token = $this->consumer->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/products', [
                'category_id'        => $this->category->id,
                'name'               => 'Fake Product',
                'description'        => 'Test',
                'price'              => 100,
                'unit'               => 'kg',
                'quantity_available' => 10,
                'images'             => [UploadedFile::fake()->image('img.jpg')],
            ])->assertStatus(403);
    }

    public function test_farmer_can_update_own_product(): void
    {
        $product = Product::factory()->create([
            'farmer_id'   => $this->farmer->id,
            'category_id' => $this->category->id,
        ]);

        $token = $this->farmer->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/products/{$product->id}", ['price' => 3000])
            ->assertOk();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'price' => 3000]);
    }

    public function test_farmer_cannot_update_another_farmers_product(): void
    {
        $otherFarmer = User::factory()->create(['role' => 'farmer']);
        $product = Product::factory()->create([
            'farmer_id'   => $otherFarmer->id,
            'category_id' => $this->category->id,
        ]);

        $token = $this->farmer->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/products/{$product->id}", ['price' => 999])
            ->assertStatus(403);
    }

    public function test_product_can_be_filtered_by_category(): void
    {
        Product::factory()->count(2)->create([
            'farmer_id'   => $this->farmer->id,
            'category_id' => $this->category->id,
            'status'      => 'active',
        ]);

        $this->getJson("/api/products?category={$this->category->id}")
            ->assertOk();
    }
}
