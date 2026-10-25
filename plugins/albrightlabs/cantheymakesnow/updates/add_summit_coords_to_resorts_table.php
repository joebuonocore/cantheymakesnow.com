<?php namespace AlbrightLabs\CanTheyMakeSnow\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::table('albrightlabs_cantheymakesnow_resorts', function(Blueprint $table) {
            $table->decimal('summit_lat', 9, 6)->nullable()->after('lon');
            $table->decimal('summit_lon', 9, 6)->nullable()->after('summit_lat');
        });
    }

    public function down()
    {
        Schema::table('albrightlabs_cantheymakesnow_resorts', function(Blueprint $table) {
            $table->dropColumn(['summit_lat', 'summit_lon']);
        });
    }
};
