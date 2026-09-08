<?php

namespace App\Livewire\Notifications;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Campana de notificaciones del nav (Bloque 12). Anidada dentro del componente Volt de
 * navegación. Refresco por `wire:poll.30s` (coherente con chofer 15s / tablero 45s).
 */
class Bell extends Component
{
    /** @return Collection<int, DatabaseNotification> */
    #[Computed]
    public function items(): Collection
    {
        return auth()->user()->notifications()->latest()->take(12)->get();
    }

    #[Computed]
    public function unreadCount(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    public function markRead(string $id): void
    {
        $notification = auth()->user()->notifications()->whereKey($id)->first();
        abort_unless($notification, 404);

        $notification->markAsRead();
        unset($this->items, $this->unreadCount);

        if ($url = $notification->data['url'] ?? null) {
            $this->redirect($url, navigate: true);
        }
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
        unset($this->items, $this->unreadCount);
    }

    public function deleteOne(string $id): void
    {
        auth()->user()->notifications()->whereKey($id)->delete();
        unset($this->items, $this->unreadCount);
    }

    public function clearAll(): void
    {
        auth()->user()->notifications()->delete();
        unset($this->items, $this->unreadCount);
    }

    public function render()
    {
        return view('livewire.notifications.bell');
    }
}
