<?php namespace AlbrightLabs\CanTheyMakeSnow\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::table('albrightlabs_cantheymakesnow_resorts', function(Blueprint $table) {
            $table->tinyInteger('last_sm_hours_48')->unsigned()->nullable()->after('time_zone');
            $table->tinyInteger('last_sm_great_hours_48')->unsigned()->nullable()->after('last_sm_hours_48');
            $table->decimal('last_sm_coldest_wb', 5, 2)->nullable()->after('last_sm_great_hours_48');
            $table->timestamp('last_sm_next_start')->nullable()->after('last_sm_coldest_wb');
        });
    }

    public function down()
    {
        Schema::table('albrightlabs_cantheymakesnow_resorts', function(Blueprint $table) {
            $table->dropColumn(['last_sm_hours_48', 'last_sm_great_hours_48', 'last_sm_coldest_wb', 'last_sm_next_start']);
        });
    }
};
