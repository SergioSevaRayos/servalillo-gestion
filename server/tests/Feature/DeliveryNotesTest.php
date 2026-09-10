<?php

use App\Enums\DeliveryNoteStatus;
use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Jobs\ProcessDeliveryNote;
use App\Livewire\DeliveryNotes\Index;
use App\Mail\DeliveryNoteMail;
use App\Models\DeliveryNote;
use App\Models\Driver;
use App\Models\RouteDay;
use App\Models\RouteStop;
use App\Services\DeliveryNotePdfRenderer;
use App\Services\DeliveryNoteService;
use App\Support\DeliveryChannels\DeliveryChannelManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('r2'));

function completedStop(): RouteStop
{
    $route = RouteDay::factory()->create(['status' => RouteStatus::InProgress, 'route_date' => today()]);

    return RouteStop::factory()->for($route, 'route')->create([
        'status' => RouteStopStatus::Completed,
        'delivered_quantity' => 900,
        'customer_name' => 'Bar Central',
        'customer_tax_id' => 'B12345678',
        'address' => 'C/ Real 1',
        'completed_at' => now(),
    ]);
}

describe('DeliveryNoteService', function () {
    it('crea el albarán con número secuencial, snapshot y lo encola', function () {
        Queue::fake();

        $service = app(DeliveryNoteService::class);
        $a = $service->createForStop(completedStop(), ['channel' => 'physical', 'signer_name' => 'Ana', 'signature' => fakeSignature()]);
        $b = $service->createForStop(completedStop(), ['channel' => 'physical', 'signer_name' => 'Luis', 'signature' => fakeSignature()]);

        expect($a->number)->toBe('ALB-'.now()->year.'-000001')
            ->and($b->number)->toBe('ALB-'.now()->year.'-000002')
            ->and($a->customer_snapshot['name'])->toBe('Bar Central')
            ->and($a->customer_snapshot['tax_id'])->toBe('B12345678')
            ->and($a->status)->toBe(DeliveryNoteStatus::Queued)
            ->and(Storage::disk('r2')->exists($a->signature_path))->toBeTrue();

        Queue::assertPushed(ProcessDeliveryNote::class, 2);
    });

    it('rechaza una firma que no es PNG', function () {
        expect(fn () => app(DeliveryNoteService::class)->createForStop(
            completedStop(),
            ['channel' => 'physical', 'signer_name' => 'Ana', 'signature' => 'data:image/png;base64,'.base64_encode('no soy un png')],
        ))->toThrow(ValidationException::class);
    });

    it('no duplica el albarán de una parada', function () {
        Queue::fake();
        $stop = completedStop();
        $service = app(DeliveryNoteService::class);

        $a = $service->createForStop($stop, ['channel' => 'physical', 'signer_name' => 'Ana', 'signature' => fakeSignature()]);
        $b = $service->createForStop($stop->refresh(), ['channel' => 'physical', 'signer_name' => 'Otra', 'signature' => fakeSignature()]);

        expect($b->id)->toBe($a->id)
            ->and(DeliveryNote::count())->toBe(1);
    });
});

describe('ProcessDeliveryNote job', function () {
    it('genera el PDF y envía el email en el canal email', function () {
        Mail::fake();
        $note = DeliveryNote::factory()->channel('email')->for(completedStop(), 'routeStop')
            ->create(['status' => DeliveryNoteStatus::Queued]);

        (new ProcessDeliveryNote($note))->handle(
            app(DeliveryNotePdfRenderer::class),
            app(DeliveryChannelManager::class),
        );

        $note->refresh();
        expect($note->status)->toBe(DeliveryNoteStatus::Sent)
            ->and($note->pdf_path)->not->toBeNull()
            ->and(Storage::disk('r2')->exists($note->pdf_path))->toBeTrue()
            ->and($note->sent_at)->not->toBeNull();

        Mail::assertSent(DeliveryNoteMail::class, fn ($m) => $m->hasTo($note->recipient_email));
    });

    it('en canal físico genera el PDF pero no envía correo y queda "entregado en mano"', function () {
        Mail::fake();
        $note = DeliveryNote::factory()->channel('physical')->for(completedStop(), 'routeStop')
            ->create(['status' => DeliveryNoteStatus::Queued]);

        (new ProcessDeliveryNote($note))->handle(
            app(DeliveryNotePdfRenderer::class),
            app(DeliveryChannelManager::class),
        );

        expect($note->refresh()->status)->toBe(DeliveryNoteStatus::DeliveredPhysically);
        Mail::assertNothingSent();
    });

    it('failed() deja el albarán en Failed con el motivo (tras agotar reintentos)', function () {
        $note = DeliveryNote::factory()->channel('email')->for(completedStop(), 'routeStop')
            ->create(['status' => DeliveryNoteStatus::Generating]);

        (new ProcessDeliveryNote($note))->failed(new RuntimeException('Servidor SMTP no responde'));

        expect($note->refresh()->status)->toBe(DeliveryNoteStatus::Failed)
            ->and($note->failure_reason)->toContain('SMTP');
    });
});

describe('listado de albaranes', function () {
    it('lo ve el administrador con filtros', function () {
        $this->actingAs(makeUser('administrador'));
        DeliveryNote::factory()->channel('email')->status(DeliveryNoteStatus::Sent)->for(completedStop(), 'routeStop')->create(['customer_snapshot' => ['name' => 'Panadería Sol']]);
        DeliveryNote::factory()->channel('physical')->status(DeliveryNoteStatus::DeliveredPhysically)->for(completedStop(), 'routeStop')->create(['customer_snapshot' => ['name' => 'Taller Luna']]);

        Livewire::test(Index::class)
            ->assertSee('Panadería Sol')
            ->assertSee('Taller Luna')
            ->set('channel', 'physical')
            ->assertSee('Taller Luna')
            ->assertDontSee('Panadería Sol');
    });

    it('un chofer no accede al listado', function () {
        $chofer = makeUser('chofer');
        Driver::factory()->create(['user_id' => $chofer->id]);
        $this->actingAs($chofer)->get('/albaranes')->assertForbidden();
    });

    it('reprocesar vuelve a encolar el albarán', function () {
        Queue::fake();
        $this->actingAs(makeUser('administrador'));
        $note = DeliveryNote::factory()->status(DeliveryNoteStatus::Failed)->for(completedStop(), 'routeStop')->create();

        Livewire::test(Index::class)->call('reprocess', $note->id);

        expect($note->refresh()->status)->toBe(DeliveryNoteStatus::Queued);
        Queue::assertPushed(ProcessDeliveryNote::class);
    });

    it('marcar entregado un albarán físico', function () {
        $admin = makeUser('administrador');
        $this->actingAs($admin);
        $note = DeliveryNote::factory()->channel('physical')->status(DeliveryNoteStatus::Generated)->for(completedStop(), 'routeStop')->create();

        Livewire::test(Index::class)->call('markDelivered', $note->id);

        $note->refresh();
        expect($note->status)->toBe(DeliveryNoteStatus::DeliveredPhysically)
            ->and($note->delivered_by)->toBe($admin->id);
    });
});

describe('descarga del PDF', function () {
    it('el manager descarga; otro chofer no', function () {
        $stop = completedStop();
        $note = DeliveryNote::factory()->for($stop, 'routeStop')->create();

        $this->actingAs(makeUser('administrador'))->get(route('delivery-notes.pdf', $note))->assertOk();

        $intruso = makeUser('chofer');
        Driver::factory()->create(['user_id' => $intruso->id]);
        $this->actingAs($intruso)->get(route('delivery-notes.pdf', $note))->assertForbidden();
    });

    it('el chofer dueño de la parada descarga su albarán', function () {
        $chofer = makeUser('chofer');
        $driver = Driver::factory()->create(['user_id' => $chofer->id]);
        $route = RouteDay::factory()->create(['driver_id' => $driver->id]);
        $stop = RouteStop::factory()->for($route, 'route')->create(['status' => RouteStopStatus::Completed]);
        $note = DeliveryNote::factory()->for($stop, 'routeStop')->create();

        $this->actingAs($chofer)->get(route('delivery-notes.pdf', $note))->assertOk();
    });
});
