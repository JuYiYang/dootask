<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_ai_task_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('userid')->index();
            $table->unsignedBigInteger('created_by');
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->string('token_suffix', 6);
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_ai_task_tokens');
    }
};
