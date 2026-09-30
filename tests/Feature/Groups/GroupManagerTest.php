<?php

namespace Tests\Feature\Groups;

use App\Enums\GroupStatus;
use App\Enums\SendErrorType;
use App\Enums\SendStatus;
use App\Livewire\Groups\EditGroup;
use App\Livewire\Groups\Index;
use App\Livewire\Groups\Show;
use App\Models\CampaignGroup;
use App\Models\Category;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GroupManagerTest extends TestCase
{
    use RefreshDatabase;

    private Category $mpsc;

    private Category $police;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->actingAs(User::factory()->create());
        $this->mpsc = Category::factory()->create(['name' => 'MPSC', 'sort_order' => 0]);
        $this->police = Category::factory()->create(['name' => 'Police Bharti', 'sort_order' => 1]);
    }

    public function test_page_renders_with_stats(): void
    {
        Group::factory()->for($this->mpsc)->count(3)->create();
        Group::factory()->for($this->mpsc)->inactive()->create();

        $this->get('/groups')
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSee('Manage your WhatsApp student groups');

        $this->assertSame(['total' => 4, 'active' => 3, 'inactive' => 1], Livewire::test(Index::class)->instance()->stats);
    }

    public function test_filters_change_the_query(): void
    {
        Group::factory()->for($this->mpsc)->create(['name' => 'MPSC Batch 01', 'member_count' => 250]);
        Group::factory()->for($this->mpsc)->inactive()->create(['name' => 'MPSC Batch 02', 'member_count' => 90]);
        Group::factory()->for($this->police)->create(['name' => 'Police Batch 01', 'member_count' => 200]);

        Livewire::test(Index::class)
            ->set('search', 'police')->assertSee('Police Batch 01')->assertDontSee('MPSC Batch 01')
            ->set('search', '')->set('category', (string) $this->mpsc->id)->assertSee('MPSC Batch 02')->assertDontSee('Police Batch 01')
            ->set('status', 'inactive')->assertSee('MPSC Batch 02')->assertDontSee('MPSC Batch 01')
            ->call('clearFilters')
            ->set('minMembers', '150')->set('maxMembers', '220')->assertSee('Police Batch 01')->assertDontSee('MPSC Batch 01');
    }

    public function test_search_from_the_url_is_applied(): void
    {
        Group::factory()->for($this->mpsc)->create(['name' => 'MPSC Batch 01']);
        Group::factory()->for($this->police)->create(['name' => 'Police Batch 01']);

        Livewire::withQueryParams(['search' => 'MPSC Batch 01'])
            ->test(Index::class)
            ->assertSee('MPSC Batch 01')
            ->assertDontSee('Police Batch 01');
    }

    public function test_sorting_and_rows_per_page(): void
    {
        Group::factory()->for($this->mpsc)->create(['name' => 'A group', 'member_count' => 10]);
        Group::factory()->for($this->mpsc)->create(['name' => 'B group', 'member_count' => 300]);

        Livewire::test(Index::class)
            ->assertSeeInOrder(['A group', 'B group'])
            ->call('sortBy', 'member_count')->call('sortBy', 'member_count')
            ->assertSeeInOrder(['B group', 'A group'])
            ->call('sortBy', 'password') // not sortable: ignored
            ->assertSet('sortBy', 'member_count')
            ->set('perPage', 37)
            ->assertSet('perPage', 25);
    }

    public function test_tables_paginate(): void
    {
        Group::factory()->for($this->mpsc)->count(30)->sequence(fn ($s) => ['name' => sprintf('Batch %02d', $s->index + 1)])->create();

        Livewire::test(Index::class)
            ->assertSee('Batch 25')->assertDontSee('Batch 26')
            ->call('nextPage')
            ->assertSee('Batch 30')
            ->set('perPage', 50)
            ->assertSee('Batch 01')->assertSee('Batch 30');
    }

    public function test_admin_can_add_a_group(): void
    {
        Livewire::test(EditGroup::class)
            ->call('create')
            ->set('form.name', '  MPSC बॅच 11  ')
            ->set('form.category_id', $this->mpsc->id)
            ->set('form.member_count', 215)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('group-saved')
            ->assertDispatched('toast');

        $group = Group::sole();
        $this->assertSame('MPSC बॅच 11', $group->name); // trimmed
        $this->assertSame(215, $group->member_count);
        $this->assertSame(GroupStatus::Active, $group->status);
    }

    public function test_group_names_must_be_unique_and_valid(): void
    {
        Group::factory()->for($this->mpsc)->create(['name' => 'MPSC Batch 01']);

        Livewire::test(EditGroup::class)
            ->call('create')
            ->set('form.name', 'MPSC Batch 01')
            ->set('form.member_count', 99999)
            ->call('save')
            ->assertHasErrors(['form.name' => 'unique', 'form.member_count' => 'max'])
            ->set('form.name', '')
            ->call('save')
            ->assertHasErrors(['form.name' => 'required']);

        $this->assertSame(1, Group::count());
    }

    public function test_admin_can_edit_a_group_and_keep_its_own_name(): void
    {
        $group = Group::factory()->for($this->mpsc)->create(['name' => 'MPSC Batch 01']);

        Livewire::test(EditGroup::class)
            ->call('edit', $group->id)
            ->assertSet('form.name', 'MPSC Batch 01')
            ->set('form.category_id', $this->police->id)
            ->set('form.status', 'inactive')
            ->set('form.whatsapp_identifier', '1203630@g.us')
            ->call('save')
            ->assertHasNoErrors();

        $group->refresh();
        $this->assertTrue($group->category->is($this->police));
        $this->assertSame(GroupStatus::Inactive, $group->status);
        $this->assertSame('1203630@g.us', $group->whatsapp_identifier);
    }

    public function test_toggle_status(): void
    {
        $group = Group::factory()->for($this->mpsc)->create();

        Livewire::test(Index::class)->call('toggleStatus', $group->id);
        $this->assertSame(GroupStatus::Inactive, $group->fresh()->status);

        Livewire::test(Index::class)->call('toggleStatus', $group->id);
        $this->assertSame(GroupStatus::Active, $group->fresh()->status);
    }

    public function test_bulk_status_and_category_on_selected_groups(): void
    {
        [$a, $b, $c] = Group::factory()->for($this->mpsc)->count(3)->create()->all();

        Livewire::test(Index::class)
            ->set('selected', [(string) $a->id, (string) $b->id])
            ->call('bulkSetStatus', 'inactive')
            ->assertSet('selected', [])
            ->set('selected', [(string) $a->id])
            ->set('bulkCategory', (string) $this->police->id)
            ->call('bulkChangeCategory');

        $this->assertSame(GroupStatus::Inactive, $a->fresh()->status);
        $this->assertSame(GroupStatus::Inactive, $b->fresh()->status);
        $this->assertSame(GroupStatus::Active, $c->fresh()->status);
        $this->assertTrue($a->fresh()->category->is($this->police));
        $this->assertTrue($b->fresh()->category->is($this->mpsc));
    }

    public function test_select_all_matching_applies_to_every_filtered_group_across_pages(): void
    {
        Group::factory()->for($this->mpsc)->count(30)->create();
        $police = Group::factory()->for($this->police)->create();

        Livewire::test(Index::class)
            ->set('category', (string) $this->mpsc->id)
            ->set('selectAll', true)
            ->call('bulkSetStatus', 'inactive');

        $this->assertSame(30, Group::where('status', 'inactive')->count());
        $this->assertSame(GroupStatus::Active, $police->fresh()->status);
    }

    public function test_bulk_move_requires_a_category(): void
    {
        $group = Group::factory()->for($this->mpsc)->create();

        Livewire::test(Index::class)
            ->set('selected', [(string) $group->id])
            ->call('bulkChangeCategory')
            ->assertHasErrors('bulkCategory');
    }

    public function test_delete_asks_for_confirmation_and_keeps_history(): void
    {
        $group = Group::factory()->for($this->mpsc)->create(['name' => 'Old Batch']);
        $row = CampaignGroup::factory()->sent()->create(['group_id' => $group->id, 'group_name' => 'Old Batch']);

        Livewire::test(Index::class)
            ->call('confirmDelete', $group->id)
            ->assertDispatched('open-modal')
            ->assertSee('Old Batch');

        $this->assertModelExists($group); // nothing deleted before confirming

        Livewire::test(Index::class)
            ->call('confirmDelete', $group->id)
            ->call('delete')
            ->assertDispatched('toast');

        $this->assertModelMissing($group);
        $this->assertSame('Old Batch', $row->fresh()->group_name);
    }

    public function test_groups_still_being_sent_to_are_not_deleted(): void
    {
        $busy = Group::factory()->for($this->mpsc)->create();
        $idle = Group::factory()->for($this->mpsc)->create();
        CampaignGroup::factory()->create(['group_id' => $busy->id, 'status' => SendStatus::Pending]);

        Livewire::test(Index::class)
            ->set('selected', [(string) $busy->id, (string) $idle->id])
            ->call('confirmDelete')
            ->call('delete')
            ->assertDispatched('toast', type: 'warning');

        $this->assertModelExists($busy);
        $this->assertModelMissing($idle);
    }

    public function test_group_details_page(): void
    {
        $group = Group::factory()->for($this->police)->create(['name' => 'Police Batch 04', 'member_count' => 240]);
        CampaignGroup::factory()->sent()->count(3)->create(['group_id' => $group->id]);
        CampaignGroup::factory()->failed(SendErrorType::NotMember)->create(['group_id' => $group->id]);

        $this->get(route('groups.show', $group))
            ->assertOk()
            ->assertSeeLivewire(Show::class)
            ->assertSee('Police Batch 04')
            ->assertSee('Not linked yet')
            ->assertSee('You are not a member of this group');

        $this->assertSame(['sent' => 3, 'failed' => 1, 'total' => 4], Livewire::test(Show::class, ['group' => $group])->instance()->stats);
    }

    public function test_details_page_can_toggle_status(): void
    {
        $group = Group::factory()->for($this->mpsc)->create();

        Livewire::test(Show::class, ['group' => $group])->call('toggleStatus');

        $this->assertSame(GroupStatus::Inactive, $group->fresh()->status);
    }
}
