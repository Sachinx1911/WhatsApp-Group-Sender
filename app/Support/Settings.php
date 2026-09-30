<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Admin-editable settings (docs/MASTER_PROMPT.md §20).
 *
 * Values live in the `settings` table and are applied on top of config('educationhub.*')
 * when the app boots, so the rest of the code simply reads config(). The config file holds
 * the defaults. Only keys listed in rules() can be stored.
 */
class Settings
{
    private const CACHE_KEY = 'settings.values';

    /** Original config value of every key this process has overridden (to restore after a reset). */
    private static array $defaults = [];

    /** @return array<string, array<int, mixed>> config key => validation rules */
    public static function rules(): array
    {
        return [
            // Sending
            'educationhub.sending.delay_seconds' => ['required', 'integer', 'min:5', 'max:300'],
            'educationhub.sending.daily_limit' => ['required', 'integer', 'min:0', 'max:10000'],
            'educationhub.sending.max_groups_per_campaign' => ['required', 'integer', 'min:1', 'max:1000'],
            'educationhub.sending.test_group_id' => ['nullable', 'integer', Rule::exists('groups', 'id')],
            'educationhub.sending.show_progress' => ['boolean'],
            'educationhub.sending.large_selection_threshold' => ['required', 'integer', 'min:1', 'max:1000'],
            // Message
            'educationhub.message.default_type' => ['required', Rule::in(['text', 'image', 'pdf'])],
            'educationhub.message.footer' => ['nullable', 'string', 'max:500'],
            'educationhub.message.auto_add_footer' => ['boolean'],
            'educationhub.message.link_preview' => ['boolean'],
            'educationhub.message.show_counter' => ['boolean'],
            // Media
            'educationhub.media.max_image_mb' => ['required', 'integer', 'min:1', 'max:16'],
            'educationhub.media.max_pdf_mb' => ['required', 'integer', 'min:1', 'max:100'],
            'educationhub.storage_quota_gb' => ['required', 'integer', 'min:1', 'max:1000'],
            'educationhub.media.compress_images' => ['boolean'],
            'educationhub.media.generate_thumbnails' => ['boolean'],
            'educationhub.media.temp_retention_days' => ['required', 'integer', 'min:1', 'max:30'],
            // Groups
            'educationhub.groups.default_category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'educationhub.groups.show_inactive_in_selector' => ['boolean'],
            'educationhub.groups.remember_selection' => ['boolean'],
            'educationhub.groups.last_selection' => ['array'],
            'educationhub.groups.last_selection.*' => ['integer'],
            'educationhub.groups.search_mode' => ['required', Rule::in(['contains', 'starts_with'])],
            // WhatsApp sync
            'educationhub.whatsapp.sync.scope' => ['required', Rule::in(['groups', 'all'])],
            'educationhub.whatsapp.sync.skip_phone_numbers' => ['boolean'],
            'educationhub.whatsapp.sync.status' => ['required', Rule::in(['inactive', 'active'])],
            'educationhub.whatsapp.sync.category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'educationhub.whatsapp.sync.fetch_member_counts' => ['boolean'],
            // Notifications
            'educationhub.notifications.desktop' => ['boolean'],
            'educationhub.notifications.campaign_completed' => ['boolean'],
            'educationhub.notifications.campaign_failures' => ['boolean'],
            'educationhub.notifications.whatsapp_disconnected' => ['boolean'],
            'educationhub.notifications.queue_stopped' => ['boolean'],
            // Appearance
            'educationhub.appearance.sidebar_collapsed' => ['boolean'],
            'educationhub.appearance.compact_tables' => ['boolean'],
        ];
    }

    /**
     * Apply stored values on top of config. Only stored keys are touched; a key that was
     * stored earlier in this process but has since been reset gets its default back.
     * Long-running workers call this before each group so changes apply immediately.
     * Safe before migrations have run.
     */
    public static function apply(): void
    {
        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::whereIn('key', self::keys())->pluck('value', 'key')->all());
        } catch (Throwable) {
            return; // Database not ready yet (fresh install / migrations): keep the defaults.
        }

        foreach (array_diff_key(self::$defaults, $stored) as $key => $default) {
            config([$key => $default]);
            unset(self::$defaults[$key]);
        }

        foreach ($stored as $key => $value) {
            if (! array_key_exists($key, self::$defaults)) {
                self::$defaults[$key] = config($key);
            }
            config([$key => $value]);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return config($key, $default);
    }

    /**
     * Validate and store several settings at once.
     *
     * @param  array<string, mixed>  $values  config key => value
     *
     * @throws ValidationException
     */
    public static function set(array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::keys()));
        $rules = collect(self::rules())
            ->filter(fn ($rule, $key) => array_key_exists(preg_replace('/\.\*$/', '', $key), $values))
            ->all();

        // Keys are config paths ("educationhub.sending.delay_seconds"); undot so the rules match them.
        Validator::make(Arr::undot($values), $rules)->validate();

        foreach ($values as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => self::cast($key, $value)]);
        }

        self::forget();
        self::apply();
    }

    /** Remove stored values so the config defaults apply again. */
    public static function reset(?array $keys = null): void
    {
        Setting::whereIn('key', $keys ?? self::keys())->delete();

        self::forget();
        self::apply();
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_values(array_filter(array_keys(self::rules()), fn ($k) => ! str_ends_with($k, '.*')));
    }

    private static function cast(string $key, mixed $value): mixed
    {
        $rules = self::rules()[$key];

        return match (true) {
            in_array('boolean', $rules, true) => (bool) $value,
            in_array('integer', $rules, true) => $value === null || $value === '' ? null : (int) $value,
            in_array('array', $rules, true) => array_values(array_map('intval', (array) $value)),
            is_string($value) => trim($value) === '' ? null : trim($value),
            default => $value,
        };
    }
}
