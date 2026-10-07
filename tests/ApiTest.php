<?php
/**
 * End-to-end smoke tests, no framework needed.
 *
 *   1. point .env (or env vars) at an EMPTY test database and run: php bin/migrate.php && php bin/seed.php
 *   2. php -S 127.0.0.1:8000 -t public            (in another terminal)
 *   3. php tests/ApiTest.php [http://127.0.0.1:8000]
 *
 * Creates users/products with random suffixes; do not run against production data.
 */
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/') . '/api';
$pass = $fail = 0;

/** The test makes far more than 10 auth calls/minute, so clear the limiter between calls (except in the throttle test). */
function resetThrottle(): void
{
    static $pdo = null;
    $pdo ??= new PDO("mysql:host=" . (getenv('DB_HOST') ?: '127.0.0.1') . ";port=" . (getenv('DB_PORT') ?: 3306) . ";dbname=" . (getenv('DB_DATABASE') ?: 'aliagro_db'), getenv('DB_USERNAME') ?: 'root', getenv('DB_PASSWORD') ?: '');
    $pdo->exec('DELETE FROM rate_limits');
}

$GLOBALS['clearThrottle'] = true;

function call(string $method, string $path, array $data = [], ?string $token = null, bool $multipart = false): array
{
    global $base;
    if ($GLOBALS['clearThrottle']) resetThrottle();
    $ch = curl_init($base . $path);
    $headers = ['Accept: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true];
    if ($method !== 'GET' && $data) {
        if ($multipart) {
            $opts[CURLOPT_POSTFIELDS] = $data;
        } else {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($data);
        }
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => json_decode(substr($raw, $size), true), 'raw' => substr($raw, $size)];
}

function check(string $name, bool $ok, mixed $detail = null): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n";
    if ($detail !== null) echo '       ' . (is_string($detail) ? $detail : json_encode($detail)) . "\n";
}

$png = tempnam(sys_get_temp_dir(), 'img') . '.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$sfx = bin2hex(random_bytes(3));
$pw  = 'Password@1';

echo "Auth\n";
$r = call('POST', '/auth/register', ['name' => 'Bad'], null);
check('register validation 422', $r['status'] === 422 && isset($r['json']['errors']['email']), $r);
$r = call('POST', '/auth/register', ['name' => 'X', 'email' => "x$sfx@t.com", 'password' => 'weak', 'password_confirmation' => 'weak', 'role' => 'admin']);
check('register rejects admin role + weak password', $r['status'] === 422 && isset($r['json']['errors']['role'], $r['json']['errors']['password']), $r);

$r = call('POST', '/auth/register', ['name' => 'Farmer', 'email' => "farmer$sfx@t.com", 'password' => $pw, 'password_confirmation' => $pw, 'role' => 'farmer']);
check('register farmer 201', $r['status'] === 201 && !empty($r['json']['token']) && !isset($r['json']['user']['password']), $r);
$farmerTok = $r['json']['token'] ?? ''; $farmerId = $r['json']['user']['id'] ?? 0; $refCode = $r['json']['user']['referral_code'] ?? '';
$r = call('POST', '/auth/register', ['name' => 'Buyer', 'email' => "buyer$sfx@t.com", 'password' => $pw, 'password_confirmation' => $pw, 'role' => 'consumer', 'referral_code' => $refCode]);
check('register consumer with referral', $r['status'] === 201, $r);
$buyerTok = $r['json']['token'] ?? '';
$r = call('POST', '/auth/register', ['name' => 'Dup', 'email' => "buyer$sfx@t.com", 'password' => $pw, 'password_confirmation' => $pw, 'role' => 'consumer']);
check('duplicate email 422', $r['status'] === 422 && isset($r['json']['errors']['email']), $r);

$r = call('POST', '/auth/login', ['email' => "buyer$sfx@t.com", 'password' => 'nope']);
check('wrong password 401', $r['status'] === 401 && $r['json']['message'] === 'Invalid credentials.', $r);
$r = call('POST', '/auth/login', ['email' => "buyer$sfx@t.com", 'password' => $pw]);
check('login 200 w/ relations', $r['status'] === 200 && array_key_exists('farmer_profile', $r['json']['user']) && !empty($r['json']['token']), $r);
$buyerTok = $r['json']['token'];
$r = call('GET', '/auth/me');
check('me without token 401', $r['status'] === 401, $r);
$r = call('GET', '/auth/me', [], $buyerTok);
check('me with token', $r['status'] === 200 && $r['json']['user']['email'] === "buyer$sfx@t.com", $r);
$r = call('POST', '/auth/login', ['email' => "farmer$sfx@t.com", 'password' => $pw]);
$farmerTok = $r['json']['token'];
$r = call('POST', '/auth/login', ['email' => 'admin@aliagro.com', 'password' => 'Admin@1234']);
$adminTok = $r['json']['token'] ?? '';
check('admin login (seeded)', $adminTok !== '', $r);

echo "Roles\n";
$r = call('GET', '/admin/dashboard', [], $buyerTok);
check('consumer blocked from admin 403', $r['status'] === 403, $r);
$r = call('GET', '/admin/dashboard', [], $adminTok);
check('admin dashboard', $r['status'] === 200 && isset($r['json']['stats']['total_users']), $r);
$r = call('POST', '/products', ['name' => 'x'], $buyerTok);
check('consumer cannot create product 403', $r['status'] === 403, $r);

echo "Products\n";
$cats = call('GET', '/categories')['json']['categories'] ?? [];
check('categories list', count($cats) >= 10, $cats);
$catId = $cats[0]['id'] ?? 1;
$r = call('POST', '/products', [
    'category_id' => $catId, 'name' => "Tomatoes $sfx", 'description' => 'Fresh', 'price' => 1000, 'unit' => 'kg',
    'quantity_available' => 50, 'is_organic' => '1', 'images[0]' => new CURLFile($png, 'image/png', 'a.png'),
], $farmerTok, true);
check('farmer creates product 201', $r['status'] === 201 && str_starts_with($r['json']['product']['thumbnail'] ?? '', '/storage/products/'), $r);
$pid = $r['json']['product']['id'] ?? 0;
check('uploaded file is served', is_file(__DIR__ . '/../public' . ($r['json']['product']['thumbnail'] ?? '/nope')));
check('product casts (price float, is_organic bool)', $r['json']['product']['price'] == 1000 && $r['json']['product']['is_organic'] === true, $r['json']['product'] ?? null);
$r = call('GET', '/products?per_page=5');
check('products paginated', $r['status'] === 200 && $r['json']['per_page'] === 5 && isset($r['json']['links']) && $r['json']['data'][0]['farmer']['name'] !== null, $r['raw']);
$r = call('GET', "/products/$pid");
check('product show + relations', $r['status'] === 200 && isset($r['json']['product']['category'], $r['json']['product']['reviews']), $r);
$r = call('GET', '/products/trending');
check('trending route reachable (was shadowed in Laravel)', $r['status'] === 200 && isset($r['json']['trending']), $r);
$r = call('GET', '/products/99999999');
check('missing product 404', $r['status'] === 404, $r);
$r = call('GET', '/search?q=Tomatoes');
check('search finds product', $r['status'] === 200 && count($r['json']['products']) >= 1, $r);

echo "Cart / wishlist / address\n";
$r = call('POST', '/cart', ['product_id' => $pid, 'quantity' => 2], $buyerTok);
check('add to cart', $r['status'] === 200, $r);
$r = call('GET', '/cart', [], $buyerTok);
check('cart total', $r['json']['cart']['total'] == 2000.0 && $r['json']['cart']['count'] === 1, $r);
$r = call('POST', '/wishlist/toggle', ['product_id' => $pid], $buyerTok);
check('wishlist toggle', $r['json']['wishlisted'] === true, $r);
$r = call('POST', '/addresses', ['recipient_name' => 'Amina', 'phone' => '0801', 'address' => '1 Road', 'state' => 'Lagos', 'is_default' => true], $buyerTok);
check('address create', $r['status'] === 201 && $r['json']['address']['label'] === 'Home', $r);
$addrId = $r['json']['address']['id'] ?? 0;
$r = call('PUT', "/addresses/$addrId", ['state' => 'Abuja'], $buyerTok);
check('address update (broken in Laravel original)', $r['status'] === 200 && $r['json']['address']['state'] === 'Abuja', $r);

echo "Orders\n";
$r = call('POST', '/orders', ['items' => [['product_id' => $pid, 'quantity' => 100]], 'delivery_address' => 'x', 'delivery_state' => 'Lagos', 'delivery_phone' => '1'], $buyerTok);
check('over-stock rejected 422', $r['status'] === 422, $r);
$r = call('POST', '/orders', ['items' => [['product_id' => $pid, 'quantity' => 3]], 'delivery_address' => '1 Road', 'delivery_state' => 'Lagos', 'delivery_phone' => '0801', 'coupon_code' => 'ALIAGRO10'], $buyerTok);
check('order placed', $r['status'] === 201 && $r['json']['order']['subtotal'] == 3000.0 && $r['json']['order']['delivery_fee'] == 1500.0, $r);
$orderId = $r['json']['order']['id'] ?? 0;
check('stock decremented', call('GET', "/products/$pid")['json']['product']['quantity_available'] === 47);
$r = call('POST', "/orders/$orderId/cancel", [], $buyerTok);
check('cancel order', $r['status'] === 200, $r);
check('stock restored', call('GET', "/products/$pid")['json']['product']['quantity_available'] === 50);
$r = call('POST', "/orders/$orderId/cancel", [], $buyerTok);
check('cancel twice 422', $r['status'] === 422, $r);

$r = call('POST', '/orders', ['items' => [['product_id' => $pid, 'quantity' => 2], ['product_id' => $pid, 'quantity' => 1]], 'delivery_address' => '1 Road', 'delivery_state' => 'Kano', 'delivery_phone' => '0801'], $buyerTok);
check('duplicate lines merged', $r['status'] === 201 && count($r['json']['order']['items']) === 1 && $r['json']['order']['items'][0]['quantity'] === 3, $r);
$orderId = $r['json']['order']['id']; $itemId = $r['json']['order']['items'][0]['id'];
$r = call('GET', "/orders/$orderId", [], $farmerTok);
check('farmer can view own order', $r['status'] === 200, $r);
$r = call('GET', "/orders/$orderId", [], call('POST', '/auth/login', ['email' => 'consumer@aliagro.com', 'password' => 'Consumer@1234'])['json']['token']);
check('stranger cannot view order 403', $r['status'] === 403, $r);

echo "Wallet / payment\n";
$r = call('POST', '/wallet/pay', ['order_id' => $orderId], $buyerTok);
check('wallet pay with 0 balance 422', $r['status'] === 422 && isset($r['json']['required']), $r);
$r = call('POST', '/payments/initialize', ['order_id' => $orderId, 'gateway' => 'nope'], $buyerTok);
check('payments validation', $r['status'] === 422, $r);
$r = call('POST', '/payments/paystack/webhook', ['event' => 'charge.success']);
check('webhook without signature 400', $r['status'] === 400, $r);
$r = call('GET', '/wallet', [], $buyerTok);
check('wallet index', $r['status'] === 200 && $r['json']['wallet']['balance'] == 0.0, $r);

echo "Fulfilment, reviews, referral, badges\n";
$r = call('PUT', "/order-items/$itemId/status", ['status' => 'delivered'], $buyerTok);
check('consumer cannot update item (role) 403', $r['status'] === 403, $r);
$r = call('PUT', "/order-items/$itemId/status", ['status' => 'shipped'], $farmerTok);
check('farmer ships', $r['status'] === 200 && $r['json']['item']['status'] === 'shipped', $r);
$r = call('PUT', "/order-items/$itemId/status", ['status' => 'delivered'], $farmerTok);
check('farmer delivers', $r['status'] === 200, $r);
$r = call('GET', "/orders/$orderId", [], $buyerTok);
check('order auto-delivered', $r['json']['order']['status'] === 'delivered', $r['json']['order']['status'] ?? $r);
$r = call('GET', '/wallet', [], ($farmerTok = call('POST', '/auth/login', ['email' => "farmer$sfx@t.com", 'password' => $pw])['json']['token']));
check('referrer got ₦500 bonus', $r['json']['wallet']['balance'] == 500.0, $r);
$r = call('POST', "/products/$pid/reviews", ['order_id' => $orderId, 'rating' => 5, 'comment' => 'Great'], $buyerTok);
check('review submitted', $r['status'] === 201, $r);
$r = call('POST', "/products/$pid/reviews", ['order_id' => $orderId, 'rating' => 5], $buyerTok);
check('duplicate review 422', $r['status'] === 422, $r);
$r = call('GET', "/products/$pid/reviews");
check('product rating updated', $r['json']['average'] == 5.0 && $r['json']['total_reviews'] === 1, $r);
$r = call('GET', '/loyalty', [], $buyerTok);
check('loyalty endpoint', $r['status'] === 200 && $r['json']['points'] >= 50, $r);
$r = call('GET', '/referrals', [], ($farmerTok = call('POST', '/auth/login', ['email' => "farmer$sfx@t.com", 'password' => $pw])['json']['token']));
check('referral stats', $r['json']['rewarded'] === 1, $r);

echo "Follow / KYC / profile / GDPR\n";
$r = call('POST', "/farmers/$farmerId/follow", [], $buyerTok);
check('follow', $r['json']['following'] === true, $r);
$r = call('GET', '/following', [], $buyerTok);
check('following list w/ pivot', $r['status'] === 200 && isset($r['json']['data'][0]['pivot']['farmer_id']), $r['raw']);
$r = call('GET', "/farmers/$farmerId/followers");
check('followers', $r['json']['followers_count'] === 1, $r);
$r = call('POST', '/kyc', ['id_type' => 'passport', 'id_number' => 'A1', 'address' => 'x', 'state' => 'Lagos',
    'id_front_image' => new CURLFile($png, 'image/png', 'f.png'), 'selfie_image' => new CURLFile($png, 'image/png', 's.png')], $farmerTok, true);
check('kyc submit', $r['status'] === 201, $r);
$kycId = $r['json']['kyc']['id'] ?? 0;
$r = call('POST', "/admin/kyc/$kycId/approve", [], $adminTok);
check('admin approves kyc', $r['status'] === 200 && $r['json']['kyc']['status'] === 'approved', $r);
$r = call('GET', '/kyc/status', [], $farmerTok);
check('kyc status', $r['json']['kyc']['status'] === 'approved', $r);
$r = call('POST', '/farmer/profile', ['farm_name' => 'F', 'farm_address' => 'a', 'state' => 'Lagos'], $farmerTok);
check('farmer profile upsert', $r['status'] === 200 && $r['json']['profile']['country'] === 'Nigeria', $r);
$r = call('GET', "/farmers/$farmerId/profile");
check('public farmer profile', $r['status'] === 200 && count($r['json']['products']) >= 1, $r);
$r = call('PUT', '/profile', ['name' => 'New Name'], $buyerTok);
check('profile update', $r['json']['user']['name'] === 'New Name', $r);
$r = call('GET', '/gdpr/export', [], $buyerTok);
check('gdpr export', $r['status'] === 200 && count($r['json']['data']['orders']) >= 1, $r);
$r = call('GET', '/login-activity', [], $buyerTok);
check('login activity', $r['status'] === 200 && $r['json']['total'] >= 1, $r);
$r = call('GET', '/badges/mine', [], $buyerTok);
check('badges mine', $r['status'] === 200 && isset($r['json']['badges']), $r);
$r = call('POST', '/coupons/validate', ['code' => 'aliagro10', 'order_total' => 10000], $buyerTok);
check('coupon validate', $r['status'] === 200 && $r['json']['discount'] == 1000.0, $r);

echo "Password flows\n";
$r = call('POST', '/auth/forgot-password', ['email' => "buyer$sfx@t.com"]);
check('forgot password', $r['status'] === 200, $r);
$r = call('POST', '/auth/reset-password', ['email' => "buyer$sfx@t.com", 'token' => 'bad', 'password' => 'Newpass@1', 'password_confirmation' => 'Newpass@1']);
check('bad reset token 400', $r['status'] === 400, $r);
$r = call('POST', '/auth/change-password', ['current_password' => $pw, 'password' => 'Newpass@1', 'password_confirmation' => 'Newpass@1'], $buyerTok);
check('change password', $r['status'] === 200, $r);
$r = call('GET', '/auth/me', [], $buyerTok);
check('old token revoked', $r['status'] === 401, $r);

echo "Admin\n";
foreach (['/admin/users', '/admin/orders', '/admin/products', '/admin/transactions', '/admin/kyc', '/admin/coupons'] as $p) {
    $r = call('GET', $p, [], $adminTok);
    check("GET $p", $r['status'] === 200, $r['raw']);
}
$r = call('PUT', "/admin/users/$farmerId/status", ['status' => 'suspended'], $adminTok);
check('suspend user', $r['status'] === 200, $r);
$r = call('GET', '/auth/me', [], $farmerTok);
check('suspended user token revoked', $r['status'] === 401, $r);

echo "Throttle\n";
$GLOBALS['clearThrottle'] = false; resetThrottle();
$last = 0;
for ($i = 0; $i < 12; $i++) { $last = call('POST', '/auth/login', ['email' => 'a@b.co', 'password' => 'x'])['status']; }
check('auth endpoints rate limited 429', $last === 429, $last);

@unlink($png);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
