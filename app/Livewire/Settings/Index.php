<?php

namespace App\Livewire\Settings;

use App\Actions\Data\ClearTemporaryFiles;
use App\Actions\Data\ExportAllData;
use App\Actions\Data\ResetApplication;
use App\Actions\Groups\RefreshMemberCounts;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\Group;
use App\Models\WhatsAppSession;
use App\Services\WhatsApp\WhatsAppSessionManager;
use App\Services\WhatsApp\WorkerUnavailableException;
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

    public const DISCONNECT_MODAL = 'confirm-disconnect-whatsapp';

    /** section => [label, icon, description] */
    public const SECTIONS = [
        'whatsapp' => ['WhatsApp Connection', 'message-circle', 'Connection status of this computer’s WhatsApp Web session'],
        'sync' => ['WhatsApp Sync', 'refresh-cw', 'What “Sync from WhatsApp” imports into Group Manager'],
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
        'sync_scope' => ['educationhub.whatsapp.sync.scope', 'sync'],
        'sync_skip_phone_numbers' => ['educationhub.whatsapp.sync.skip_phone_numbers', 'sync'],
        'sync_status' => ['educationhub.whatsapp.sync.status', 'sync'],
        'sync_category_id' => ['educationhub.whatsapp.sync.category_id', 'sync'],
        'sync_fetch_member_counts' => ['educationhub.whatsapp.sync.fetch_member_counts', 'sync'],
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

    // ---- WhatsApp connection ------------------------------------------------

    public function connectWhatsApp(WhatsAppSessionManager $sessions): void
    {
        try {
            $session = $sessions->connect();
        } catch (WorkerUnavailableException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        } finally {
            unset($this->session);
            $this->dispatch('whatsapp-status-changed');
        }

        $this->dispatch('toast', ...match (true) {
            $session->isConnected() => ['type' => 'success', 'message' => 'WhatsApp connected'],
            default => ['type' => 'info', 'message' => 'WhatsApp Web is opening. Scan the QR code with your phone.'],
        });
    }

    public function disconnectWhatsApp(WhatsAppSessionManager $sessions): void
    {
        $this->dispatch('close-modal', self::DISCONNECT_MODAL);

        try {
            $sessions->disconnect();
        } catch (WorkerUnavailableException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        } finally {
            unset($this->session);
            $this->dispatch('whatsapp-status-changed');
        }

        $this->dispatch('toast', type: 'success', message: 'WhatsApp disconnected');
    }

    public function refreshSession(WhatsAppSessionManager $sessions): void
    {
        try {
            $session = $sessions->refresh();
        } catch (WorkerUnavailableException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        } finally {
            unset($this->session);
            $this->dispatch('whatsapp-status-changed');
        }

        $this->dispatch('toast', type: 'success', message: 'Status refreshed: '.$session->status->label());
    }

    // ---- WhatsApp sync -------------------------------------------------------

    /**
     * Read every group's member count from WhatsApp now, without waiting for a sync.
     *
     * Runs in the request rather than on the queue on purpose: the queue's single worker
     * is the sending lane, and a long count refresh there would hold up a campaign.
     */
    public function updateMemberCounts(RefreshMemberCounts $refresh): void
    {
        if (! $this->session->isConnected()) {
            $this->dispatch('toast', type: 'error', message: 'Connect WhatsApp first, then update member counts.');

            return;
        }

        if (Campaign::where('status', CampaignStatus::Sending)->exists()) {
            // One browser, one WhatsApp Web session: reading counts now would fight with
            // the campaign for it, and the worker would refuse anyway.
            $this->dispatch('toast', type: 'error', message: 'A campaign is sending right now. Wait for it to finish, then update member counts.');

            return;
        }

        try {
            $result = $refresh->handle();
        } catch (WorkerUnavailableException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        unset($this->groupCount);

        if ($result['checked'] === 0) {
            $this->dispatch('toast', type: 'info', message: 'There are no groups to update yet.');

            return;
        }

        $this->dispatch('toast', type: $result['updated'] > 0 ? 'success' : 'warning', message: $result['updated'].' of '
            .$result['checked'].' '.str('group')->plural($result['checked']).' updated.'
            .($result['missing'] > 0 ? ' '.$result['missing'].' not found in WhatsApp, left unchanged.' : ''));
    }

    /** Groups on record, for the member-count button's time estimate. */
    #[Computed]
    public function groupCount(): int
    {
        return Group::count();
    }

    /** Health of the two background programs, for the WhatsApp section. */
    #[Computed]
    public function health(): array
    {
        $sessions = app(WhatsAppSessionManager::class);

        return [
            'worker' => $sessions->workerRunning(),
            'queueStalled' => $sessions->queueStalled(),
            'sending' => Campaign::where('status', CampaignStatus::Sending)->first(),
        ];
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
