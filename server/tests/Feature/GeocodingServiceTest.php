<?php

use App\Services\GeocodingService;
use Illuminate\Support\Facades\Http;

it('devuelve una lista vacía si la búsqueda está en blanco', function () {
    expect(app(GeocodingService::class)->search('   '))->toBe([]);
});

it('manda un User-Agent identificando la app y devuelve label/lat/lng', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            ['display_name' => 'Calle Mayor, Alicante, España', 'lat' => '38.3452', 'lon' => '-0.4815'],
        ]),
    ]);

    $results = app(GeocodingService::class)->search('Calle Mayor Alicante');

    expect($results)->toHaveCount(1);
    expect($results[0]['label'])->toBe('Calle Mayor, Alicante, España');
    expect($results[0]['lat'])->toBe(38.3452);
    expect($results[0]['lng'])->toBe(-0.4815);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'nominatim.openstreetmap.org/search')
            && $request->hasHeader('User-Agent')
            && ! str_contains((string) $request->header('User-Agent')[0], 'GuzzleHttp');
    });
});

it('se traga cualquier fallo de red y devuelve una lista vacía', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(null, 500),
    ]);

    expect(app(GeocodingService::class)->search('cualquier cosa'))->toBe([]);
});
