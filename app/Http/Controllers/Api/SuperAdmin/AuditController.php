<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        try {
            $data = $request->validate([
                'from' => 'nullable|date_format:Y-m-d',
                'to' => 'nullable|date_format:Y-m-d',
                'actor_id' => 'nullable|exists:users,id',
                'tenant_id' => 'nullable|exists:tenants,id',
                'action' => 'nullable|string|max:255',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $q = AuditLog::query()->with(['actor:id,email,name', 'tenant:id,name'])->latest();

            if (!empty($data['from']) && !empty($data['to'])) {
                $from = Carbon::parse($data['from'])->startOfDay();
                $to = Carbon::parse($data['to'])->endOfDay();
                $q->whereBetween('created_at', [$from, $to]);
            }
            if (!empty($data['actor_id'])) $q->where('actor_id', $data['actor_id']);
            if (!empty($data['tenant_id'])) $q->where('tenant_id', $data['tenant_id']);
            if (!empty($data['action'])) $q->where('action', $data['action']);

            $logs = $q->paginate(50);
            $rows = collect($logs->items())->map(function (AuditLog $l) {
                return [
                    'id' => (string) $l->id,
                    'action' => $l->action,
                    'actor' => $l->actor ? [
                        'id' => (string) $l->actor->id,
                        'email' => $l->actor->email,
                        'name' => $l->actor->name,
                    ] : null,
                    'tenant' => $l->tenant ? [
                        'id' => (string) $l->tenant->id,
                        'name' => $l->tenant->name,
                    ] : null,
                    'meta' => $l->meta,
                    'created_at' => $l->created_at?->toISOString(),
                ];
            })->values();
            $logs->setCollection($rows);

            return $this->paginated($logs, 'Audit logs retrieved');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}

