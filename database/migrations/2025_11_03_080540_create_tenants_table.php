<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id')->comment('商户名称');
            $table->string('email')->nullable()->after('name')->comment('联系邮箱');
            $table->string('phone')->nullable()->after('email')->comment('联系电话');
            $table->text('address')->nullable()->after('phone')->comment('地址');
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->after('address')->comment('状态');
            $table->text('description')->nullable()->after('status')->comment('描述');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'name',
                'email',
                'phone',
                'address',
                'status',
                'description',
            ]);
        });
    }
};
