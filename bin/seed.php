<?php
/**
 * Seed reference data and demo accounts:  php bin/seed.php [--reference-only]
 *
 * --reference-only  skip the demo admin/farmer/consumer accounts (use this in production:
 *                   the demo passwords are public in the repository).
 * Re-running is safe; existing rows are left alone.
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require __DIR__ . '/../bootstrap/app.php';

use App\Core\Auth;
use App\Core\DB;
use App\Core\Str;
use App\Models\Badge;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\FarmerProfile;
use App\Models\User;

$referenceOnly = in_array('--reference-only', $argv ?? [], true);

if (!$referenceOnly) {
    $accounts = [
        ['name' => 'AliAgro Admin', 'email' => 'admin@aliagro.com', 'password' => 'Admin@1234', 'role' => 'admin'],
        ['name' => 'Emeka Okafor', 'email' => 'farmer@aliagro.com', 'password' => 'Farmer@1234', 'role' => 'farmer', 'phone' => '+2348081677861'],
        ['name' => 'Amina Bello', 'email' => 'consumer@aliagro.com', 'password' => 'Consumer@1234', 'role' => 'consumer', 'phone' => '+2348012345678'],
    ];

    foreach ($accounts as $a) {
        if (User::where('email', $a['email'])->exists()) {
            echo "skip user {$a['email']}\n";
            continue;
        }
        $user = User::create([
            'name' => $a['name'], 'email' => $a['email'], 'phone' => $a['phone'] ?? null,
            'password' => Auth::hash($a['password']), 'role' => $a['role'],
            'referral_code' => strtoupper(Str::random(8)), 'email_verified_at' => now(),
        ]);
        echo "user {$a['email']}\n";

        if ($a['role'] === 'farmer') {
            FarmerProfile::create([
                'user_id' => $user['id'], 'farm_name' => 'Okafor Organic Farms',
                'bio' => 'We grow the freshest organic produce in Anambra State.',
                'farm_address' => 'Km 5 Onitsha-Owerri Road', 'state' => 'Anambra', 'lga' => 'Onitsha North',
                'farm_size' => '10 hectares', 'is_verified' => true,
            ]);
        }
    }
}

$categories = [
    ['Vegetables', '🥦'], ['Fruits', '🍎'], ['Grains & Cereals', '🌾'], ['Tubers & Roots', '🥔'],
    ['Legumes', '🫘'], ['Livestock', '🐄'], ['Poultry', '🐔'], ['Dairy & Eggs', '🥚'],
    ['Spices & Herbs', '🌿'], ['Processed Foods', '🫙'],
];
foreach ($categories as $i => [$name, $icon]) {
    $slug = Str::slug($name);
    if (!Category::where('slug', $slug)->exists()) {
        Category::create(['name' => $name, 'slug' => $slug, 'icon' => $icon, 'sort_order' => $i + 1, 'is_active' => true]);
        echo "category {$name}\n";
    }
}

if (!Coupon::where('code', 'ALIAGRO10')->exists()) {
    Coupon::create([
        'code' => 'ALIAGRO10', 'type' => 'percentage', 'value' => 10, 'minimum_order' => 5000,
        'usage_limit' => 100, 'is_active' => true, 'expires_at' => now(365 * 86400),
    ]);
    echo "coupon ALIAGRO10\n";
}

$badges = [
    ['Top Seller', 'top-seller', 'Completed 50+ orders', '🏆', 'farmer'],
    ['Organic Certified', 'organic-certified', 'Sells verified organic produce', '🌿', 'farmer'],
    ['Fast Shipper', 'fast-shipper', 'Consistently high ratings (4.5+)', '⚡', 'farmer'],
    ['Loyal Buyer', 'loyal-buyer', 'Completed 10+ orders', '❤️', 'consumer'],
    ['Big Spender', 'big-spender', 'Total spend over ₦100,000', '💰', 'consumer'],
];
foreach ($badges as [$name, $slug, $description, $icon, $type]) {
    if (!Badge::where('slug', $slug)->exists()) {
        Badge::create(compact('name', 'slug', 'description', 'icon', 'type'));
        echo "badge {$name}\n";
    }
}

echo "\nSeeding done" . ($referenceOnly ? ' (reference data only).' : '.') . "\n";
