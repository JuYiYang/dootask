<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_report_mail_deliveries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid');
            $table->date('report_date');
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->unique(['userid', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_report_mail_deliveries');
    }
};
