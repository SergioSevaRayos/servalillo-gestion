<?php

namespace App\Services;

/**
 * Hash de la clave de desbloqueo de la APK tracker (docs/24).
 *
 * PBKDF2-HMAC-SHA256 y no bcrypt porque la APK tiene que verificar la clave SIN conexión con
 * lo que le mandamos (hash + sal + iteraciones), y PBKDF2 existe igual en PHP y en Dart. Si se
 * cambia algo aquí, hay que cambiarlo también en `mobile/lib/services/pin_verifier.dart`
 * (los dos tests comparten un vector fijo).
 */
class DevicePinHasher
{
    public const ITERATIONS = 100_000;

    public const KEY_LENGTH = 32;

    /** @return array{hash: string, salt: string} hex */
    public function make(string $pin): array
    {
        $salt = bin2hex(random_bytes(16));

        return ['hash' => $this->hash($pin, $salt), 'salt' => $salt];
    }

    public function hash(string $pin, string $salt): string
    {
        return hash_pbkdf2('sha256', $pin, $salt, self::ITERATIONS, self::KEY_LENGTH * 2);
    }

    public function check(string $pin, string $hash, string $salt): bool
    {
        return hash_equals($hash, $this->hash($pin, $salt));
    }
}
