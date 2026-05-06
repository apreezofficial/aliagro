<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin
        User::create([
            'name'              => 'AliAgro Admin',
            'email'             => 'admin@aliagro.com',
            'password'          => Hash::make('Admin@1234'),
            'role'              => 'admin',
            'email_verified_at' => now(),
        ]);

        // Demo Farmer
        $farmer = User::create([
            'name'              => 'Emeka Okafor',
            'email'             => 'farmer@aliagro.com',
            'phone'             => '+2348081677861',
            'password'          => Hash::make('Farmer@1234'),
            'role'              => 'farmer',
            'email_verified_at' => now(),
        ]);

        \App\Models\FarmerProfile::create([
            'user_id'      => $farmer->id,
            'farm_name'    => 'Okafor Organic Farms',
            'bio'          => 'We grow the freshest organic produce in Anambra State.',
            'farm_address' => 'Km 5 Onitsha-Owerri Road',
            'state'        => 'Anambra',
            'lga'          => 'Onitsha North',
            'farm_size'    => '10 hectares',
            'is_verified'  => true,
        ]);

        // Demo Consumer
        User::create([
            'name'              => 'Amina Bello',
            'email'             => 'consumer@aliagro.com',
            'phone'             => '+2348012345678',
            'password'          => Hash::make('Consumer@1234'),
            'role'              => 'consumer',
            'email_verified_at' => now(),
        ]);

        // Categories
        $categories = [
            ['name' => 'Vegetables',   'icon' => '🥦', 'sort_order' => 1],
            ['name' => 'Fruits',       'icon' => '🍎', 'sort_order' => 2],
            ['name' => 'Grains & Cereals', 'icon' => '🌾', 'sort_order' => 3],
            ['name' => 'Tubers & Roots',   'icon' => '🥔', 'sort_order' => 4],
            ['name' => 'Legumes',      'icon' => '🫘', 'sort_order' => 5],
            ['name' => 'Livestock',    'icon' => '🐄', 'sort_order' => 6],
            ['name' => 'Poultry',      'icon' => '🐔', 'sort_order' => 7],
            ['name' => 'Dairy & Eggs', 'icon' => '🥚', 'sort_order' => 8],
            ['name' => 'Spices & Herbs', 'icon' => '🌿', 'sort_order' => 9],
            ['name' => 'Processed Foods', 'icon' => '🫙', 'sort_order' => 10],
        ];

        foreach ($categories as $cat) {
            Category::create([
                'name'       => $cat['name'],
                'slug'       => \Illuminate\Support\Str::slug($cat['name']),
                'icon'       => $cat['icon'],
                'sort_order' => $cat['sort_order'],
                'is_active'  => true,
            ]);
        }

        // Demo coupon
        \App\Models\Coupon::create([
            'code'          => 'ALIAGRO10',
            'type'          => 'percentage',
            'value'         => 10,
            'minimum_order' => 5000,
            'usage_limit'   => 100,
            'is_active'     => true,
            'expires_at'    => now()->addYear(),
        ]);

        // Badges
        $badges = [
            ['name' => 'Top Seller',        'slug' => 'top-seller',        'description' => 'Completed 50+ orders',              'icon' => '🏆', 'type' => 'farmer'],
            ['name' => 'Organic Certified', 'slug' => 'organic-certified', 'description' => 'Sells verified organic produce',     'icon' => '🌿', 'type' => 'farmer'],
            ['name' => 'Fast Shipper',      'slug' => 'fast-shipper',      'description' => 'Consistently high ratings (4.5+)',   'icon' => '⚡', 'type' => 'farmer'],
            ['name' => 'Loyal Buyer',       'slug' => 'loyal-buyer',       'description' => 'Completed 10+ orders',              'icon' => '❤️', 'type' => 'consumer'],
            ['name' => 'Big Spender',       'slug' => 'big-spender',       'description' => 'Total spend over ₦100,000',         'icon' => '💰', 'type' => 'consumer'],
        ];

        foreach ($badges as $badge) {
            \App\Models\Badge::create($badge);
        }
    }
}
