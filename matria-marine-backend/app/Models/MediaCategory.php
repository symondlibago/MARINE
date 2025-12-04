<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MediaCategory extends Model
{
    protected $fillable = ['title', 'description'];

    public function items()
    {
        return $this->hasMany(MediaItem::class);
    }
}