<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MmsUpdate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class MmsUpdateController extends Controller
{
    // Public: Fetch all updates
    public function index()
    {
        $updates = MmsUpdate::orderBy('date_posted', 'desc')->get();
        return response()->json(['success' => true, 'data' => $updates]);
    }

    // Protected: Store new update
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'date_posted' => 'required|date',
            'image' => 'required|file|mimes:jpeg,png,jpg,webp|max:10240', 
        ]);

        try {
            $file = $request->file('image');
            $path = Storage::disk('s3')->putFile('updates', $file);

            if (!$path) throw new \Exception("S3 Upload failed");

            $update = MmsUpdate::create([
                'title' => $request->title,
                'description' => $request->description,
                'date_posted' => $request->date_posted,
                'image_path' => $path
            ]);

            return response()->json(['success' => true, 'message' => 'Update created successfully', 'data' => $update]);

        } catch (\Exception $e) {
            Log::error("MMS Update Store Error: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // Protected: Update existing record
    public function update(Request $request, $id)
    {
        $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'date_posted' => 'nullable|date',
            'image' => 'nullable|file|mimes:jpeg,png,jpg,webp|max:10240',
        ]);

        try {
            $mmsUpdate = MmsUpdate::findOrFail($id);
            
            // Build data array only with fields that are present
            $data = [];
            
            if ($request->filled('title')) {
                $data['title'] = $request->title;
            }
            if ($request->filled('description')) {
                $data['description'] = $request->description;
            }
            if ($request->filled('date_posted')) {
                $data['date_posted'] = $request->date_posted;
            }

            // Handle Image Replacement
            if ($request->hasFile('image')) {
                // 1. Delete old image from S3 using raw path
                $oldPath = $mmsUpdate->getRawOriginal('image_path');
                
                if ($oldPath && !str_starts_with($oldPath, 'http')) {
                    if (Storage::disk('s3')->exists($oldPath)) {
                        Storage::disk('s3')->delete($oldPath);
                    }
                }

                // 2. Upload new image
                $newPath = Storage::disk('s3')->putFile('updates', $request->file('image'));
                $data['image_path'] = $newPath;
            }

            $mmsUpdate->update($data);

            return response()->json(['success' => true, 'message' => 'Update modified successfully', 'data' => $mmsUpdate]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // Protected: Delete record
    public function destroy($id)
    {
        try {
            $mmsUpdate = MmsUpdate::findOrFail($id);
            
            // 1. Delete image from S3
            // Important: Use getRawOriginal to bypass the Accessor that adds the http domain
            $path = $mmsUpdate->getRawOriginal('image_path');
            
            if ($path && !str_starts_with($path, 'http')) {
                if (Storage::disk('s3')->exists($path)) {
                    Storage::disk('s3')->delete($path);
                }
            }

            // 2. Delete DB record
            $mmsUpdate->delete();

            return response()->json(['success' => true, 'message' => 'Update deleted successfully']);

        } catch (\Exception $e) {
            Log::error("MMS Delete Error: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}