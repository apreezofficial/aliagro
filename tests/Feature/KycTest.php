<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KycTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_user_can_submit_kyc(): void
    {
        $user  = User::factory()->create(['role' => 'farmer']);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/kyc', [
                'id_type'        => 'national_id',
                'id_number'      => 'NIN12345678',
                'id_front_image' => UploadedFile::fake()->image('front.jpg'),
                'selfie_image'   => UploadedFile::fake()->image('selfie.jpg'),
                'address'        => '10 Farm Road, Anambra',
                'state'          => 'Anambra',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'kyc']);

        $this->assertDatabaseHas('kyc_verifications', [
            'user_id' => $user->id,
            'status'  => 'pending',
        ]);
    }

    public function test_user_can_check_kyc_status(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/kyc/status')
            ->assertOk();
    }

    public function test_admin_can_approve_kyc(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);

        $kyc = \App\Models\KycVerification::create([
            'user_id'        => $farmer->id,
            'status'         => 'pending',
            'id_type'        => 'passport',
            'id_number'      => 'A12345678',
            'id_front_image' => 'kyc/front.jpg',
            'selfie_image'   => 'kyc/selfie.jpg',
            'address'        => 'Test Address',
            'state'          => 'Lagos',
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/kyc/{$kyc->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('kyc_verifications', [
            'id'     => $kyc->id,
            'status' => 'approved',
        ]);
    }

    public function test_admin_can_reject_kyc_with_reason(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $farmer = User::factory()->create(['role' => 'farmer']);

        $kyc = \App\Models\KycVerification::create([
            'user_id'        => $farmer->id,
            'status'         => 'pending',
            'id_type'        => 'national_id',
            'id_number'      => 'NIN999',
            'id_front_image' => 'kyc/front.jpg',
            'selfie_image'   => 'kyc/selfie.jpg',
            'address'        => 'Test',
            'state'          => 'Kano',
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/kyc/{$kyc->id}/reject", [
                'reason' => 'ID image is blurry.',
            ])->assertOk();

        $this->assertDatabaseHas('kyc_verifications', [
            'id'     => $kyc->id,
            'status' => 'rejected',
        ]);
    }
}
