<?php namespace AlbrightLabs\CanTheyMakeSnow\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::table('albrightlabs_cantheymakesnow_resorts', function(Blueprint $table) {
            $table->decimal('last_snow_24h_in', 5, 1)->nullable()->after('last_tier');
            $table->decimal('last_snow_48h_in', 5, 1)->nullable()->after('last_snow_24h_in');
            $table->decimal('last_snow_72h_in', 5, 1)->nullable()->after('last_snow_48h_in');
            $table->decimal('last_storm_in', 5, 1)->nullable()->after('last_snow_72h_in');
            $table->timestamp('last_storm_start')->nullable()->after('last_storm_in');
            $table->timestamp('last_storm_end')->nullable()->after('last_storm_start');
            $table->string('time_zone', 64)->nullable()->after('last_storm_end');
        });
    }

    public function down()
    {
        Schema::table('albrightlabs_cantheymakesnow_resorts', function(Blueprint $table) {
            $table->dropColumn(['last_snow_24h_in', 'last_snow_48h_in', 'last_snow_72h_in', 'last_storm_in', 'last_storm_start', 'last_storm_end', 'time_zone']);
        });
    }
};
