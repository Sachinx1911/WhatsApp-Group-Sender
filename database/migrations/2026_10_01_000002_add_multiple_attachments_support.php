<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Templates and campaigns can carry more than one image or PDF.
 *
 * The existing attachment_id columns stay: they keep meaning "the first attachment" so
 * every screen that shows a single attachment keeps working, and the application writes
 * both. The pivot tables are the full, ordered list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_template_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['message_template_id', 'media_id']);
            $table->index(['message_template_id', 'position']);
        });

        Schema::create('campaign_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            // A campaign is history: keep the row when the media file is deleted later.
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            // Snapshot, so history still reads correctly once the media row is gone.
            $table->string('original_name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['campaign_id', 'position']);
        });

        $this->backfill();
    }

    /** Move the single attachment each row already has into the new lists. */
    private function backfill(): void
    {
        $now = now();

        DB::table('message_templates')->whereNotNull('attachment_id')->orderBy('id')
            ->chunkById(200, function ($templates) use ($now) {
                DB::table('message_template_media')->insertOrIgnore($templates->map(fn ($t) => [
                    'message_template_id' => $t->id,
                    'media_id' => $t->attachment_id,
                    'position' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

        DB::table('campaigns')->whereNotNull('attachment_id')->orderBy('id')
            ->chunkById(200, function ($campaigns) use ($now) {
                DB::table('campaign_media')->insert($campaigns->map(fn ($c) => [
                    'campaign_id' => $c->id,
                    'media_id' => $c->attachment_id,
                    'original_name' => $c->attachment_name ?: 'attachment',
                    'position' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_media');
        Schema::dropIfExists('message_template_media');
    }
};
