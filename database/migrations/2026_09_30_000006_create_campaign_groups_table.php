<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            // Nullable + name snapshot so send history survives deleting a group.
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_name');
            $table->string('status', 20)->default('pending'); // SendStatus
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error_type', 40)->nullable();      // SendErrorType
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // A group can appear only once per campaign (duplicate-send protection).
            $table->unique(['campaign_id', 'group_id']);
            $table->index(['campaign_id', 'status']);
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_groups');
    }
};
