<?php

namespace App\Livewire\Settings;

use App\Actions\Data\ClearTemporaryFiles;
use App\Actions\Data\ExportAllData;
use App\Actions\Data\ResetApplication;
use App\Models\Category;
use App\Models\Group;
use App\Models\WhatsAppSession;
use App\Support\Settings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/** Settings (docs/MASTER_PROMPT.md §20–28). */
#[Title('Settings')]
class Index extends Component
{
    public const RESET_MODAL = 'confirm-reset-application';

    /** section => [label, icon, description] */
    public const SECTIONS = [
        'whatsapp' => ['WhatsApp Connection', 'message-circle', 'Connection status of this computer’s WhatsApp Web session'],
        'sending' => ['Sending Settings', 'send', 'Pace and safety limits for sending'],
        'message' => ['Message Settings', 'message-square-text', 'Footer, link previews and the composer'],
        'media' => ['Media & File Settings', 'image', 'Upload limits, storage and images'],
        'groups' => ['Group Settings', 'users', 'Defaults for groups and the group selector'],
        'notifications' => ['Notifications', 'bell', 'Desktop notifications for important events'],
        'appearance' => ['Appearance', 'palette', 'Layout preferences'],
        'data' => ['Data & Backup', 'database', 'Export, clean up and reset'],
    ];

    /** form field => [config key, section] */
    public const FIELDS = [
        'delay_seconds' => ['educationhub.sending.delay_seconds', 'sending'],
        'daily_limit' => ['educationhub.sending.daily_limit', 'sending'],
        'max_groups_per_campaign' => ['educationhub.sending.max_groups_per_campaign', 'sending'],
        'test_group_id' => ['educationhub.sending.test_group_id', 'sending'],
        'show_progress' => ['educationhub.sending.show_progress', 'sending'],
        'default_type' => ['educationhub.message.default_type', 'message'],
        'footer' => ['educationhub.message.footer', 'message'],
        'auto_add_footer' => ['educationhub.message.auto_add_footer', 'message'],
        'link_preview' => ['educationhub.message.link_preview', 'message'],
        'show_counter' => ['educationhub.message.show_counter', 'message'],
        'max_image_mb' => ['educationhub.media.max_image_mb', 'media'],
        'max_pdf_mb' => ['educationhub.media.max_pdf_mb', 'media'],
        'storage_quota_gb' => ['educationhub.storage_quota_gb', 'media'],
        'compress_images' => ['educationhub.media.compress_images', 'media'],
        'generate_thumbnails' => ['educationhub.media.generate_thumbnails', 'media'],
        'temp_retention_days' => ['educationhub.media.temp_retention_days', 'media'],
        'default_category_id' => ['educationhub.groups.default_category_id', 'groups'],
        'show_inactive_in_selector' => ['educationhub.groups.show_inactive_in_selector', 'groups'],
        'remember_selection' => ['educationhub.groups.remember_selection', 'groups'],
        'large_selection_threshold' => ['educationhub.sending.large_selection_threshold', 'groups'],
        'search_mode' => ['educationhub.groups.search_mode', 'groups'],
        'desktop' => ['educationhub.notifications.desktop', 'notifications'],
        'campaign_completed' => ['educationhub.notifications.campaign_completed', 'notifications'],
        'campaign_failures' => ['educationhub.notifications.campaign_failures', 'notifications'],
        'whatsapp_disconnected' => ['educationhub.notifications.whatsapp_disconnected', 'notifications'],
        'queue_stopped' => ['educationhub.notifications.queue_stopped', 'notifications'],
        'sidebar_collapsed' => ['educationhub.appearance.sidebar_collapsed', 'appearance'],
        'compact_tables' => ['educationhub.appearance.compact_tables', 'appearance'],
    ];

    #[Url(except: 'whatsapp')]
    public string $section = 'whatsapp';

    /** @var array<string, mixed> current form values, keyed by FIELDS */
    public array $s = [];

    public string $resetConfirmation = '';

    public function mount(): void
    {
        if (! array_key_exists($this->section, self::SECTIONS)) {
            $this->section = 'whatsapp';
        }

        $this->load();
    }

    private function load(?string $section = null): void
    {
        foreach (self::FIELDS as $field => [$key, $fieldSection]) {
            if ($section === null || $section === $fieldSection) {
                $this->s[$field] = config($key);
            }
        }
    }

    /** @return array<string, string> field => config key, for one section */
    private function fieldsOf(string $section): array
    {
        return collect(self::FIELDS)->filter(fn ($f) => $f[1] === $section)->map(fn ($f) => $f[0])->all();
    }

    public function save(string $section): void
    {
        $fields = $this->fieldsOf($section);
        $rules = Settings::rules();

        $this->validate(
            collect($fields)->mapWithKeys(fn ($key, $field) => ["s.{$field}" => $rules[$key]])->all(),
            [],
            collect($fields)->mapWithKeys(fn ($key, $field) => ["s.{$field}" => str_replace('_', ' ', $field)])->all(),
        );

        Settings::set(collect($fields)->mapWithKeys(fn ($key, $field) => [$key => $this->s[$field] === '' ? null : $this->s[$field]])->all());
        $this->load($section);

        if ($section === 'appearance') {
            $this->dispatch('appearance-changed', sidebarCollapsed: (bool) $this->s['sidebar_collapsed'], compactTables: (bool) $this->s['compact_tables']);
        }

        $this->dispatch('toast', type: 'success', message: self::SECTIONS[$section][0].' saved');
    }

    public function restoreDefaults(string $section): void
    {
        Settings::reset(array_values($this->fieldsOf($section)));
        $this->load($section);
        $this->resetValidation();

        $this->dispatch('toast', type: 'success', message: self::SECTIONS[$section][0].' restored to defaults');
    }

    // ---- Data & Backup -----------------------------------------------------

    public function exportNow(ExportAllData $export): void
    {
        $path = $export->handle();
        unset($this->backups);

        $this->dispatch('toast', type: 'success', message: 'Backup created: '.basename($path));
    }

    public function deleteBackup(string $name): void
    {
        if (ExportAllData::isValidName($name)) {
            Storage::disk('local')->delete(ExportAllData::DIRECTORY.'/'.$name);
            unset($this->backups);
            $this->dispatch('toast', type: 'success', message: 'Backup deleted');
        }
    }

    public function clearTemporaryFiles(ClearTemporaryFiles $clear): void
    {
        $result = $clear->handle();

        $this->dispatch('toast', type: 'success', message: $result['files']
            ? "Removed {$result['files']} temporary ".str('file')->plural($result['files']).' ('.Number::fileSize($result['bytes'], 1).')'
            : 'No temporary files to remove');
    }

    public function resetApplication(ResetApplication $reset): void
    {
        if (trim($this->resetConfirmation) !== 'RESET') {
            $this->addError('resetConfirmation', 'Type RESET in capital letters to confirm.');

            return;
        }

        try {
            $backup = $reset->handle();
        } catch (RuntimeException $e) {
            $this->dispatch('close-modal', self::RESET_MODAL);
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->reset('resetConfirmation');
        $this->load();
        unset($this->backups);
        $this->dispatch('close-modal', self::RESET_MODAL);
        $this->dispatch('toast', type: 'success', message: 'Application reset. A backup was saved first: '.basename($backup));
    }

    #[Computed]
    public function backups(): array
    {
        return ExportAllData::list();
    }

    #[Computed]
    public function groups()
    {
        return Group::active()->orderBy('name')->get(['id', 'name']);
    }

    #[On('categories-changed')]
    public function refreshCategories(): void
    {
        unset($this->categories);
    }

    #[Computed]
    public function categories()
    {
        return Category::ordered()->get();
    }

    #[Computed]
    public function session(): WhatsAppSession
    {
        return WhatsAppSession::current();
    }

    public function render()
    {
        return view('livewire.settings.index');
    }
}
