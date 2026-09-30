<?php

namespace App\Livewire\Layout;

use App\Models\AppNotification;
use Livewire\Attributes\Computed;
use Livewire\Component;

class NotificationBell extends Component
{
    public const LIMIT = 8;

    /** Newest notification already known to this page; newer ones may become desktop notifications. */
    public int $lastSeenId = 0;

    public function mount(): void
    {
        $this->lastSeenId = (int) AppNotification::max('id');
    }

    /** Called by the poll: announce notifications created since the last check (Settings → Notifications). */
    public function checkForNew(): void
    {
        $new = AppNotification::where('id', '>', $this->lastSeenId)->orderBy('id')->get();

        if ($new->isEmpty()) {
            return;
        }

        $this->lastSeenId = $new->max('id');

        if (! config('educationhub.notifications.desktop')) {
            return;
        }

        $new->filter(fn (AppNotification $n) => config("educationhub.notifications.{$n->type}"))
            ->take(3)
            ->each(fn (AppNotification $n) => $this->dispatch('desktop-notify', id: $n->id, title: $n->title, body: $n->body, url: $n->url));
    }

    #[Computed]
    public function unreadCount(): int
    {
        return AppNotification::unread()->count();
    }

    #[Computed]
    public function notifications()
    {
        return AppNotification::latest()->limit(self::LIMIT)->get();
    }

    public function markAllRead(): void
    {
        AppNotification::unread()->update(['read_at' => now()]);

        unset($this->unreadCount, $this->notifications);
    }

    public function open(int $id)
    {
        $notification = AppNotification::findOrFail($id);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return $notification->url ? $this->redirect($notification->url) : null;
    }

    public function render()
    {
        return view('livewire.layout.notification-bell');
    }
}
