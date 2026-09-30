<?php

namespace App\Actions\Groups;

use App\Enums\GroupStatus;
use App\Livewire\Forms\GroupForm;
use App\Models\Category;
use App\Models\Group;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CSV import/export for groups (docs/MASTER_PROMPT.md §12).
 * Format: name, category, member_count, status — the same for import and export.
 */
class GroupCsv
{
    public const COLUMNS = ['name', 'category', 'member_count', 'status'];

    public const MAX_ROWS = 1000;

    /** Header aliases accepted on import (lowercase). */
    private const HEADER_ALIASES = [
        'name' => 'name', 'group' => 'name', 'group name' => 'name', 'group_name' => 'name',
        'category' => 'category', 'batch' => 'category',
        'member_count' => 'member_count', 'members' => 'member_count', 'member count' => 'member_count',
        'status' => 'status',
    ];

    /**
     * Parse and validate a CSV file into preview rows. Nothing is saved.
     *
     * @return array<int, array{line: int, name: string, category: string, member_count: ?int, status: string, state: string, errors: array<int, string>, category_missing: bool}>
     *
     * @throws InvalidArgumentException with a message that can be shown to the admin
     */
    public function parse(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (! mb_check_encoding($content, 'UTF-8')) {
            throw new InvalidArgumentException('The file is not UTF-8. In Excel, use File → Save As → “CSV UTF-8 (Comma delimited)”.');
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $delimiter = substr_count($lines[0] ?? '', ';') > substr_count($lines[0] ?? '', ',') ? ';' : ',';
        $records = array_map(fn (string $line) => str_getcsv($line, $delimiter, '"', ''), $lines);

        $header = array_map(fn ($h) => self::HEADER_ALIASES[mb_strtolower(trim((string) $h))] ?? null, array_shift($records) ?? []);

        if (! in_array('name', $header, true)) {
            throw new InvalidArgumentException('The first row must be a header with at least a “name” column. Download the sample CSV to see the format.');
        }

        $records = array_filter($records, fn (array $r) => implode('', array_map('trim', $r)) !== '');

        if (count($records) === 0) {
            throw new InvalidArgumentException('The file has a header but no groups.');
        }

        if (count($records) > self::MAX_ROWS) {
            throw new InvalidArgumentException('The file has '.count($records).' rows. Import at most '.self::MAX_ROWS.' groups at a time.');
        }

        $categories = Category::pluck('name')->mapWithKeys(fn ($n) => [mb_strtolower($n) => $n]);
        $existing = Group::pluck('name')->mapWithKeys(fn ($n) => [mb_strtolower($n) => true]);
        $defaultCategory = Category::default()?->name ?? 'Other';
        $seen = [];
        $rows = [];

        foreach ($records as $index => $record) {
            $values = [];
            foreach ($header as $position => $column) {
                if ($column && ! isset($values[$column])) {
                    $values[$column] = trim((string) ($record[$position] ?? ''));
                }
            }

            $rows[] = $this->validateRow($index + 2, $values, $categories, $existing, $seen, $defaultCategory);
        }

        return $rows;
    }

    private function validateRow(int $line, array $values, $categories, $existing, array &$seen, string $defaultCategory): array
    {
        $errors = [];
        $name = $values['name'] ?? '';
        $key = mb_strtolower($name);

        if ($name === '') {
            $errors[] = 'Name is missing';
        } elseif (mb_strlen($name) > 255) {
            $errors[] = 'Name is longer than 255 characters';
        } elseif (isset($seen[$key])) {
            $errors[] = "Duplicate of line {$seen[$key]}";
        } else {
            $seen[$key] = $line;
        }

        $categoryInput = $values['category'] ?? '';
        $category = $categoryInput === '' ? $defaultCategory : ($categories->get(mb_strtolower($categoryInput)) ?? $categoryInput);
        if (mb_strlen($category) > 100) {
            $errors[] = 'Category is longer than 100 characters';
        }

        $members = $values['member_count'] ?? '';
        if ($members !== '' && (! ctype_digit($members) || (int) $members > GroupForm::MAX_MEMBERS)) {
            $errors[] = 'Members must be a whole number from 0 to '.GroupForm::MAX_MEMBERS;
        }

        $status = mb_strtolower($values['status'] ?? '') ?: GroupStatus::Active->value;
        if (! GroupStatus::tryFrom($status)) {
            $errors[] = 'Status must be “active” or “inactive”';
        }

        return [
            'line' => $line,
            'name' => $name,
            'category' => $category,
            'member_count' => $members !== '' && ctype_digit($members) ? (int) $members : null,
            'status' => $status,
            'state' => $errors ? 'invalid' : ($existing->has($key) ? 'existing' : 'new'),
            'errors' => $errors,
            'category_missing' => ! $categories->has(mb_strtolower($category)),
        ];
    }

    /**
     * Save previewed rows.
     *
     * @return array{created: int, updated: int, skipped: int, categories_created: int}
     */
    public function import(array $rows, bool $createCategories, bool $updateExisting): array
    {
        return DB::transaction(function () use ($rows, $createCategories, $updateExisting) {
            $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'categories_created' => 0];
            $nextSort = (int) Category::max('sort_order') + 1;

            // "Existing" is decided by the database, not by a PHP string compare: the unique
            // index on names is utf8mb4_unicode_ci, which also equates accents and some
            // Unicode spellings PHP sees as different. Comparing in PHP let such rows through
            // as "new" and the insert then failed the whole import with a constraint error.
            foreach ($rows as $row) {
                if ($row['state'] === 'invalid') {
                    $summary['skipped']++;

                    continue;
                }

                $category = Category::where('name', $row['category'])->first();

                if (! $category && ! $createCategories) {
                    $summary['skipped']++;

                    continue;
                }

                $group = Group::where('name', $row['name'])->first();

                if ($group && ! $updateExisting) {
                    $summary['skipped']++;

                    continue;
                }

                if (! $category) {
                    $category = Category::create([
                        'name' => $row['category'],
                        'color' => Category::PALETTE[$nextSort % count(Category::PALETTE)],
                        'sort_order' => $nextSort++,
                    ]);
                    $summary['categories_created']++;
                }

                $attributes = ['category_id' => $category->id, 'status' => $row['status']];
                if ($row['member_count'] !== null) {
                    $attributes['member_count'] = $row['member_count'];
                }

                if ($group) {
                    $group->update($attributes);
                    $summary['updated']++;
                } else {
                    Group::create(['name' => $row['name'], ...$attributes]);
                    $summary['created']++;
                }
            }

            return $summary;
        });
    }

    /** Write groups as CSV (UTF-8 with BOM so Excel shows Marathi names correctly). */
    public function write($handle, iterable $groups): void
    {
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::COLUMNS, escape: '');

        foreach ($groups as $group) {
            fputcsv($handle, [$group->name, $group->category?->name, $group->member_count, $group->status->value], escape: '');
        }
    }
}
