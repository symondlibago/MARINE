<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MediaCategory;
use App\Models\MediaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MediaController extends Controller
{
    public function index()
    {
        // Get categories that have items, ordered by newest first
        $categories = MediaCategory::with('items')->orderBy('created_at', 'desc')->get();
        return response()->json(['success' => true, 'data' => $categories]);
    }

    public function storeItem(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'description' => 'nullable|string',
            // Ensure category_id is provided if adding to existing
            'category_id' => 'nullable|exists:media_categories,id',
            'file' => 'required|file|mimes:jpeg,png,jpg,mp4,mov|max:51200',
        ]);
    
        try {
            DB::beginTransaction();
    
            $categoryId = $request->input('category_id');

            // 1. Find or Create Category
            if ($categoryId) {
                $category = MediaCategory::findOrFail($categoryId);
            } else {
                // Creating a new one based on title
                 $category = MediaCategory::firstOrCreate(
                    ['title' => $request->title],
                    ['description' => $request->description ?? '']
                );
            }
    
            $file = $request->file('file');
            
            // 2. Upload to S3
            try {
                $filePath = Storage::disk('s3')->putFile('media', $file);

                if (!$filePath) {
                    throw new \Exception('Storage::putFile returned false.');
                }
            } catch (\Exception $e) {
                Log::error('S3 Storage Error: ' . $e->getMessage());
                throw new \Exception('Failed to upload to S3: ' . $e->getMessage());
            }

            // 3. Determine type
            $mime = $file->getMimeType();
            $type = str_contains($mime, 'video') ? 'vid' : 'img';
    
            // 4. Save to database
            $item = MediaItem::create([
                'media_category_id' => $category->id,
                'type' => $type,
                'src' => $filePath // Save relative path
            ]);
    
            DB::commit();
            
            // Re-fetch category to get updated list
            $updatedCategory = MediaCategory::with('items')->find($category->id);

            return response()->json([
                'success' => true,
                'message' => 'Uploaded successfully',
                'data' => $updatedCategory // Return the whole updated category
            ]);
    
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Media Upload Failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // --- NEW: Update Category Title/Description ---
    public function updateCategory(Request $request, $id)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        try {
            $category = MediaCategory::findOrFail($id);
            $category->update([
                'title' => $request->title,
                'description' => $request->description,
            ]);

            return response()->json(['success' => true, 'message' => 'Category updated successfully', 'data' => $category]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }

    // --- NEW: Delete Media Item ---
    public function deleteItem($id)
    {
        try {
            $item = MediaItem::findOrFail($id);

            // FIX: Use getRawOriginal() to get the path as stored in DB (media/filename.png)
            // ignoring the 'getSrcAttribute' accessor that adds the full http URL.
            $rawSrc = $item->getRawOriginal('src');

            // 1. Delete from S3
            // We only try to delete if it's NOT a full URL (meaning it's a relative S3 path)
            if ($rawSrc && !str_starts_with($rawSrc, 'http')) {
                 
                 // Check if it exists first to avoid errors
                 if (Storage::disk('s3')->exists($rawSrc)) {
                    $deleted = Storage::disk('s3')->delete($rawSrc);
                    
                    if (!$deleted) {
                        throw new \Exception("S3 refused to delete the file. Check permissions.");
                    }
                 }
            }

            // 2. Delete from DB (Only happens if S3 delete didn't crash)
            $item->delete();

            return response()->json(['success' => true, 'message' => 'Item deleted successfully']);

        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Delete Error: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()], 500);
        }
    }

    public function deleteCategory($id)
    {
        try {
            DB::beginTransaction();
            $category = MediaCategory::with('items')->findOrFail($id);

            // 1. Loop through all items and delete their S3 files
            foreach ($category->items as $item) {
                $rawSrc = $item->getRawOriginal('src');
                if ($rawSrc && !str_starts_with($rawSrc, 'http')) {
                    if (Storage::disk('s3')->exists($rawSrc)) {
                        Storage::disk('s3')->delete($rawSrc);
                    }
                }
            }

            // 2. Delete the items from DB (Cascade usually handles this, but explicit is safer)
            $category->items()->delete();

            // 3. Delete the category
            $category->delete();

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Category and all files deleted successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Category Delete Error: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()], 500);
        }
    }
}