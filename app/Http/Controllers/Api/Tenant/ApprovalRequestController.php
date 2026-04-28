<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Services\SaleRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApprovalRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'status' => 'nullable|in:pending,approved,rejected,expired',
                'entity_type' => 'nullable|string|max:64',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $q = ApprovalRequest::query()
                ->with(['branch', 'requestedBy', 'decidedBy'])
                ->latest('id');

            if (!empty($data['status'])) $q->where('status', $data['status']);
            if (!empty($data['entity_type'])) $q->where('entity_type', $data['entity_type']);

            return $this->paginated($q->paginate(50));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function approve(Request $request, ApprovalRequest $approvalRequest, SaleRefundService $refunds): JsonResponse
    {
        try {
            $data = $request->validate([
                'notes' => 'nullable|string|max:255',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $actorId = (int) auth('api')->id();
        $tenantId = (int) auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

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

            $this->performApprovedAction($approvalRequest, $refunds, $tenantId, $actorId, $data['notes'] ?? null);

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

    public function reject(Request $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        try {
            $data = $request->validate([
                'notes' => 'nullable|string|max:255',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $actorId = (int) auth('api')->id();
        $tenantId = (int) auth('api')->user()?->tenant_id;
        if (!$tenantId) return $this->error('Tenant required', 422);

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

    private function performApprovedAction(
        ApprovalRequest $approvalRequest,
        SaleRefundService $refunds,
        int $tenantId,
        int $actorId,
        ?string $notes
    ): void {
        $entityType = (string) $approvalRequest->entity_type;
        $action = (string) $approvalRequest->requested_action;

        if ($entityType === 'appointment' && $action === 'delete') {
            $appointment = Appointment::query()->findOrFail((int) $approvalRequest->entity_id);
            if ((int) $appointment->tenant_id !== $tenantId) {
                throw new \RuntimeException('Invalid appointment for tenant');
            }
            $appointment->delete();
            return;
        }

        if ($entityType === 'sale' && $action === 'refund') {
            $sale = Invoice::query()->findOrFail((int) $approvalRequest->entity_id);
            if ((int) $sale->tenant_id !== $tenantId) {
                throw new \RuntimeException('Invalid sale for tenant');
            }
            $refundReason = (string) (($approvalRequest->payload['refund_reason'] ?? null) ?: ($notes ?: 'Refund approved'));
            $refunds->refund($sale, $actorId, $tenantId, $refundReason);
            return;
        }

        throw new \RuntimeException('Unsupported approval request action');
    }
}

