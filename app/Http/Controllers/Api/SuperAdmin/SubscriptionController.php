<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SubscriptionController extends Controller
{
    public function index()
    {
        try {
            $subs = Subscription::query()->with('tenant')->latest()->paginate(50);
            $rows = collect($subs->items())->map(function (Subscription $s) {
                return [
                    'id' => (string) $s->id,
                    'tenant_id' => (string) $s->tenant_id,
                    'tenant_name' => $s->tenant?->name,
                    'plan' => $s->plan,
                    'status' => $s->status,
                    'starts_at' => $s->starts_at ? (string) $s->starts_at : null,
                    'ends_at' => $s->ends_at ? (string) $s->ends_at : null,
                    'notes' => $s->notes,
                ];
            })->values();
            $subs->setCollection($rows);
            return $this->paginated($subs, 'Subscriptions retrieved');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function upsertForTenant(Request $request, Tenant $tenant)
    {
        try {
            $data = $request->validate([
                'plan' => 'required|in:basic,pro,enterprise',
                'status' => 'required|in:active,suspended,trial,cancelled',
                'starts_at' => 'nullable|date_format:Y-m-d',
                'ends_at' => 'nullable|date_format:Y-m-d',
                'notes' => 'nullable|string|max:255',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $sub = Subscription::query()->updateOrCreate(
                ['tenant_id' => $tenant->id],
                $data
            );

            // Keep legacy tenant fields in sync
            $tenant->update([
                'plan' => $data['plan'],
                'subscription_status' => $data['status'] === 'active' ? 'active' : 'suspended',
            ]);

            return $this->success([
                'id' => (string) $sub->id,
                'tenant_id' => (string) $sub->tenant_id,
                'plan' => $sub->plan,
                'status' => $sub->status,
                'starts_at' => $sub->starts_at ? (string) $sub->starts_at : null,
                'ends_at' => $sub->ends_at ? (string) $sub->ends_at : null,
                'notes' => $sub->notes,
            ], 'Subscription updated');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}

