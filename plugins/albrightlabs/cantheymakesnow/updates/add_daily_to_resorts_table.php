<?php namespace AlbrightLabs\CanTheyMakeSnow\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::table('albrightlabs_cantheymakesnow_resorts', function(Blueprint $table) {
            $table->text('last_daily')->nullable()->after('last_sm_next_start');
        });
    }

    public function down()
    {
        Schema::table('albrightlabs_cantheymakesnow_resorts', function(Blueprint $table) {
            $table->dropColumn('last_daily');
        });
    }
};
