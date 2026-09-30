<?php

namespace App\Livewire\Layout;

use App\Models\AppNotification;
use Livewire\Attributes\Computed;
use Livewire\Component;

class NotificationBell extends Component
{
    public const LIMIT = 8;

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
