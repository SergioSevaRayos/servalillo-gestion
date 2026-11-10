<?php

use App\Models\ErrorLog;
use App\Support\ErrorLogger;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Livewire\Component;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportFileUploads\MissingFileUploadsTraitException;

it('no registra como error de sistema una subida Livewire fabricada a mano', function () {
    $component = new class extends Component {};

    ErrorLogger::record(new MissingFileUploadsTraitException($component));

    expect(ErrorLog::count())->toBe(0);
});

it('no registra un sondeo a un método Livewire inexistente con prefijo _', function () {
    ErrorLogger::record(new MethodNotFoundException('_xNonExist'));

    expect(ErrorLog::count())->toBe(0);
});

it('sigue registrando un método Livewire inexistente normal (un wire:click mal escrito es un bug nuestro)', function () {
    ErrorLogger::record(new MethodNotFoundException('guardarCliente'));

    expect(ErrorLog::count())->toBe(1);
});

it('sigue registrando excepciones de sistema normales', function () {
    ErrorLogger::record(new RuntimeException('fallo real'));

    expect(ErrorLog::count())->toBe(1);
});

it('responde 400, no 500, a un sondeo Livewire fabricado', function () {
    $handler = app(ExceptionHandler::class);

    $response = $handler->render(request(), new MethodNotFoundException('_xNonExist'));

    expect($response->getStatusCode())->toBe(400);
});
