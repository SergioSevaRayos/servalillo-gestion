<?php

use App\Enums\SupportStatus;
use App\Livewire\Maintenance\Support;
use App\Models\SupportTicket;
use App\Notifications\SupportTicketReplied;
use App\Notifications\SupportTicketStatusChanged;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('la pestaña y la ruta de soporte son solo para mantenimiento', function () {
    $this->actingAs(makeUser('mantenimiento'))->get('/mantenimiento/soporte')->assertOk();
    $this->actingAs(makeUser('administrador'))->get('/mantenimiento/soporte')->assertForbidden();

    $this->actingAs(makeUser('mantenimiento'))->get('/mantenimiento/auditoria')->assertSee('Soporte');
});

it('mantenimiento ve todas las incidencias y filtra', function () {
    $maint = makeUser('mantenimiento');
    $admin = makeUser('administrador');
    SupportTicket::factory()->create(['user_id' => $admin->id, 'subject' => 'Fallo del PDF', 'category' => 'fallo', 'status' => 'abierto']);
    SupportTicket::factory()->create(['user_id' => $admin->id, 'subject' => 'Nuevo informe', 'category' => 'necesidad', 'status' => 'resuelto']);

    Livewire::actingAs($maint)->test(Support::class)
        ->assertSee('Fallo del PDF')
        ->assertSee('Nuevo informe')
        ->set('status', 'abierto')
        ->assertSee('Fallo del PDF')
        ->assertDontSee('Nuevo informe')
        ->set('status', 'all')
        ->set('category', 'necesidad')
        ->assertSee('Nuevo informe')
        ->assertDontSee('Fallo del PDF');
});

it('responder notifica al creador', function () {
    Notification::fake();
    $maint = makeUser('mantenimiento');
    $admin = makeUser('administrador');
    $ticket = SupportTicket::factory()->create(['user_id' => $admin->id]);

    Livewire::actingAs($maint)->test(Support::class)
        ->call('show', $ticket->id)
        ->set('replyForm.body', 'Lo miramos hoy.')
        ->call('reply')
        ->assertHasNoErrors();

    expect($ticket->replies()->count())->toBe(1);
    Notification::assertSentTo($admin, SupportTicketReplied::class);
});

it('cambiar el estado lo actualiza y notifica al creador con el estado anterior y nuevo', function () {
    Notification::fake();
    $maint = makeUser('mantenimiento');
    $admin = makeUser('administrador');
    $ticket = SupportTicket::factory()->create(['user_id' => $admin->id, 'status' => 'abierto']);

    Livewire::actingAs($maint)->test(Support::class)
        ->call('show', $ticket->id)
        ->call('setStatus', 'resuelto');

    expect($ticket->fresh()->status)->toBe(SupportStatus::Resuelto);

    Notification::assertSentTo($admin, SupportTicketStatusChanged::class, function ($n) {
        return $n->old === SupportStatus::Abierto && $n->new === SupportStatus::Resuelto;
    });
});

it('mantenimiento elimina una incidencia', function () {
    $maint = makeUser('mantenimiento');
    $ticket = SupportTicket::factory()->create(['user_id' => makeUser('administrador')->id]);

    Livewire::actingAs($maint)->test(Support::class)
        ->call('deleteTicket', $ticket->id)
        ->assertDispatched('toast');

    expect(SupportTicket::find($ticket->id))->toBeNull();
});
