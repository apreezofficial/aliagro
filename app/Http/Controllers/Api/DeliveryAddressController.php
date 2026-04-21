<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryAddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'addresses' => $request->user()->deliveryAddresses()->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label'          => 'nullable|string|max:50',
            'recipient_name' => 'required|string|max:255',
            'phone'          => 'required|string|max:20',
            'address'        => 'required|string|max:255',
            'state'          => 'required|string|max:100',
            'lga'            => 'nullable|string|max:100',
            'is_default'     => 'boolean',
        ]);

        if ($validated['is_default'] ?? false) {
            $request->user()->deliveryAddresses()->update(['is_default' => false]);
        }

        $address = $request->user()->deliveryAddresses()->create($validated);

        return response()->json(['message' => 'Address saved.', 'address' => $address], 201);
    }

    public function update(Request $request, DeliveryAddress $deliveryAddress): JsonResponse
    {
        $this->authorize($request, $deliveryAddress);

        $validated = $request->validate([
            'label'          => 'nullable|string|max:50',
            'recipient_name' => 'sometimes|string|max:255',
            'phone'          => 'sometimes|string|max:20',
            'address'        => 'sometimes|string|max:255',
            'state'          => 'sometimes|string|max:100',
            'lga'            => 'nullable|string|max:100',
            'is_default'     => 'boolean',
        ]);

        if ($validated['is_default'] ?? false) {
            $request->user()->deliveryAddresses()->update(['is_default' => false]);
        }

        $deliveryAddress->update($validated);

        return response()->json(['message' => 'Address updated.', 'address' => $deliveryAddress]);
    }

    public function destroy(Request $request, DeliveryAddress $deliveryAddress): JsonResponse
    {
        $this->authorize($request, $deliveryAddress);
        $deliveryAddress->delete();

        return response()->json(['message' => 'Address deleted.']);
    }

    private function authorize(Request $request, DeliveryAddress $address): void
    {
        if ($address->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized.');
        }
    }
}
