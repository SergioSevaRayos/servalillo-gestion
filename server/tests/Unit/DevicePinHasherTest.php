<?php

use App\Services\DevicePinHasher;

// Vector compartido con mobile/test/pin_verifier_test.dart: si cambia el algoritmo en un lado,
// los dos tests deben fallar a la vez.
const PIN_VECTOR_SALT = '00112233445566778899aabbccddeeff';

it('verifica la clave correcta y rechaza la incorrecta', function () {
    $hasher = new DevicePinHasher;
    $made = $hasher->make('4821');

    expect($hasher->check('4821', $made['hash'], $made['salt']))->toBeTrue()
        ->and($hasher->check('4822', $made['hash'], $made['salt']))->toBeFalse()
        ->and(strlen($made['hash']))->toBe(64)
        ->and(strlen($made['salt']))->toBe(32);
});

it('produce el vector fijo compartido con la APK', function () {
    expect((new DevicePinHasher)->hash('1234', PIN_VECTOR_SALT))
        ->toBe('d722ea95af8abb45631ab78d40ddc19fab580bc4732cd0a9572ebba118e451c4');
});
