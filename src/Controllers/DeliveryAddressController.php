<?php

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\DeliveryAddress;

class DeliveryAddressController
{
    public function index(Request $request): Response
    {
        return json_response([
            'addresses' => DeliveryAddress::where('user_id', $request->user['id'])->latest()->get(),
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        return json_response(['address' => $this->owned($request, $id)]);
    }

    public function store(Request $request): Response
    {
        $v = $request->validate([
            'label'          => 'nullable|string|max:50',
            'recipient_name' => 'required|string|max:255',
            'phone'          => 'required|string|max:20',
            'address'        => 'required|string|max:255',
            'state'          => 'required|string|max:100',
            'lga'            => 'nullable|string|max:100',
            'is_default'     => 'boolean',
        ]);

        $userId = $request->user['id'];
        $v = array_filter($v, fn($x) => $x !== null);   // label has a DB default ('Home')

        $address = DB::transaction(function () use ($v, $userId) {
            if (!empty($v['is_default'])) {
                DB::update('delivery_addresses', ['is_default' => 0], 'user_id = ?', [$userId]);
            }
            return DeliveryAddress::create($v + ['user_id' => $userId]);
        });

        return json_response(['message' => 'Address saved.', 'address' => $address], 201);
    }

    public function update(Request $request, string $id): Response
    {
        $address = $this->owned($request, $id);

        $v = $request->validate([
            'label'          => 'nullable|string|max:50',
            'recipient_name' => 'sometimes|string|max:255',
            'phone'          => 'sometimes|string|max:20',
            'address'        => 'sometimes|string|max:255',
            'state'          => 'sometimes|string|max:100',
            'lga'            => 'nullable|string|max:100',
            'is_default'     => 'boolean',
        ]);
        if (array_key_exists('label', $v) && $v['label'] === null) {
            unset($v['label']);
        }

        $updated = DB::transaction(function () use ($v, $request, $address) {
            if (!empty($v['is_default'])) {
                DB::update('delivery_addresses', ['is_default' => 0], 'user_id = ?', [$request->user['id']]);
            }
            return DeliveryAddress::update($address['id'], $v);
        });

        return json_response(['message' => 'Address updated.', 'address' => $updated]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $address = $this->owned($request, $id);
        DeliveryAddress::delete($address['id']);

        return json_response(['message' => 'Address deleted.']);
    }

    private function owned(Request $request, string $id): array
    {
        $address = DeliveryAddress::findOrFail($id);
        if ($address['user_id'] !== $request->user['id']) {
            throw new HttpException(403, 'Unauthorized.');
        }
        return $address;
    }
}
