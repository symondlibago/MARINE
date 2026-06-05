<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    public function index(Request $request)
    {
        $query = Vendor::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
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
        $vendor = Vendor::create($this->validateData($request));

        return response()->json([
            'success' => true,
            'message' => 'Vendor created.',
            'data' => $vendor,
        ], 201);
    }

    public function show(Vendor $vendor)
    {
        return response()->json(['success' => true, 'data' => $vendor]);
    }

    public function update(Request $request, Vendor $vendor)
    {
        $vendor->update($this->validateData($request));

        return response()->json([
            'success' => true,
            'message' => 'Vendor updated.',
            'data' => $vendor,
        ]);
    }

    public function destroy(Vendor $vendor)
    {
        $vendor->delete();

        return response()->json(['success' => true, 'message' => 'Vendor deleted.']);
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'currency' => ['required', 'string', 'size:3'],
            'nav_code' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        $data['currency'] = strtoupper($data['currency']);

        return $data;
    }
}
