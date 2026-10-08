<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Str;
use App\Models\Badge;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\FarmerProfile;
use App\Models\User;

/** Reference data + accounts. Shared by `php bin/seed.php` and the /setup page. Idempotent. */
class Seeder
{
    /** @var string[] */
    private array $log = [];

    /**
     * @param array|null $admin       ['name','email','password'] for the first admin; null = skip
     * @param bool       $demoUsers   also create the public demo farmer/consumer (and demo admin if $admin is null)
     * @return string[] what was created
     */
    public function run(?array $admin, bool $demoUsers): array
    {
        if ($admin) {
            $this->user($admin['name'], $admin['email'], $admin['password'], 'admin');
        }

        if ($demoUsers) {
            if (!$admin) {
                $this->user('AliAgro Admin', 'admin@aliagro.com', 'Admin@1234', 'admin');
            }
            $farmer = $this->user('Emeka Okafor', 'farmer@aliagro.com', 'Farmer@1234', 'farmer', '+2348081677861');
            if ($farmer && !FarmerProfile::where('user_id', $farmer['id'])->exists()) {
                FarmerProfile::create([
                    'user_id' => $farmer['id'], 'farm_name' => 'Okafor Organic Farms',
                    'bio' => 'We grow the freshest organic produce in Anambra State.',
                    'farm_address' => 'Km 5 Onitsha-Owerri Road', 'state' => 'Anambra', 'lga' => 'Onitsha North',
                    'farm_size' => '10 hectares', 'is_verified' => true,
                ]);
            }
            $this->user('Amina Bello', 'consumer@aliagro.com', 'Consumer@1234', 'consumer', '+2348012345678');
        }

        $this->categories();
        $this->coupon();
        $this->badges();

        return $this->log;
    }

    private function user(string $name, string $email, string $password, string $role, ?string $phone = null): ?array
    {
        if (User::where('email', $email)->exists()) {
            $this->log[] = "skipped existing user {$email}";
            return User::where('email', $email)->first();
        }
        $user = User::create([
            'name' => $name, 'email' => $email, 'phone' => $phone, 'password' => Auth::hash($password),
            'role' => $role, 'referral_code' => strtoupper(Str::random(8)), 'email_verified_at' => now(),
        ]);
        $this->log[] = "created {$role} {$email}";
        return $user;
    }

    private function categories(): void
    {
        $categories = [
            ['Vegetables', '🥦'], ['Fruits', '🍎'], ['Grains & Cereals', '🌾'], ['Tubers & Roots', '🥔'],
            ['Legumes', '🫘'], ['Livestock', '🐄'], ['Poultry', '🐔'], ['Dairy & Eggs', '🥚'],
            ['Spices & Herbs', '🌿'], ['Processed Foods', '🫙'],
        ];
        $n = 0;
        foreach ($categories as $i => [$name, $icon]) {
            $slug = Str::slug($name);
            if (!Category::where('slug', $slug)->exists()) {
                Category::create(['name' => $name, 'slug' => $slug, 'icon' => $icon, 'sort_order' => $i + 1, 'is_active' => true]);
                $n++;
            }
        }
        $this->log[] = "categories: {$n} created";
    }

    private function coupon(): void
    {
        if (!Coupon::where('code', 'ALIAGRO10')->exists()) {
            Coupon::create([
                'code' => 'ALIAGRO10', 'type' => 'percentage', 'value' => 10, 'minimum_order' => 5000,
                'usage_limit' => 100, 'is_active' => true, 'expires_at' => now(365 * 86400),
            ]);
            $this->log[] = 'coupon ALIAGRO10 created';
        }
    }

    private function badges(): void
    {
        $badges = [
            ['Top Seller', 'top-seller', 'Completed 50+ orders', '🏆', 'farmer'],
            ['Organic Certified', 'organic-certified', 'Sells verified organic produce', '🌿', 'farmer'],
            ['Fast Shipper', 'fast-shipper', 'Consistently high ratings (4.5+)', '⚡', 'farmer'],
            ['Loyal Buyer', 'loyal-buyer', 'Completed 10+ orders', '❤️', 'consumer'],
            ['Big Spender', 'big-spender', 'Total spend over ₦100,000', '💰', 'consumer'],
        ];
        $n = 0;
        foreach ($badges as [$name, $slug, $description, $icon, $type]) {
            if (!Badge::where('slug', $slug)->exists()) {
                Badge::create(compact('name', 'slug', 'description', 'icon', 'type'));
                $n++;
            }
        }
        $this->log[] = "badges: {$n} created";
    }
}
