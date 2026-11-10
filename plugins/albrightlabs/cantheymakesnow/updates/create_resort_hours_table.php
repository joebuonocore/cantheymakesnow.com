<?php namespace AlbrightLabs\CanTheyMakeSnow\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::create('albrightlabs_cantheymakesnow_resort_hours', function(Blueprint $table) {
            $table->id();
            $table->foreignId('resort_id')->constrained('albrightlabs_cantheymakesnow_resorts')->cascadeOnDelete();
            $table->timestamp('hour');
            $table->decimal('base_wet_bulb', 5, 2)->nullable();
            $table->decimal('summit_wet_bulb', 5, 2)->nullable();
            $table->decimal('snow_in', 4, 2)->nullable();
            $table->timestamps();
            $table->unique(['resort_id', 'hour'], 'ctms_resort_hours_resort_hour_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('albrightlabs_cantheymakesnow_resort_hours');
    }
};
