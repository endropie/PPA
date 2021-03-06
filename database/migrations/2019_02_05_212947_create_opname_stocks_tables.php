<?php

use App\Models\Auth\Permission;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOpnameStocksTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {

        Schema::create('opnames', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('number');
            $table->integer('revise_id')->nullable();
            $table->integer('revise_number')->nullable();
            $table->string('status')->default('OPEN');

            $table->bigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('opname_stocks', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->integer('item_id');
            $table->string('stockist');
            $table->decimal('init_amount', 20, 2)->default(0);
            $table->decimal('final_amount', 20, 2)->default(0);

            $table->bigInteger('opname_id')->unsigned();
            $table->bigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('opname_id')
                ->references('id')->on('opnames')
                ->onDelete('CASCADE');
        });

        Schema::create('opname_vouchers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('number');
            $table->integer('item_id');
            $table->integer('unit_id');
            $table->string('stockist');
            $table->decimal('unit_rate', 10, 5)->default(1);
            $table->decimal('quantity', 10, 2);

            $table->string('status')->default('OPEN');

            $table->bigInteger('opname_stock_id')->unsigned()->nullable();
            $table->bigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('opname_stock_id')
                ->references('id')->on('opname_stocks')
                ->onDelete('SET NULL');
        });

        // create Roles & Permissions
        $this->setPermiss();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('opname_vouchers');
        Schema::dropIfExists('opname_stocks');
        Schema::dropIfExists('opnames');
    }

    protected function setPermiss () {
        $OpnameStockRole = Role::firstOrCreate(['name' => 'user.opname.stocks']);
        $OpnameStockRole->givePermissionTo(Permission::firstOrCreate(['name' => "opname-stocks-create"]));
        $OpnameStockRole->givePermissionTo(Permission::firstOrCreate(['name' => "opname-stocks-read"]));
        $OpnameStockRole->givePermissionTo(Permission::firstOrCreate(['name' => "opname-stocks-update"]));
        $OpnameStockRole->givePermissionTo(Permission::firstOrCreate(['name' => "opname-stocks-delete"]));
        Permission::firstOrCreate(['name' => "opname-stocks-void"]);
        Permission::firstOrCreate(['name' => "opname-stocks-validation"]);

        $OpnameVoucherRole = Role::firstOrCreate(['name' => 'user.opname.vouchers']);
        $OpnameVoucherRole->givePermissionTo(Permission::firstOrCreate(['name' => "opname-vouchers-create"]));
        $OpnameVoucherRole->givePermissionTo(Permission::firstOrCreate(['name' => "opname-vouchers-read"]));
        $OpnameVoucherRole->givePermissionTo(Permission::firstOrCreate(['name' => "opname-vouchers-update"]));
        $OpnameVoucherRole->givePermissionTo(Permission::firstOrCreate(['name' => "opname-vouchers-delete"]));
        Permission::firstOrCreate(['name' => "opname-vouchers-void"]);
        Permission::firstOrCreate(['name' => "opname-vouchers-validation"]);

        if ($admin = User::first()) {
            $admin->assignRole($OpnameStockRole);
            $admin->assignRole($OpnameVoucherRole);
            $admin->givePermissionTo('opname-stocks-void');
            $admin->givePermissionTo('opname-stocks-validation');
            $admin->givePermissionTo('opname-vouchers-void');
            $admin->givePermissionTo('opname-vouchers-validation');
        }
    }
}
