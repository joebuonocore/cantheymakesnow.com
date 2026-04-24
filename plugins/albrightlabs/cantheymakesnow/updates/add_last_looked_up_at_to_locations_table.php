<?php namespace AlbrightLabs\CanTheyMakeSnow\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::table('albrightlabs_cantheymakesnow_locations', function(Blueprint $table) {
            $table->timestamp('last_looked_up_at')->nullable()->after('lookups');
        });
    }

    public function down()
    {
        Schema::table('albrightlabs_cantheymakesnow_locations', function(Blueprint $table) {
            $table->dropColumn('last_looked_up_at');
        });
    }
};
