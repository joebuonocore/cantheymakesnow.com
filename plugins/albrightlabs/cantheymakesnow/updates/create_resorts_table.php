<?php namespace AlbrightLabs\CanTheyMakeSnow\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::create('albrightlabs_cantheymakesnow_resorts', function(Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('state', 2)->index();
            $table->decimal('lat', 9, 6);
            $table->decimal('lon', 9, 6);
            $table->integer('base_elev_ft')->unsigned()->nullable();
            $table->integer('summit_elev_ft')->unsigned()->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->integer('lookups')->unsigned()->default(0);
            $table->decimal('last_wet_bulb', 5, 2)->nullable();
            $table->string('last_tier', 16)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('albrightlabs_cantheymakesnow_resorts');
    }
};
