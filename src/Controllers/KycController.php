<?php

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\FarmerProfile;
use App\Models\KycVerification;
use App\Models\User;
use App\Services\ImageUploadService;

class KycController
{
    private ImageUploadService $images;

    public function __construct()
    {
        $this->images = new ImageUploadService();
    }

    /** Submit KYC documents. */
    public function submit(Request $request): Response
    {
        $user     = $request->user;
        $existing = KycVerification::where('user_id', $user['id'])->first();

        if ($existing && in_array($existing['status'], ['approved', 'under_review'], true)) {
            throw new HttpException(422, 'KYC already ' . $existing['status'] . '.');
        }

        $validated = $request->validate([
            'id_type'        => 'required|in:national_id,passport,drivers_license,voters_card',
            'id_number'      => 'required|string|max:50',
            'id_front_image' => 'required|image|mimes:jpg,jpeg,png|max:5120',
            'id_back_image'  => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
            'selfie_image'   => 'required|image|mimes:jpg,jpeg,png|max:5120',
            'address'        => 'required|string|max:255',
            'state'          => 'required|string|max:100',
            'country'        => 'nullable|string|max:100',
        ]);

        $front  = $this->images->upload($request->file('id_front_image'), 'kyc');
        $back   = $request->hasFile('id_back_image') ? $this->images->upload($request->file('id_back_image'), 'kyc') : null;
        $selfie = $this->images->upload($request->file('selfie_image'), 'kyc');

        $kyc = KycVerification::updateOrCreate(['user_id' => $user['id']], [
            'status'           => 'pending',
            'id_type'          => $validated['id_type'],
            'id_number'        => $validated['id_number'],
            'id_front_image'   => $front,
            'id_back_image'    => $back,
            'selfie_image'     => $selfie,
            'address'          => $validated['address'],
            'state'            => $validated['state'],
            'country'          => $validated['country'] ?? 'Nigeria',
            'rejection_reason' => null,
            'reviewed_by'      => null,
            'reviewed_at'      => null,
        ]);

        return json_response(['message' => 'KYC submitted successfully. Under review.', 'kyc' => $kyc], 201);
    }

    public function status(Request $request): Response
    {
        $kyc = KycVerification::where('user_id', $request->user['id'])->first();

        if (!$kyc) {
            return json_response(['message' => 'No KYC submission found.', 'kyc' => null]);
        }

        return json_response(['kyc' => $kyc]);
    }

    // ── Admin ────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $q = KycVerification::query()->with('user');
        if ($request->input('status')) {
            $q->where('status', $request->input('status'));
        }

        return json_response($q->latest()->paginate(20));
    }

    public function approve(Request $request, string $kycId): Response
    {
        $kyc = KycVerification::findOrFail($kycId);

        $kyc = KycVerification::update($kyc['id'], [
            'status'           => 'approved',
            'reviewed_by'      => $request->user['id'],
            'reviewed_at'      => now(),
            'rejection_reason' => null,
        ]);

        // Mark the farm as verified if the applicant is a farmer.
        $owner = User::find($kyc['user_id']);
        if ($owner && User::isFarmer($owner)) {
            $profile = FarmerProfile::where('user_id', $owner['id'])->first();
            if ($profile) {
                FarmerProfile::update($profile['id'], ['is_verified' => true]);
            }
        }

        return json_response(['message' => 'KYC approved.', 'kyc' => $kyc]);
    }

    public function reject(Request $request, string $kycId): Response
    {
        $kyc = KycVerification::findOrFail($kycId);
        $validated = $request->validate(['reason' => 'required|string|max:500']);

        $kyc = KycVerification::update($kyc['id'], [
            'status'           => 'rejected',
            'reviewed_by'      => $request->user['id'],
            'reviewed_at'      => now(),
            'rejection_reason' => $validated['reason'],
        ]);

        return json_response(['message' => 'KYC rejected.', 'kyc' => $kyc]);
    }
}
