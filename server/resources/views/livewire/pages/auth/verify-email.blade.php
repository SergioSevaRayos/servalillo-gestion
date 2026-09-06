<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    /**
     * Send an email verification notification to the user.
     */
    public function sendVerification(): void
    {
        if (Auth::user()->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);

            return;
        }

        Auth::user()->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div>
    <div class="mb-4 text-sm text-slate-500 dark:text-slate-400">
        {{ __('Antes de continuar, verifica tu email haciendo clic en el enlace que te acabamos de enviar. Si no lo has recibido, te enviamos otro con gusto.') }}
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 text-sm font-medium text-emerald-600 dark:text-emerald-400">
            {{ __('Se ha enviado un nuevo enlace de verificación al email indicado.') }}
        </div>
    @endif

    <div class="mt-4 flex items-center justify-between">
        <x-primary-button wire:click="sendVerification">
            {{ __('Reenviar email de verificación') }}
        </x-primary-button>

        <button wire:click="logout" type="submit" class="rounded-md text-sm text-slate-500 underline hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:text-slate-200">
            {{ __('Cerrar sesión') }}
        </button>
    </div>
</div>
