<?php

namespace Database\Seeders\Inventory;

use App\Models\Inventory\Vessel;
use App\Support\Inventory\ProvisionCatalogue;
use Illuminate\Database\Seeder;

/**
 * Put the master provision list on the first vessel.
 *
 * The list itself, and the work of putting it on a ship, live in
 * App\Support\Inventory\ProvisionCatalogue — because a vessel added from the
 * fleet page needs exactly the same thing, and the two must not drift into
 * two slightly different catalogues.
 */
class ProvisionItemSeeder extends Seeder
{
    public function run(): void
    {
        if (! ProvisionCatalogue::exists()) {
            $this->command?->error('provision_items.json not found at '.ProvisionCatalogue::path());

            return;
        }

        $vessel = Vessel::current();
        $result = ProvisionCatalogue::stock($vessel);

        $this->command?->info(
            "{$vessel->name}: {$result['added']} items added, {$result['present']} already present, "
            ."{$result['skipped']} write-in rows skipped."
        );
    }
}
