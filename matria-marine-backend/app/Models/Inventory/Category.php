<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

/** One of the 30 provision groups the catalogue is organised into. */
class Category extends Model
{
    protected $table = 'provision_categories';

    protected $fillable = ['name', 'sort'];

    protected $casts = ['sort' => 'integer'];

    public function items()
    {
        return $this->hasMany(Item::class, 'category_id');
    }
}
