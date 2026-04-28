<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Tenant\DecideApprovalRequestRequest;
use App\Http\Requests\Api\Tenant\IndexApprovalRequestsRequest;
use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalRequestActionService;
use Illuminate\Http\JsonResponse;

class ApprovalRequestController extends Controller
{
    public function __construct(
        private readonly ApprovalRequestActionService $approvalActions,
    ) {}

    public function index(IndexApprovalRequestsRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $q = ApprovalRequest::query()
                ->with(['branch', 'requestedBy', 'decidedBy'])
                ->latest('id');

            if (! empty($data['status'])) {
                $q->where('status', $data['status']);
            }
            if (! empty($data['entity_type'])) {
                $q->where('entity_type', $data['entity_type']);
            }

            return $this->paginated($q->paginate(50));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function approve(DecideApprovalRequestRequest $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        $data = $request->validated();

        $actorId = (int) auth('api')->id();
        $tenantId = (int) auth('api')->user()?->tenant_id;
        if (! $tenantId) {
            return $this->error('Tenant required', 422);
        }

        try {
            if ($approvalRequest->status !== ApprovalRequest::STATUS_PENDING) {
                return $this->error('Approval request is not pending', 422);
            }
            if ($approvalRequest->expires_at && now()->greaterThan($approvalRequest->expires_at)) {
                $approvalRequest->update([
                    'status' => ApprovalRequest::STATUS_EXPIRED,
                ]);

                return $this->error('Approval request has expired', 422);
            }

            $this->approvalActions->executeApproved($approvalRequest, $tenantId, $actorId, $data['notes'] ?? null);

            $approvalRequest->update([
                'status' => ApprovalRequest::STATUS_APPROVED,
                'decided_by' => $actorId,
                'decided_at' => now(),
            ]);

            return $this->success($approvalRequest->fresh(), 'Approved');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function reject(DecideApprovalRequestRequest $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        $data = $request->validated();

        $actorId = (int) auth('api')->id();
        $tenantId = (int) auth('api')->user()?->tenant_id;
        if (! $tenantId) {
            return $this->error('Tenant required', 422);
        }

        try {
            if ($approvalRequest->status !== ApprovalRequest::STATUS_PENDING) {
                return $this->error('Approval request is not pending', 422);
            }

            $approvalRequest->update([
                'status' => ApprovalRequest::STATUS_REJECTED,
                'decided_by' => $actorId,
                'decided_at' => now(),
                'payload' => array_merge($approvalRequest->payload ?? [], [
                    'rejection_notes' => $data['notes'] ?? null,
                ]),
            ]);

            return $this->success($approvalRequest->fresh(), 'Rejected');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
