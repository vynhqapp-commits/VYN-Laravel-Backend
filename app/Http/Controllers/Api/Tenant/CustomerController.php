<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerNoteResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index()
    {
        try {
            // Stable ordering: many seeded rows share the same created_at second,
            // which can cause pagination order to appear to "shuffle" between requests.
            $customers = Customer::orderByDesc('created_at')->orderByDesc('id')->paginate(20);
            return $this->paginated(CustomerResource::collection($customers)->resource);
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
                'email'    => 'nullable|email',
                'birthday' => 'nullable|date',
                'gender'   => 'nullable|in:male,female,other',
                'tags'     => 'nullable|string',
            ]);

            return $this->created(new CustomerResource(Customer::create($data)));

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function show(Customer $customer)
    {
        try {
            return $this->success(new CustomerResource($customer->load('notes.user', 'appointments', 'debts')));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update(Request $request, Customer $customer)
    {
        try {
            $customer->update($request->validate([
                'name'     => 'sometimes|string|max:255',
                'phone'    => 'nullable|string',
                'email'    => 'nullable|email',
                'birthday' => 'nullable|date',
                'gender'   => 'nullable|in:male,female,other',
                'tags'     => 'nullable|string',
            ]));

            return $this->success(new CustomerResource($customer), 'Customer updated');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function notes(Customer $customer)
    {
        try {
            $notes = $customer->notes()->with('user')->latest()->get();
            return $this->success(CustomerNoteResource::collection($notes));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function addNote(Request $request, Customer $customer)
    {
        try {
            $note = $customer->notes()->create([
                'user_id' => auth('api')->id(),
                'note'    => $request->validate(['note' => 'required|string'])['note'],
            ]);

            return $this->created(new CustomerNoteResource($note->load('user')), 'Note added');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}
