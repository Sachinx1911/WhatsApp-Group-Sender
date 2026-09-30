<?php

namespace App\Actions\Groups;

use App\Models\Group;
use App\Services\WhatsApp\PlaywrightWhatsAppService;
use App\Services\WhatsApp\WhatsAppServiceInterface;
use App\Services\WhatsApp\WorkerUnavailableException;
use Illuminate\Support\Collection;

/**
 * Read member counts from WhatsApp and write them to the Members column.
 *
 * WhatsApp only shows a group's member count inside its info panel, so the worker opens
 * every group in turn: budget roughly four seconds each. Used both by "Sync from WhatsApp"
 * and by the manual button in Settings.
 */
class RefreshMemberCounts
{
    /** Roughly how long WhatsApp takes to open one group and show its info panel. */
    public const SECONDS_PER_GROUP = 4;

    /**
     * @param  Collection<int, string>|array<int, string>|null  $names
     *                                                                  Group names to refresh. Null means every group in the database.
     * @return array{updated: int, missing: int, checked: int}
     *
     * @throws WorkerUnavailableException when the worker cannot be reached
     */
    public function handle(Collection|array|null $names = null): array
    {
        $names = $this->namesToCheck($names);

        if ($names->isEmpty()) {
            return ['updated' => 0, 'missing' => 0, 'checked' => 0];
        }

        $whatsapp = app(WhatsAppServiceInterface::class);

        if (! $whatsapp instanceof PlaywrightWhatsAppService) {
            throw WorkerUnavailableException::make('Member counts need the Playwright worker (WHATSAPP_DRIVER=playwright).');
        }

        $counts = $whatsapp->memberCounts($names->all());

        $updated = 0;
        $missing = 0;

        foreach ($counts as $name => $count) {
            if (! is_int($count)) {
                // WhatsApp showed no count for this group. Leave the column as it was: a 0
                // here would read as a real group that nobody is in.
                $missing++;

                continue;
            }

            $updated += Group::where('name', $name)->update(['member_count' => $count]);
        }

        return ['updated' => $updated, 'missing' => $missing, 'checked' => $names->count()];
    }

    /** How long a refresh of this many groups is likely to take, in seconds. */
    public static function estimatedSeconds(int $groups): int
    {
        return $groups * self::SECONDS_PER_GROUP;
    }

    /** @return Collection<int, string> */
    private function namesToCheck(Collection|array|null $names): Collection
    {
        $names = $names === null
            ? Group::orderBy('name')->pluck('name')
            : collect($names);

        return $names->map(fn ($name) => trim((string) $name))->filter()->unique()->values();
    }
}
