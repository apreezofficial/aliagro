<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KycVerification;
use App\Services\ImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KycController extends Controller
{
    public function __construct(private ImageUploadService $imageService) {}

    /**
     * Submit KYC documents.
     */
    public function submit(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->kycVerification && in_array($user->kycVerification->status, ['approved', 'under_review'])) {
            return response()->json([
                'message' => 'KYC already ' . $user->kycVerification->status . '.',
            ], 422);
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

        $frontPath  = $this->imageService->upload($request->file('id_front_image'), 'kyc');
        $backPath   = $request->hasFile('id_back_image')
            ? $this->imageService->upload($request->file('id_back_image'), 'kyc')
            : null;
        $selfiePath = $this->imageService->upload($request->file('selfie_image'), 'kyc');

        $kyc = KycVerification::updateOrCreate(
            ['user_id' => $user->id],
            [
                'status'         => 'pending',
                'id_type'        => $validated['id_type'],
                'id_number'      => $validated['id_number'],
                'id_front_image' => $frontPath,
                'id_back_image'  => $backPath,
                'selfie_image'   => $selfiePath,
                'address'        => $validated['address'],
                'state'          => $validated['state'],
                'country'        => $validated['country'] ?? 'Nigeria',
                'rejection_reason' => null,
                'reviewed_by'    => null,
                'reviewed_at'    => null,
            ]
        );

        return response()->json([
            'message' => 'KYC submitted successfully. Under review.',
            'kyc'     => $kyc,
        ], 201);
    }

    /**
     * Get current user's KYC status.
     */
    public function status(Request $request): JsonResponse
    {
        $kyc = $request->user()->kycVerification;

        if (!$kyc) {
            return response()->json(['message' => 'No KYC submission found.', 'kyc' => null]);
        }

        return response()->json(['kyc' => $kyc]);
    }

    // ─── Admin endpoints ────────────────────────────────────────────────────

    /**
     * List all KYC submissions (admin).
     */
    public function index(Request $request): JsonResponse
    {
        $kycs = KycVerification::with('user')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return response()->json($kycs);
    }

    /**
     * Approve a KYC submission (admin).
     */
    public function approve(Request $request, KycVerification $kyc): JsonResponse
    {
        $kyc->update([
            'status'      => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);

        // Mark farmer as verified if applicable
        if ($kyc->user->isFarmer()) {
            $kyc->user->farmerProfile?->update(['is_verified' => true]);
        }

        return response()->json(['message' => 'KYC approved.', 'kyc' => $kyc]);
    }

    /**
     * Reject a KYC submission (admin).
     */
    public function reject(Request $request, KycVerification $kyc): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $kyc->update([
            'status'           => 'rejected',
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
            'rejection_reason' => $request->reason,
        ]);

        return response()->json(['message' => 'KYC rejected.', 'kyc' => $kyc]);
    }
}
