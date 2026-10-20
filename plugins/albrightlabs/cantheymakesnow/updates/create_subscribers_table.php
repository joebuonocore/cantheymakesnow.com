<?php namespace AlbrightLabs\CanTheyMakeSnow\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::create('albrightlabs_cantheymakesnow_subscribers', function(Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('token', 40)->unique();
            $table->string('units', 1)->default('f');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('last_emailed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('albrightlabs_cantheymakesnow_subscribers');
    }
};
