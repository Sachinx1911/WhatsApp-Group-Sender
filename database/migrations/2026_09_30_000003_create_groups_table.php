<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            // Must match the WhatsApp group name exactly; WhatsApp Web finds groups by name.
            $table->string('name')->unique();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('member_count')->nullable();
            $table->string('whatsapp_identifier')->nullable()->unique();
            $table->string('status', 20)->default('active')->index(); // GroupStatus
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
