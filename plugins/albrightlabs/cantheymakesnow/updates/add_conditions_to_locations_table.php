<?php namespace AlbrightLabs\CanTheyMakeSnow\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::table('albrightlabs_cantheymakesnow_locations', function(Blueprint $table) {
            $table->decimal('last_wet_bulb', 5, 2)->nullable()->after('last_looked_up_at');
            $table->string('last_tier', 16)->nullable()->after('last_wet_bulb');
        });
    }

    public function down()
    {
        Schema::table('albrightlabs_cantheymakesnow_locations', function(Blueprint $table) {
            $table->dropColumn(['last_wet_bulb', 'last_tier']);
        });
    }
};
