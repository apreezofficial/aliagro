<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_as_consumer(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name'                  => 'Test Consumer',
            'email'                 => 'consumer@test.com',
            'password'              => 'Password@1',
            'password_confirmation' => 'Password@1',
            'role'                  => 'consumer',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'user', 'token']);

        $this->assertDatabaseHas('users', ['email' => 'consumer@test.com', 'role' => 'consumer']);
    }

    public function test_user_can_register_as_farmer(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name'                  => 'Test Farmer',
            'email'                 => 'farmer@test.com',
            'password'              => 'Password@1',
            'password_confirmation' => 'Password@1',
            'role'                  => 'farmer',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'farmer@test.com', 'role' => 'farmer']);
    }

    public function test_registration_fails_with_invalid_role(): void
    {
        $this->postJson('/api/auth/register', [
            'name'                  => 'Bad Role',
            'email'                 => 'bad@test.com',
            'password'              => 'Password@1',
            'password_confirmation' => 'Password@1',
            'role'                  => 'admin', // not allowed
        ])->assertStatus(422);
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'email'    => 'login@test.com',
            'password' => bcrypt('Password@1'),
            'role'     => 'consumer',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'login@test.com',
            'password' => 'Password@1',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'user', 'token']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'wrong@test.com', 'password' => bcrypt('correct')]);

        $this->postJson('/api/auth/login', [
            'email'    => 'wrong@test.com',
            'password' => 'wrongpassword',
        ])->assertStatus(401);
    }

    public function test_authenticated_user_can_get_profile(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonStructure(['user']);
    }

    public function test_user_can_logout(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertOk();
    }

    public function test_suspended_user_cannot_login(): void
    {
        User::factory()->create([
            'email'    => 'suspended@test.com',
            'password' => bcrypt('Password@1'),
            'status'   => 'suspended',
        ]);

        $this->postJson('/api/auth/login', [
            'email'    => 'suspended@test.com',
            'password' => 'Password@1',
        ])->assertStatus(403);
    }
}
