<?php namespace AlbrightLabs\CanTheyMakeSnow\Updates;

use AlbrightLabs\CanTheyMakeSnow\Models\Resort;
use October\Rain\Database\Updates\Seeder;

/**
 * Fills in summit coordinates from seeds/resorts.json (the top of each
 * resort's highest lift, from OpenStreetMap) for resorts that don't have
 * them yet, so summit points set in the backend are never overwritten.
 */
class SeedResortSummits extends Seeder
{
    public function run()
    {
        $resorts = json_decode(file_get_contents(__DIR__ . '/seeds/resorts.json'), true);

        foreach ($resorts as $data) {
            if (!isset($data['summit_lat'], $data['summit_lon'])) {
                continue;
            }
            Resort::where('slug', $data['slug'])
                ->whereNull('summit_lat')
                ->update(['summit_lat' => $data['summit_lat'], 'summit_lon' => $data['summit_lon']]);
        }
    }
}
