<?php

use App\Models\Client;
use App\Models\DeliveryType;
use App\Services\ClientImporter;

function writeCsv(string $content): string
{
    $path = sys_get_temp_dir().'/clients-'.uniqid().'.csv';
    file_put_contents($path, $content);

    return $path;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/clients-*.csv') as $f) {
        @unlink($f);
    }
});

it('importa clientes nuevos y actualiza por código externo', function () {
    DeliveryType::factory()->create(['name' => 'Reparto de gasóleo', 'slug' => 'gasoleo']);

    $path = writeCsv(implode("\n", [
        'Codigo;Nombre;CIF;Poblacion;Litros;Periodicidad;Tipo de reparto;Latitud;Longitud',
        'AX-100;Bar Central;B11111111;La Laguna;800;quincenal;gasoleo;28,4636;-16,2518',
        'AX-101;Finca Sol;B22222222;Adeje;2000;30;Reparto de gasóleo;28,12;-16,72',
    ]));

    $r = app(ClientImporter::class)->import($path);

    expect($r['created'])->toBe(2)->and($r['updated'])->toBe(0)
        ->and(Client::count())->toBe(2);

    $bar = Client::firstWhere('external_ref', 'AX-100');
    expect($bar->name)->toBe('Bar Central')
        ->and($bar->city)->toBe('La Laguna')
        ->and((int) $bar->typical_quantity)->toBe(800)
        ->and($bar->frequency_days)->toBe(15)
        ->and($bar->default_delivery_type_id)->not->toBeNull()
        ->and((float) $bar->latitude)->toBe(28.4636);

    // Segunda pasada con un cambio → actualiza, no duplica.
    $path2 = writeCsv(implode("\n", [
        'Codigo;Nombre;Poblacion',
        'AX-100;Bar Central Renovado;Tegueste',
    ]));
    $r2 = app(ClientImporter::class)->import($path2);

    expect($r2['created'])->toBe(0)->and($r2['updated'])->toBe(1)
        ->and(Client::count())->toBe(2)
        ->and($bar->fresh()->name)->toBe('Bar Central Renovado')
        ->and($bar->fresh()->city)->toBe('Tegueste');
});

it('informa de filas con error sin abortar', function () {
    $path = writeCsv(implode("\n", [
        'Codigo,Nombre,Email',
        'AX-1,Cliente OK,ok@example.com',
        'AX-2,,sin-nombre@example.com',
        'AX-3,Cliente Mail Malo,esto-no-es-email',
    ]));

    $r = app(ClientImporter::class)->import($path);

    expect($r['created'])->toBe(1)
        ->and($r['skipped'])->toBe(2)
        ->and($r['errors'])->toHaveCount(2);
});

it('en modo simulación no escribe nada', function () {
    $path = writeCsv("Codigo,Nombre\nAX-9,Cliente Fantasma");

    $r = app(ClientImporter::class)->import($path, dryRun: true);

    expect($r['created'])->toBe(1)
        ->and(Client::count())->toBe(0);
});

it('el comando artisan importa desde un archivo', function () {
    $path = writeCsv("Codigo,Nombre\nAX-5,Desde Comando");

    $this->artisan('clientes:importar', ['archivo' => $path])
        ->assertSuccessful();

    expect(Client::firstWhere('external_ref', 'AX-5')?->name)->toBe('Desde Comando');
});
