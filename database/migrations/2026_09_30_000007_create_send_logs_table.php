<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('send_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_name');
            $table->string('status', 20);                  // SendStatus
            $table->text('message')->nullable();           // friendly log line
            $table->string('error_type', 40)->nullable();  // SendErrorType
            $table->text('error_message')->nullable();
            $table->text('technical_details')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('send_logs');
    }
};
