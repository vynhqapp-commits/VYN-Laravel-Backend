<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        try {
            $data = $request->validate([
                'include_inactive' => 'nullable|boolean',
                'q' => 'nullable|string|max:255',
            ]);

            $includeInactive = filter_var($data['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $qTerm = trim((string) ($data['q'] ?? ''));

            $q = Branch::with('staff');
            if (!$includeInactive) $q->where('is_active', true);
            if ($qTerm !== '') {
                $q->where(function ($sub) use ($qTerm) {
                    $sub->where('name', 'like', "%{$qTerm}%")
                        ->orWhere('address', 'like', "%{$qTerm}%")
                        ->orWhere('phone', 'like', "%{$qTerm}%")
                        ->orWhere('contact_email', 'like', "%{$qTerm}%");
                });
            }
            $branches = $q->orderBy('name')->get();
            return $this->success(BranchResource::collection($branches));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'name'               => 'required|string|max:255',
                'phone'              => 'nullable|string',
                'contact_email'      => 'nullable|email|max:255',
                'address'            => 'nullable|string',
                'timezone'           => 'nullable|string',
                'working_hours'      => 'nullable|string|max:4000',
                'gender_preference'  => 'nullable|in:ladies,gents,unisex',
                'lat'                => 'nullable|numeric',
                'lng'                => 'nullable|numeric',
                'is_active'          => 'sometimes|boolean',
            ]);

            return $this->created(new BranchResource(Branch::create($data)));

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function show(Branch $branch)
    {
        try {
            return $this->success(new BranchResource($branch->load('staff')));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update(Request $request, Branch $branch)
    {
        try {
            $branch->update($request->validate([
                'name'               => 'sometimes|string|max:255',
                'phone'              => 'nullable|string',
                'contact_email'      => 'nullable|email|max:255',
                'address'            => 'nullable|string',
                'timezone'           => 'nullable|string',
                'working_hours'      => 'nullable|string|max:4000',
                'gender_preference'  => 'nullable|in:ladies,gents,unisex',
                'lat'                => 'nullable|numeric',
                'lng'                => 'nullable|numeric',
                'is_active'          => 'sometimes|boolean',
            ]));

            return $this->success(new BranchResource($branch), 'Branch updated');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function destroy(Branch $branch)
    {
        try {
            $branch->delete();
            return $this->success(null, 'Branch deleted');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}
