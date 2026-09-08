<?php

use App\Enums\SupportStatus;
use App\Livewire\Support\Index;
use App\Models\Driver;
use App\Models\SupportTicket;
use App\Notifications\SupportTicketOpened;
use App\Notifications\SupportTicketReplied;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('un administrador abre una incidencia y se notifica a mantenimiento', function () {
    Notification::fake();
    $admin = makeUser('administrador');
    $maint = makeUser('mantenimiento');

    Livewire::actingAs($admin)->test(Index::class)
        ->call('compose')
        ->set('form.subject', 'El PDF sale cortado')
        ->set('form.category', 'fallo')
        ->set('form.body', 'La última fila queda pegada al borde.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal', 'ticket-form');

    $ticket = SupportTicket::first();
    expect($ticket->subject)->toBe('El PDF sale cortado')
        ->and($ticket->user_id)->toBe($admin->id)
        ->and($ticket->status)->toBe(SupportStatus::Abierto);

    Notification::assertSentTo($maint, SupportTicketOpened::class);
});

it('el asunto, la categoría y el cuerpo son obligatorios', function () {
    $admin = makeUser('administrador');

    Livewire::actingAs($admin)->test(Index::class)
        ->call('compose')
        ->call('save')
        ->assertHasErrors(['form.subject', 'form.category', 'form.body']);
});

it('un administrador solo ve sus incidencias', function () {
    $admin = makeUser('administrador');
    $otro = makeUser('administrador');
    $ajena = SupportTicket::factory()->create(['user_id' => $otro->id, 'subject' => 'Incidencia ajena']);
    $propia = SupportTicket::factory()->create(['user_id' => $admin->id, 'subject' => 'Incidencia propia']);

    Livewire::actingAs($admin)->test(Index::class)
        ->assertSee('Incidencia propia')
        ->assertDontSee('Incidencia ajena')
        ->call('select', $ajena->id)
        ->assertStatus(403);
});

it('responder marca last_reply_at y notifica a mantenimiento', function () {
    Notification::fake();
    $admin = makeUser('administrador');
    $maint = makeUser('mantenimiento');
    $ticket = SupportTicket::factory()->create(['user_id' => $admin->id]);

    Livewire::actingAs($admin)->test(Index::class)
        ->call('select', $ticket->id)
        ->set('replyForm.body', 'Añado un detalle más.')
        ->call('reply')
        ->assertHasNoErrors();

    expect($ticket->fresh()->last_reply_at)->not->toBeNull()
        ->and($ticket->replies()->count())->toBe(1);

    Notification::assertSentTo($maint, SupportTicketReplied::class);
});

it('puede editar y borrar su primer mensaje mientras mantenimiento no responde, luego no', function () {
    $admin = makeUser('administrador');
    $maint = makeUser('mantenimiento');
    $ticket = SupportTicket::factory()->create(['user_id' => $admin->id, 'body' => 'Texto original']);

    Livewire::actingAs($admin)->test(Index::class)
        ->call('select', $ticket->id)
        ->call('startEdit')
        ->set('editBody', 'Texto corregido')
        ->call('saveEdit')
        ->assertHasNoErrors();
    expect($ticket->fresh()->body)->toBe('Texto corregido');

    // mantenimiento responde
    $ticket->replies()->create(['user_id' => $maint->id, 'body' => 'Recibido']);

    Livewire::actingAs($admin)->test(Index::class)
        ->call('select', $ticket->id)
        ->call('startEdit')
        ->assertStatus(403);

    Livewire::actingAs($admin)->test(Index::class)
        ->call('deleteTicket', $ticket->id)
        ->assertStatus(403);

    expect($ticket->fresh())->not->toBeNull();
});

it('un chofer no puede entrar en /soporte', function () {
    $chofer = makeUser('chofer');
    Driver::factory()->create(['user_id' => $chofer->id]);

    $this->actingAs($chofer)->get('/soporte')->assertForbidden();
});

it('un administrador entra en /soporte y mantenimiento también', function () {
    $this->actingAs(makeUser('administrador'))->get('/soporte')->assertOk();
    $this->actingAs(makeUser('mantenimiento'))->get('/soporte')->assertOk();
});
