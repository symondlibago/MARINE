<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MmsUpdate extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'description', 'date_posted', 'image_path'];

    // Accessor to automatically give full S3 URL to frontend
    public function getImagePathAttribute($value)
    {
        return str_starts_with($value, 'http') ? $value : Storage::disk('s3')->url($value);
    }
}