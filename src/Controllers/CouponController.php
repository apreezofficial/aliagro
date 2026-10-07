<?php

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Coupon;

class CouponController
{
    /** Validate a coupon code against an order total. */
    public function validate(Request $request): Response
    {
        $validated = $request->validate([
            'code'        => 'required|string',
            'order_total' => 'required|numeric|min:0',
        ]);

        $coupon = Coupon::where('code', strtoupper($validated['code']))->first();

        if (!$coupon || !Coupon::isValid($coupon)) {
            throw new HttpException(422, 'Invalid or expired coupon.');
        }

        if ($validated['order_total'] < $coupon['minimum_order']) {
            throw new HttpException(422, "Minimum order of ₦{$coupon['minimum_order']} required for this coupon.");
        }

        return json_response([
            'valid'    => true,
            'coupon'   => [
                'code'  => $coupon['code'],
                'type'  => $coupon['type'],
                'value' => $coupon['value'],
            ],
            'discount' => Coupon::calculateDiscount($coupon, (float) $validated['order_total']),
        ]);
    }

    // ── Admin ────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        return json_response(['coupons' => Coupon::query()->latest()->paginate(20)]);
    }

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'code'             => 'required|string|unique:coupons,code|max:20',
            'type'             => 'required|in:percentage,fixed',
            'value'            => 'required|numeric|min:0',
            'minimum_order'    => 'nullable|numeric|min:0',
            'maximum_discount' => 'nullable|numeric|min:0',
            'usage_limit'      => 'nullable|integer|min:1',
            'starts_at'        => 'nullable|date',
            'expires_at'       => 'nullable|date|after:starts_at',
        ]);

        $validated['code'] = strtoupper($validated['code']);
        if (array_key_exists('minimum_order', $validated) && $validated['minimum_order'] === null) {
            unset($validated['minimum_order']);   // NOT NULL DEFAULT 0
        }
        foreach (['starts_at', 'expires_at'] as $field) {
            if (!empty($validated[$field])) {
                $validated[$field] = gmdate('Y-m-d H:i:s', strtotime($validated[$field]));
            }
        }

        return json_response(['message' => 'Coupon created.', 'coupon' => Coupon::create($validated)], 201);
    }

    /** Coupons are deactivated rather than deleted. */
    public function destroy(Request $request, string $couponId): Response
    {
        $coupon = Coupon::findOrFail($couponId);
        Coupon::update($coupon['id'], ['is_active' => false]);

        return json_response(['message' => 'Coupon deactivated.']);
    }
}
