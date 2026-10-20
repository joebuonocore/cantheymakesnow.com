<?php namespace AlbrightLabs\CanTheyMakeSnow\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::create('albrightlabs_cantheymakesnow_subscriptions', function(Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('albrightlabs_cantheymakesnow_subscribers')->cascadeOnDelete();
            $table->foreignId('resort_id')->constrained('albrightlabs_cantheymakesnow_resorts')->cascadeOnDelete();
            $table->boolean('snowmaking')->default(true);
            $table->string('snowmaking_min_tier', 16)->default('marginal');
            $table->boolean('powder')->default(true);
            $table->tinyInteger('powder_threshold_in')->unsigned()->default(6);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_snowmaking_start')->nullable();
            $table->timestamp('last_snowmaking_end')->nullable();
            $table->timestamp('last_snowmaking_sent_at')->nullable();
            $table->timestamp('last_powder_start')->nullable();
            $table->timestamp('last_powder_end')->nullable();
            $table->decimal('last_powder_in', 5, 1)->nullable();
            $table->timestamp('last_powder_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['subscriber_id', 'resort_id'], 'ctms_subscriptions_subscriber_resort_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('albrightlabs_cantheymakesnow_subscriptions');
    }
};
