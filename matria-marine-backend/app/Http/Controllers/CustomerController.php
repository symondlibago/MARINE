<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $customer = Customer::create($this->validateData($request));

        return response()->json(['success' => true, 'message' => 'Customer created.', 'data' => $customer], 201);
    }

    public function show(Customer $customer)
    {
        return response()->json(['success' => true, 'data' => $customer]);
    }

    public function update(Request $request, Customer $customer)
    {
        $customer->update($this->validateData($request));

        return response()->json(['success' => true, 'message' => 'Customer updated.', 'data' => $customer]);
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();

        return response()->json(['success' => true, 'message' => 'Customer deleted.']);
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['currency'] = strtoupper($data['currency']);

        return $data;
    }
}
