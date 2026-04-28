<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Tenant\IndexBranchesRequest;
use App\Http\Requests\Api\Tenant\StoreBranchRequest;
use App\Http\Requests\Api\Tenant\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;

class BranchController extends Controller
{
    public function index(IndexBranchesRequest $request)
    {
        try {
            $data = $request->validated();

            $includeInactive = filter_var($data['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $qTerm = trim((string) ($data['q'] ?? ''));

            $q = Branch::with('staff');
            if (! $includeInactive) {
                $q->where('is_active', true);
            }
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

    public function store(StoreBranchRequest $request)
    {
        try {
            return $this->created(new BranchResource(Branch::create($request->validated())));
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

    public function update(UpdateBranchRequest $request, Branch $branch)
    {
        try {
            $branch->update($request->validated());

            return $this->success(new BranchResource($branch), 'Branch updated');
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
