<?php namespace AlbrightLabs\CanTheyMakeSnow\Updates;

use AlbrightLabs\CanTheyMakeSnow\Models\Resort;
use October\Rain\Database\Updates\Seeder;

/**
 * Seeds curated US ski resorts from seeds/resorts.json. Coordinates are the
 * base area; correct them in the backend (Locations > Resorts) as needed.
 * Existing slugs are left untouched so backend edits survive a re-seed.
 */
class SeedResorts extends Seeder
{
    public function run()
    {
        $resorts = json_decode(file_get_contents(__DIR__ . '/seeds/resorts.json'), true);

        foreach ($resorts as $data) {
            Resort::firstOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
