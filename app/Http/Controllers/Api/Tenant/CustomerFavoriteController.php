<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerFavorite;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerFavoriteController extends Controller
{
    public function index(): JsonResponse
    {
        $customerIds = $this->customerIdsForCurrentUser();
        if ($customerIds->isEmpty()) {
            return $this->success(['favorites' => []]);
        }

        $favorites = CustomerFavorite::query()
            ->whereIn('customer_id', $customerIds)
            ->with(['salon' => fn ($q) => $q->withoutGlobalScopes()])
            ->latest()
            ->get()
            ->map(function (CustomerFavorite $favorite) {
                $salon = $favorite->salon;
                return [
                    'id' => $favorite->id,
                    'salon_id' => $favorite->salon_id,
                    'created_at' => $favorite->created_at,
                    'salon' => $salon ? [
                        'id' => $salon->id,
                        'name' => $salon->name,
                        'slug' => $salon->slug,
                        'logo' => $salon->logo,
                        'address' => $salon->address,
                    ] : null,
                ];
            })
            ->values();

        return $this->success(['favorites' => $favorites]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'salon_id' => ['required', 'exists:tenants,id'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $customer = $this->primaryCustomerForCurrentUser();
        if (!$customer) {
            return $this->error('Customer profile not found', 404);
        }

        $favorite = CustomerFavorite::firstOrCreate([
            'customer_id' => $customer->id,
            'salon_id' => (int) $data['salon_id'],
        ]);

        $favorite->load(['salon' => fn ($q) => $q->withoutGlobalScopes()]);

        return $this->success([
            'favorite' => [
                'id' => $favorite->id,
                'salon_id' => $favorite->salon_id,
                'salon' => [
                    'id' => $favorite->salon?->id,
                    'name' => $favorite->salon?->name,
                    'slug' => $favorite->salon?->slug,
                    'logo' => $favorite->salon?->logo,
                    'address' => $favorite->salon?->address,
                ],
            ],
        ], 'Favorite saved');
    }

    public function destroy(Tenant $salon): JsonResponse
    {
        $customerIds = $this->customerIdsForCurrentUser();
        if ($customerIds->isEmpty()) {
            return $this->error('Customer profile not found', 404);
        }

        CustomerFavorite::query()
            ->whereIn('customer_id', $customerIds)
            ->where('salon_id', $salon->id)
            ->delete();

        return $this->success(['deleted' => true], 'Favorite removed');
    }

    private function customerIdsForCurrentUser()
    {
        $userId = auth('api')->id();
        if (!$userId) {
            return collect();
        }

        return Customer::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->pluck('id');
    }

    private function primaryCustomerForCurrentUser(): ?Customer
    {
        $userId = auth('api')->id();
        if (!$userId) {
            return null;
        }

        return Customer::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->first();
    }
}
