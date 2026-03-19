<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function index()
    {
        try {
            $branches = Branch::with('staff')->where('is_active', true)->get();
            return $this->success(BranchResource::collection($branches));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'name'     => 'required|string|max:255',
                'phone'    => 'nullable|string',
                'address'  => 'nullable|string',
                'timezone' => 'nullable|string',
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
                'name'      => 'sometimes|string|max:255',
                'phone'     => 'nullable|string',
                'address'   => 'nullable|string',
                'timezone'  => 'nullable|string',
                'is_active' => 'sometimes|boolean',
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
