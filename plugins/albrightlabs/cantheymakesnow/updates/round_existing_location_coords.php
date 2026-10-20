<?php namespace AlbrightLabs\CanTheyMakeSnow\Updates;

use Db;
use October\Rain\Database\Updates\Migration;

/**
 * Earlier versions stored the first visitor's raw coordinates as a city's
 * map pin, which could be GPS-precise. Blur them to ~10km; each row is
 * replaced with NOAA's city coordinates on its next lookup.
 */
return new class extends Migration
{
    public function up()
    {
        Db::table('albrightlabs_cantheymakesnow_locations')
            ->whereNotNull('lat')
            ->whereNotNull('lon')
            ->update([
                'lat' => Db::raw('ROUND(lat, 1)'),
                'lon' => Db::raw('ROUND(lon, 1)'),
            ]);
    }

    public function down()
    {
        // Precision cannot be restored.
    }
};
