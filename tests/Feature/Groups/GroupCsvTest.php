<?php

namespace Tests\Feature\Groups;

use App\Actions\Groups\GroupCsv;
use App\Enums\GroupStatus;
use App\Livewire\Groups\ImportGroups;
use App\Models\Category;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class GroupCsvTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        Category::factory()->create(['name' => 'MPSC', 'sort_order' => 0]);
        Category::factory()->create(['name' => 'Other', 'sort_order' => 1]);
    }

    private function csvFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);

        return $path;
    }

    public function test_parse_marks_new_existing_invalid_and_missing_categories(): void
    {
        Group::factory()->for(Category::firstWhere('name', 'MPSC'))->create(['name' => 'MPSC Batch 01']);

        $rows = app(GroupCsv::class)->parse($this->csvFile(implode("\n", [
            "\xEF\xBB\xBFname,category,member_count,status",
            'mpsc batch 01,MPSC,250,active',        // exists (case-insensitive)
            'MPSC Batch 02,mpsc,,',                 // new, category matched case-insensitively, defaults
            'Talathi Batch 01,Talathi Bharti,120,inactive', // new category
            ',MPSC,10,active',                      // missing name
            'MPSC Batch 02,MPSC,1,active',          // duplicate in file
            'Bad Batch,MPSC,lots,sleeping',         // invalid members + status
            'मराठी बॅच 01,,200,active',              // Marathi name, default category
        ])));

        $this->assertSame(['existing', 'new', 'new', 'invalid', 'invalid', 'invalid', 'new'], array_column($rows, 'state'));
        $this->assertSame('MPSC', $rows[1]['category']);
        $this->assertNull($rows[1]['member_count']);
        $this->assertSame('active', $rows[1]['status']);
        $this->assertTrue($rows[2]['category_missing']);
        $this->assertSame(['Name is missing'], $rows[3]['errors']);
        $this->assertSame(['Duplicate of line 3'], $rows[4]['errors']);
        $this->assertCount(2, $rows[5]['errors']);
        $this->assertSame('मराठी बॅच 01', $rows[6]['name']);
        $this->assertSame('Other', $rows[6]['category']);
    }

    public function test_parse_accepts_semicolons_and_header_aliases(): void
    {
        $rows = app(GroupCsv::class)->parse($this->csvFile("Group Name;Members\r\nMPSC Batch 05;180\r\n"));

        $this->assertSame('MPSC Batch 05', $rows[0]['name']);
        $this->assertSame(180, $rows[0]['member_count']);
    }

    public function test_parse_rejects_unusable_files(): void
    {
        foreach ([
            "category,status\nMPSC,active" => 'header',
            "name\n" => 'no groups',
            "name\n\xE9t\xE9 batch" => 'UTF-8', // Windows-1252 bytes
        ] as $content => $expected) {
            try {
                app(GroupCsv::class)->parse($this->csvFile($content));
                $this->fail("Expected an error mentioning {$expected}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function test_parse_limits_rows(): void
    {
        $content = "name\n".implode("\n", array_map(fn ($i) => "Batch {$i}", range(1, GroupCsv::MAX_ROWS + 1)));

        $this->expectException(InvalidArgumentException::class);

        app(GroupCsv::class)->parse($this->csvFile($content));
    }

    public function test_import_respects_options(): void
    {
        $mpsc = Category::firstWhere('name', 'MPSC');
        $existing = Group::factory()->for($mpsc)->create(['name' => 'MPSC Batch 01', 'member_count' => 10]);
        $csv = app(GroupCsv::class);
        $rows = $csv->parse($this->csvFile("name,category,member_count,status\nMPSC Batch 01,MPSC,250,inactive\nTalathi 01,Talathi Bharti,50,active\nNew MPSC,MPSC,,active\n,x,1,active"));

        $summary = $csv->import($rows, createCategories: false, updateExisting: false);

        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 3, 'categories_created' => 0], $summary);
        $this->assertSame(10, $existing->fresh()->member_count);
        $this->assertFalse(Category::where('name', 'Talathi Bharti')->exists());

        $summary = $csv->import($rows, createCategories: true, updateExisting: true);

        $this->assertSame(1, $summary['categories_created']);
        $this->assertSame(250, $existing->fresh()->member_count);
        $this->assertSame(GroupStatus::Inactive, $existing->fresh()->status);
        $this->assertTrue(Group::firstWhere('name', 'Talathi 01')->category->name === 'Talathi Bharti');
    }

    public function test_import_modal_previews_then_imports(): void
    {
        $file = UploadedFile::fake()->createWithContent('groups.csv', "name,category,member_count,status\nMPSC Batch 20,MPSC,210,active\nPolice Batch 09,Police Bharti,190,active");

        Livewire::test(ImportGroups::class)
            ->call('start')
            ->set('file', $file)
            ->assertHasNoErrors()
            ->assertSet('fileName', 'groups.csv')
            ->assertSee('MPSC Batch 20')
            ->assertSee('Police Bharti')
            ->assertSet('rows', fn ($rows) => count($rows) === 2);

        $this->assertSame(0, Group::count()); // preview only

        Livewire::test(ImportGroups::class)
            ->set('file', $file)
            ->call('import')
            ->assertDispatched('group-saved')
            ->assertDispatched('toast');

        $this->assertSame(2, Group::count());
        $this->assertTrue(Category::where('name', 'Police Bharti')->exists());
    }

    public function test_import_modal_shows_file_errors(): void
    {
        Livewire::test(ImportGroups::class)
            ->set('file', UploadedFile::fake()->createWithContent('groups.csv', "category\nMPSC"))
            ->assertHasErrors('file')
            ->assertSet('rows', []);

        Livewire::test(ImportGroups::class)
            ->set('file', UploadedFile::fake()->create('photo.png', 10, 'image/png'))
            ->assertHasErrors('file');
    }

    public function test_export_uses_the_import_format_and_current_filters(): void
    {
        $mpsc = Category::firstWhere('name', 'MPSC');
        $other = Category::firstWhere('name', 'Other');
        Group::factory()->for($mpsc)->create(['name' => 'मराठी बॅच 01', 'member_count' => 200]);
        Group::factory()->for($other)->inactive()->create(['name' => 'Test Group', 'member_count' => null]);

        $all = $this->get(route('groups.export'))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFname,category,member_count,status\n", $all);
        $this->assertStringContainsString('"मराठी बॅच 01",MPSC,200,active', $all);
        $this->assertStringContainsString('"Test Group",Other,,inactive', $all);

        $filtered = $this->get(route('groups.export', ['status' => 'active']))->streamedContent();
        $this->assertStringNotContainsString('Test Group', $filtered);

        // The exported file can be imported again unchanged.
        $rows = app(GroupCsv::class)->parse($this->csvFile($all));
        $this->assertSame(['existing', 'existing'], array_column($rows, 'state'));
    }

    public function test_sample_csv_download(): void
    {
        $content = $this->get(route('groups.import.sample'))->assertOk()->streamedContent();

        $this->assertStringContainsString('name,category,member_count,status', $content);
        $this->assertCount(4, app(GroupCsv::class)->parse($this->csvFile($content)));
    }

    public function test_csv_routes_require_login(): void
    {
        auth()->logout();

        $this->get(route('groups.export'))->assertRedirect('/login');
        $this->get(route('groups.import.sample'))->assertRedirect('/login');
    }
}
