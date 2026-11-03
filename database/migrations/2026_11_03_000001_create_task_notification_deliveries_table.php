<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('task_notification_deliveries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('sender_id');
            $table->unsignedBigInteger('recipient_id');
            $table->string('request_key', 64);
            $table->string('source', 100)->unique();
            $table->unsignedBigInteger('message_id')->nullable()->index();
            $table->string('error_code', 40)->nullable();
            $table->json('receipts')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['request_key', 'recipient_id']);
            $table->index(['task_id', 'sender_id', 'request_key'], 'task_notification_sender_batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_notification_deliveries');
    }
};
