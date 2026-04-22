<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    /**
     * Validate a coupon code.
     */
    public function validate(Request $request): JsonResponse
    {
        $request->validate([
            'code'        => 'required|string',
            'order_total' => 'required|numeric|min:0',
        ]);

        $coupon = Coupon::where('code', strtoupper($request->code))->first();

        if (!$coupon || !$coupon->isValid()) {
            return response()->json(['message' => 'Invalid or expired coupon.'], 422);
        }

        if ($request->order_total < $coupon->minimum_order) {
            return response()->json([
                'message' => "Minimum order of ₦{$coupon->minimum_order} required for this coupon.",
            ], 422);
        }

        $discount = $coupon->calculateDiscount($request->order_total);

        return response()->json([
            'valid'    => true,
            'coupon'   => [
                'code'     => $coupon->code,
                'type'     => $coupon->type,
                'value'    => $coupon->value,
            ],
            'discount' => $discount,
        ]);
    }

    // Admin
    public function index(): JsonResponse
    {
        return response()->json(['coupons' => Coupon::latest()->paginate(20)]);
    }

    public function store(Request $request): JsonResponse
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

        $coupon = Coupon::create(array_merge($validated, [
            'code' => strtoupper($validated['code']),
        ]));

        return response()->json(['message' => 'Coupon created.', 'coupon' => $coupon], 201);
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        $coupon->update(['is_active' => false]);
        return response()->json(['message' => 'Coupon deactivated.']);
    }
}
