<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerReviewController extends Controller
{
    public function index(): JsonResponse
    {
        $userId = auth('api')->id();
        if (!$userId) {
            return $this->error('Unauthenticated', 401);
        }

        $customerIds = Customer::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->pluck('id');

        if ($customerIds->isEmpty()) {
            return $this->success(['reviews' => []]);
        }

        $reviews = Review::withoutGlobalScopes()
            ->whereIn('customer_id', $customerIds)
            ->with(['salon:id,name,slug', 'appointment:id,status,starts_at'])
            ->latest('id')
            ->get();

        return $this->success([
            'reviews' => $reviews,
        ]);
    }

    public function store(Request $request, Appointment $appointment): JsonResponse
    {
        $owned = $this->ownedByCustomer($appointment);
        if ($owned !== true) {
            return $owned;
        }

        if ((string) $appointment->status !== 'completed') {
            return $this->error('Only completed bookings can be reviewed', 422);
        }

        try {
            $data = $request->validate([
                'rating' => ['required', 'integer', 'min:1', 'max:5'],
                'comment' => ['nullable', 'string', 'max:2000'],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $existing = Review::withoutGlobalScopes()
            ->where('appointment_id', $appointment->id)
            ->first();

        if ($existing) {
            return $this->error('Review already submitted for this appointment', 422);
        }

        $review = Review::withoutGlobalScopes()->create([
            'salon_id' => $appointment->tenant_id,
            'customer_id' => $appointment->customer_id,
            'appointment_id' => $appointment->id,
            'rating' => (int) $data['rating'],
            'comment' => $data['comment'] ?? null,
            'status' => 'pending',
        ]);

        return $this->created([
            'review' => $review,
            'message' => 'Review submitted and pending moderation.',
        ], 'Review submitted');
    }

    private function ownedByCustomer(Appointment $appointment): true|JsonResponse
    {
        $userId = auth('api')->id();
        if (!$userId) {
            return $this->error('Unauthenticated', 401);
        }

        $ownsCustomer = Customer::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('id', $appointment->customer_id)
            ->exists();

        if (!$ownsCustomer) {
            return $this->error('Not found', 404);
        }

        return true;
    }
}

