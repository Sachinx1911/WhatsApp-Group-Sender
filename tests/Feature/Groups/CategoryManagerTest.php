<?php

namespace Tests\Feature\Groups;

use App\Livewire\Categories\Manager;
use App\Models\Category;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_admin_can_add_a_category_at_the_end(): void
    {
        Category::factory()->create(['name' => 'MPSC', 'sort_order' => 0]);

        Livewire::test(Manager::class)
            ->set('newName', ' Talathi Bharti ')
            ->set('newColor', '#7C3AED')
            ->call('add')
            ->assertHasNoErrors()
            ->assertDispatched('categories-changed');

        $this->assertDatabaseHas('categories', ['name' => 'Talathi Bharti', 'color' => '#7C3AED', 'sort_order' => 1]);
    }

    public function test_category_names_are_unique_and_colors_come_from_the_palette(): void
    {
        Category::factory()->create(['name' => 'MPSC']);

        Livewire::test(Manager::class)
            ->set('newName', 'MPSC')
            ->set('newColor', 'red; background:url(x)')
            ->call('add')
            ->assertHasErrors(['newName' => 'unique', 'newColor' => 'in']);
    }

    public function test_admin_can_rename_and_recolor(): void
    {
        $category = Category::factory()->create(['name' => 'Police']);

        Livewire::test(Manager::class)
            ->call('edit', $category->id)
            ->set('editName', 'Police Bharti')
            ->set('editColor', '#10B981')
            ->call('update')
            ->assertHasNoErrors()
            ->assertSet('editingId', null);

        $this->assertSame('Police Bharti', $category->fresh()->name);
        $this->assertSame('#10B981', $category->fresh()->color);
    }

    public function test_reorder_moves_a_category_up_or_down(): void
    {
        $a = Category::factory()->create(['name' => 'A', 'sort_order' => 0]);
        $b = Category::factory()->create(['name' => 'B', 'sort_order' => 1]);
        $c = Category::factory()->create(['name' => 'C', 'sort_order' => 2]);

        Livewire::test(Manager::class)->call('move', $c->id, 'up');
        $this->assertSame(['A', 'C', 'B'], Category::ordered()->pluck('name')->all());

        Livewire::test(Manager::class)->call('move', $a->id, 'up'); // already first: no change
        Livewire::test(Manager::class)->call('move', $a->id, 'down');
        $this->assertSame(['C', 'A', 'B'], Category::ordered()->pluck('name')->all());
    }

    public function test_category_with_groups_cannot_be_deleted(): void
    {
        $category = Category::factory()->create(['name' => 'MPSC']);
        Group::factory()->for($category)->create();

        Livewire::test(Manager::class)
            ->call('delete', $category->id)
            ->assertDispatched('toast', type: 'error');

        $this->assertModelExists($category);
    }

    public function test_empty_category_can_be_deleted(): void
    {
        $category = Category::factory()->create(['name' => 'Unused']);

        Livewire::test(Manager::class)->call('delete', $category->id);

        $this->assertModelMissing($category);
    }
}
