<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\Reviews\ReviewModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Multitenancy\Models\Tenant as CurrentTenant;

class ReviewModerationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'status' => ['nullable', 'in:pending,approved,rejected'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $query = Review::query()->with([
            'customer:id,full_name,email,phone',
            'appointment:id,starts_at,status',
            'salon:id,name,slug',
            'approver:id,name,email',
        ])->latest('id');

        if (!CurrentTenant::checkCurrent()) {
            return $this->forbidden('Tenant context is required.');
        }

        $tenantId = (int) CurrentTenant::current()->id;
        $query->where('salon_id', $tenantId);

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return $this->paginated($query->paginate((int) ($data['per_page'] ?? 20)));
    }

    public function moderate(Request $request, Review $review, ReviewModerationService $service): JsonResponse
    {
        try {
            $data = $request->validate([
                'action' => ['required', 'in:approve,reject'],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $action = (string) $data['action'];
        $status = $action === 'approve' ? 'approved' : 'rejected';

        if (!CurrentTenant::checkCurrent() || (int) CurrentTenant::current()->id !== (int) $review->salon_id) {
            return $this->forbidden('You are not allowed to moderate this review.');
        }

        $review->update([
            'status' => $status,
            'approved_at' => $status === 'approved' ? now() : null,
            'approved_by' => auth('api')->id(),
        ]);

        $service->refreshTenantAverageRating((string) $review->salon_id);

        return $this->success([
            'review' => $review->fresh()->load(['customer:id,full_name,email,phone', 'appointment:id,starts_at,status', 'approver:id,name,email']),
        ], 'Review moderated');
    }
}

