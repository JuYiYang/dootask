<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_columns', function (Blueprint $table) {
            $table->bigInteger('flow_item_id')->default(0)->comment('拖入列表时关联的工作流状态ID');
        });
    }

    public function down(): void
    {
        Schema::table('project_columns', function (Blueprint $table) {
            $table->dropColumn('flow_item_id');
        });
    }
};
