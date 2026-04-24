<?php namespace AlbrightLabs\CanTheyMakeSnow\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::table('albrightlabs_cantheymakesnow_locations', function(Blueprint $table) {
            $table->decimal('lat', 9, 6)->nullable()->after('state');
            $table->decimal('lon', 9, 6)->nullable()->after('lat');
            $table->unique(['city', 'state'], 'albrightlabs_cantheymakesnow_locations_city_state_unique');
        });
    }

    public function down()
    {
        Schema::table('albrightlabs_cantheymakesnow_locations', function(Blueprint $table) {
            $table->dropUnique('albrightlabs_cantheymakesnow_locations_city_state_unique');
            $table->dropColumn(['lat', 'lon']);
        });
    }
};
