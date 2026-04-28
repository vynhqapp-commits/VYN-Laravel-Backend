<?php

namespace App\Http\Requests\Api\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => 'required|exists:branches,id',
            'customer_id' => 'nullable|exists:customers,id',
            'appointment_id' => 'nullable|exists:appointments,id',
            'notes' => 'nullable|string',
            'items' => 'present|array',
            'items.*.service_id' => 'nullable|exists:services,id',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'tips_amount' => 'nullable|numeric|min:0',
            'tip_allocation_mode' => 'nullable|in:single,equal_split,custom',
            'tip_allocations' => 'nullable|array',
            'tip_allocations.*.staff_id' => 'required_with:tip_allocations|exists:staff,id',
            'tip_allocations.*.amount' => 'nullable|numeric|min:0',
            'gift_card_amount' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:flat,percent',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_code' => 'nullable|string|max:64',
            'payments' => 'nullable|array',
            'payments.*.method' => 'required|string|in:cash,card,bank_transfer,wallet,whish,omt,transfer,mobile',
            'payments.*.amount' => 'required|numeric|min:0',
            'payments.*.reference' => 'nullable|string|max:255',
            'package_template_id' => 'nullable|integer|exists:service_package_templates,id',
            'membership_plan_id' => 'nullable|integer|exists:membership_plan_templates,id',
            'payment_method' => 'nullable|string',
        ];
    }
}
