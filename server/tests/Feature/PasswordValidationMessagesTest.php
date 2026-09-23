<?php

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/*
| Bug real de producción (2026-09-23): "no puedo crear un usuario" — lang/es/validation.php no
| tenía las claves 'password.letters'/'password.numbers'/'password.uncompromised' (solo
| 'attributes.password' = "contraseña", una clave distinta). AppServiceProvider aplica
| Password::defaults()->uncompromised() en producción — cualquier contraseña de prueba que
| apareciera en una filtración (muy probable con contraseñas de prueba) fallaba la validación
| pero el mensaje se mostraba en crudo ("validation.password.uncompromised"), indistinguible de
| "no hace nada" para un administrador sin conocimientos técnicos. 0 usuarios nuevos creados
| desde el alta inicial (en producción) confirmó que nunca se había creado uno de verdad.
|
| El propio UncompromisedVerifier real llama a la API de HIBP por red — aquí se sustituye por
| uno falso (siempre "comprometida") para no depender de red en los tests.
*/

beforeEach(function () {
    app()->setLocale('es');
});

it('el campo password tiene mensajes de validación en español para letters/numbers/uncompromised', function () {
    foreach (['letters', 'numbers', 'uncompromised'] as $key) {
        // trans() sin sustituir :attribute todavía — solo comprobamos que existe una
        // traducción real y no la clave en crudo (lo que se veía en producción).
        expect(trans("validation.password.{$key}"))->not->toBe("validation.password.{$key}");
    }
});

it('una contraseña filtrada muestra un mensaje legible, no la clave de traducción en crudo', function () {
    app()->bind(UncompromisedVerifier::class, fn () => new class implements UncompromisedVerifier
    {
        public function verify($data)
        {
            return false; // simula que SÍ aparece en una filtración, sin llamar a la red
        }
    });

    $validator = Validator::make(
        ['password' => 'cualquier-cosa123'],
        ['password' => ['required', 'string', Password::min(10)->letters()->numbers()->uncompromised()]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('password'))
        ->not->toBe('validation.password.uncompromised')
        ->and($validator->errors()->first('password'))->toContain('filtración');
});
