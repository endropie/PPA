<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateDeliveryOrdersTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->increments('id');
            $table->string('number');
            $table->string('numrev')->nullable();
            $table->enum('transaction', ['REGULER', 'RETURN']);
            $table->date('date')->nullable();
            $table->time('time')->nullable();
            $table->date('due_date')->nullable();
            $table->time('due_time')->nullable();

            $table->integer('customer_id');
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('customer_address')->nullable();

            // $table->integer('transport_id')->nullable();
            $table->string('transport_number')->nullable();
            $table->integer('transport_rate')->nullable();
            $table->integer('operator_id')->nullable();

            $table->tinyInteger('is_revision')->default(0);
            $table->text('description')->nullable();

            $table->integer('request_order_id')->nullable();
            $table->integer('ship_delivery_id')->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_order_items', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('delivery_order_id');

            $table->integer('item_id');
            $table->integer('unit_id');
            $table->float('unit_rate')->default(1);
            $table->float('quantity');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('delivery_orders');
        Schema::dropIfExists('delivery_order_items');
    }
}