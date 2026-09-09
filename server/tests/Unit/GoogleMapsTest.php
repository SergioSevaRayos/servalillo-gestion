<?php

use App\Support\GoogleMaps;

it('genera un enlace de direcciones a un solo punto, sin origin', function () {
    $url = GoogleMaps::pointUrl(38.169731, -0.841234);

    expect($url)->toBe(
        'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=38.169731,-0.841234'
    )->and($url)->not->toContain('origin=');
});

it('redondea las coordenadas a 6 decimales', function () {
    expect(GoogleMaps::pointUrl(38.16973186, -0.84123499))
        ->toContain('destination=38.169732,-0.841235');
});

it('con dos paradas: destino = la última, la primera es waypoint (origin lo pone el móvil)', function () {
    $url = GoogleMaps::directionsUrl([[38.10, -0.80], [38.20, -0.90]]);

    expect($url)->toBe(
        'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=38.2,-0.9&waypoints=38.1,-0.8'
    );
});

it('con una sola parada: solo destino, sin waypoints', function () {
    expect(GoogleMaps::directionsUrl([[38.20, -0.90]]))->toBe(
        'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=38.2,-0.9'
    );
});

it('con varias paradas: última = destino, resto = waypoints separados por %7C, en orden', function () {
    $url = GoogleMaps::directionsUrl([
        [1.0, 1.0], [2.0, 2.0], [3.0, 3.0], [4.0, 4.0],
    ]);

    expect($url)->toBe(
        'https://www.google.com/maps/dir/?api=1&travelmode=driving'
        .'&destination=4,4&waypoints=1,1%7C2,2%7C3,3'
    );
});

it('corta los waypoints a 9 (destino aparte)', function () {
    $points = [];
    for ($i = 1; $i <= 15; $i++) {
        $points[] = [(float) $i, (float) $i];
    }

    $url = GoogleMaps::directionsUrl($points);

    // 15 puntos → destino = el 15, waypoints = los 9 primeros.
    expect($url)->toContain('&destination=15,15')
        ->and(substr_count($url, '%7C'))->toBe(8) // 9 waypoints -> 8 separadores
        ->and($url)->toContain('waypoints=1,1%7C2,2%7C3,3%7C4,4%7C5,5%7C6,6%7C7,7%7C8,8%7C9,9')
        ->and($url)->not->toContain('10,10');
});

it('sin paradas devuelve la URL base', function () {
    expect(GoogleMaps::directionsUrl([]))
        ->toBe('https://www.google.com/maps/dir/?api=1&travelmode=driving');
});
