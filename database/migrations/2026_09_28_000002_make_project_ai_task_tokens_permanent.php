<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_ai_task_tokens', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->change();
        });
        DB::table('project_ai_task_tokens')->update(['expires_at' => null]);
    }

    public function down(): void
    {
        DB::table('project_ai_task_tokens')->whereNull('expires_at')->update(['expires_at' => now()->addDays(90)]);
        Schema::table('project_ai_task_tokens', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable(false)->change();
        });
    }
};
