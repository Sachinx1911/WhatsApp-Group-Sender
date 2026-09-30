<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('filename');          // random stored name
            $table->string('original_name');     // name shown to the admin (renamable)
            $table->string('path');              // relative to the private local disk
            $table->string('thumbnail_path')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');  // bytes
            $table->string('type', 10)->index(); // MediaType
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            $table->index('original_name');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
