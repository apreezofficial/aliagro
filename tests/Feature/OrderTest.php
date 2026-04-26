<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private User $consumer;
    private User $farmer;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = User::factory()->create(['role' => 'consumer']);
        $this->farmer   = User::factory()->create(['role' => 'farmer']);

        $category = Category::create([
            'name' => 'Fruits', 'slug' => 'fruits', 'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'farmer_id'          => $this->farmer->id,
            'category_id'        => $category->id,
            'price'              => 5000,
            'quantity_available' => 100,
            'minimum_order'      => 1,
            'status'             => 'active',
            'unit'               => 'kg',
        ]);
    }

    public function test_consumer_can_place_order(): void
    {
        $token = $this->consumer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/orders', [
                'items'            => [['product_id' => $this->product->id, 'quantity' => 2]],
                'delivery_address' => '10 Test Street, Lagos',
                'delivery_state'   => 'Lagos',
                'delivery_phone'   => '08012345678',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'order']);

        $this->assertDatabaseHas('orders', ['consumer_id' => $this->consumer->id]);
    }

    public function test_order_deducts_stock(): void
    {
        $token = $this->consumer->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/orders', [
                'items'            => [['product_id' => $this->product->id, 'quantity' => 10]],
                'delivery_address' => '10 Test Street',
                'delivery_state'   => 'Lagos',
                'delivery_phone'   => '08012345678',
            ]);

        $this->assertDatabaseHas('products', [
            'id'                 => $this->product->id,
            'quantity_available' => 90,
        ]);
    }

    public function test_cannot_order_more_than_available_stock(): void
    {
        $token = $this->consumer->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/orders', [
                'items'            => [['product_id' => $this->product->id, 'quantity' => 999]],
                'delivery_address' => '10 Test Street',
                'delivery_state'   => 'Lagos',
                'delivery_phone'   => '08012345678',
            ])->assertStatus(422);
    }

    public function test_consumer_can_cancel_pending_order(): void
    {
        $token = $this->consumer->createToken('test')->plainTextToken;

        $order = Order::create([
            'order_number'     => Order::generateOrderNumber(),
            'consumer_id'      => $this->consumer->id,
            'subtotal'         => 5000,
            'delivery_fee'     => 1500,
            'discount'         => 0,
            'total'            => 6500,
            'delivery_address' => '10 Test Street',
            'delivery_state'   => 'Lagos',
            'delivery_phone'   => '08012345678',
            'status'           => 'pending',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
    }

    public function test_consumer_can_list_own_orders(): void
    {
        $token = $this->consumer->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }
}
