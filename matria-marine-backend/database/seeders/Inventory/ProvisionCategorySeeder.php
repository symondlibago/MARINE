<?php

namespace Database\Seeders\Inventory;

use App\Models\Inventory\Category;
use Illuminate\Database\Seeder;

class ProvisionCategorySeeder extends Seeder
{
    public const CATEGORIES = [
        'Vegetables fresh',
        'Fruits, Berries Fresh',
        'Dried Fruits, Nuts, Seeds, Mushrooms',
        'Beef',
        'Pork',
        'Lamb',
        'Poultry, Rabbit',
        'Fish, Seafood',
        'Sausages, Bacon, coldcuts',
        'Cheese',
        'Tinned Food (Fish, Meat)',
        'Tinned Food (Fruits, Vegetables, Pickles)',
        'Salad',
        'Fat, Oil, Butter',
        'Egg, Dairy Products',
        'Spreads, Preserves, Syrups',
        'Juice, Mineral Water',
        'Vinegar',
        'Fruits, Berries, Vegetables Frozen',
        'Pulses Dried',
        'Flour, Rice, Sugar, Baking, Cereal, Dumplings',
        'Bread',
        'Soup',
        'Sauces',
        'Spices',
        'Dessert, Cookies',
        'Coffee, Tea, Cocoa drink',
        'Local purchases by cash',
        'Miscellaneous',
        // "Additional items, not on the list" is deliberately absent. In the
        // workbook it was a block of blank rows for writing in anything the
        // catalogue missed; seeded, it became 43 nameless entries called
        // "Additional Item" that no one could order and everyone had to scroll
        // past. Anything genuinely missing belongs in a real category, added
        // through "Add item" where it gets a name of its own.
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $i => $name) {
            Category::updateOrCreate(['name' => $name], ['sort' => $i + 1]);
        }

        $this->command?->info('Provision categories: '.Category::count());
    }
}
