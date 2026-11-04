<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sender_id');
            $table->string('request_id', 64);
            $table->string('title', 60);
            $table->string('body', 300);
            $table->timestamp('created_at');
            $table->unique(['sender_id', 'request_id']);
        });
        Schema::create('global_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('notification_id');
            $table->unsignedBigInteger('recipient_id');
            $table->string('recipient_name');
            $table->timestamp('started_at')->nullable();
            $table->json('channels')->nullable();
            $table->json('clients')->nullable();
            $table->unique(['notification_id', 'recipient_id'], 'global_notification_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_notification_deliveries');
        Schema::dropIfExists('global_notifications');
    }
};
