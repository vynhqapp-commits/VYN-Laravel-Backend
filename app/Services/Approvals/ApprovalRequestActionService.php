<?php

namespace App\Services\Approvals;

use App\Models\Appointment;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Services\SaleRefundService;

class ApprovalRequestActionService
{
    public function __construct(
        private readonly SaleRefundService $saleRefund,
    ) {}

    public function executeApproved(ApprovalRequest $approvalRequest, int $tenantId, int $actorId, ?string $notes): void
    {
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
            $this->saleRefund->refund($sale, $actorId, $tenantId, $refundReason);

            return;
        }

        if ($entityType === 'product' && $action === 'delete') {
            $product = Product::query()->findOrFail((int) $approvalRequest->entity_id);
            if ((int) $product->tenant_id !== $tenantId) {
                throw new \RuntimeException('Invalid product for tenant');
            }
            $product->delete();

            return;
        }

        if ($entityType === 'service' && $action === 'delete') {
            $service = Service::query()->findOrFail((int) $approvalRequest->entity_id);
            if ((int) $service->tenant_id !== $tenantId) {
                throw new \RuntimeException('Invalid service for tenant');
            }
            $service->delete();

            return;
        }

        throw new \RuntimeException('Unsupported approval request action');
    }
}
