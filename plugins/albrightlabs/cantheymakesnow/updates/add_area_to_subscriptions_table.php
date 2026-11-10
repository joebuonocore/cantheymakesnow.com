<?php namespace AlbrightLabs\CanTheyMakeSnow\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::table('albrightlabs_cantheymakesnow_subscriptions', function(Blueprint $table) {
            // Area alerts ("any resort within 200 miles of Albany, NY") have no resort.
            $table->unsignedBigInteger('resort_id')->nullable()->change();
            $table->string('area_label', 120)->nullable()->after('resort_id');
            $table->decimal('area_lat', 5, 2)->nullable()->after('area_label');
            $table->decimal('area_lon', 6, 2)->nullable()->after('area_lat');
            $table->smallInteger('area_radius_mi')->unsigned()->nullable()->after('area_lon');
        });
    }

    public function down()
    {
        Schema::table('albrightlabs_cantheymakesnow_subscriptions', function(Blueprint $table) {
            $table->dropColumn(['area_label', 'area_lat', 'area_lon', 'area_radius_mi']);
        });
    }
};
