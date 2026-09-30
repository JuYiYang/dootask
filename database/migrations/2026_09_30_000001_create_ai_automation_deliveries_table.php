<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_automation_deliveries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('kind', 16);
            $table->unsignedBigInteger('target_id');
            $table->string('period', 16);
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('msg_id')->nullable();
            $table->text('content')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'target_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_automation_deliveries');
    }
};
