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

        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $query->orderBy('name');

        // Opt-in pagination: only when the client asks (page / per_page present).
        // Other consumers (dropdowns) still get the full list as before.
        if ($request->filled('page') || $request->filled('per_page')) {
            $perPage = min(max((int) $request->query('per_page', 100), 1), 200);
            $paginator = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
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
            // One OR MORE emails, comma/semicolon separated — each must be valid.
            'email' => ['nullable', 'string', 'max:255', function ($attr, $value, $fail) {
                foreach (preg_split('/[,;]+/', (string) $value) as $part) {
                    $part = trim($part);
                    if ($part !== '' && ! filter_var($part, FILTER_VALIDATE_EMAIL)) {
                        $fail("“{$part}” is not a valid email address.");
                    }
                }
            }],
            'phone' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['currency'] = strtoupper($data['currency']);

        // Normalise the email list to a clean, comma-separated string.
        if (! empty($data['email'])) {
            $data['email'] = collect(preg_split('/[,;]+/', $data['email']))
                ->map(fn ($e) => trim($e))->filter()->implode(', ');
        }

        return $data;
    }
}
