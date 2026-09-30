<?php

namespace Tests\Feature\Database;

use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Category;
use App\Models\Group;
use App\Models\Media;
use App\Models\MessageTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_names_must_be_unique(): void
    {
        Group::factory()->create(['name' => 'MPSC Batch 01']);

        $this->expectException(QueryException::class);

        Group::factory()->create(['name' => 'MPSC Batch 01']);
    }

    public function test_a_group_can_appear_only_once_per_campaign(): void
    {
        $row = CampaignGroup::factory()->create();

        $this->expectException(QueryException::class);

        CampaignGroup::factory()->create(['campaign_id' => $row->campaign_id, 'group_id' => $row->group_id]);
    }

    public function test_category_with_groups_cannot_be_deleted(): void
    {
        $category = Category::factory()->create();
        Group::factory()->for($category)->create();

        $this->assertFalse($category->isDeletable());

        $this->expectException(QueryException::class);

        $category->delete();
    }

    public function test_empty_category_can_be_deleted(): void
    {
        $category = Category::factory()->create();

        $this->assertTrue($category->isDeletable());
        $category->delete();

        $this->assertModelMissing($category);
    }

    public function test_send_history_survives_deleting_a_group(): void
    {
        $row = CampaignGroup::factory()->sent()->create();
        $name = $row->group_name;

        $row->group->delete();

        $row->refresh();
        $this->assertNull($row->group_id);
        $this->assertSame($name, $row->group_name);
    }

    public function test_deleting_media_keeps_templates_and_campaign_attachment_name(): void
    {
        $media = Media::factory()->pdf()->create(['original_name' => 'Current_Affairs.pdf']);
        $template = MessageTemplate::factory()->create(['attachment_id' => $media->id]);
        $campaign = Campaign::factory()->create(['attachment_id' => $media->id, 'attachment_name' => $media->original_name]);

        $media->delete();

        $this->assertNull($template->refresh()->attachment_id);
        $this->assertNull($campaign->refresh()->attachment_id);
        $this->assertSame('Current_Affairs.pdf', $campaign->attachment_name);
    }

    public function test_deleting_a_campaign_removes_its_rows_and_logs(): void
    {
        $row = CampaignGroup::factory()->sent()->create();
        $campaign = $row->campaign;
        $campaign->sendLogs()->create(['group_name' => $row->group_name, 'status' => 'sent']);

        $campaign->delete();

        $this->assertDatabaseCount('campaign_groups', 0);
        $this->assertDatabaseCount('send_logs', 0);
    }
}
