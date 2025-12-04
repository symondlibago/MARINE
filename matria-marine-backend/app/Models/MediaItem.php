<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MediaItem extends Model
{
    protected $fillable = ['media_category_id', 'type', 'src'];

    // Helper to get full S3 URL automatically
    public function getSrcAttribute($value)
    {
        // If it's already a full URL, return it, otherwise generate S3 url
        return str_starts_with($value, 'http') ? $value : Storage::disk('s3')->url($value);
    }
}