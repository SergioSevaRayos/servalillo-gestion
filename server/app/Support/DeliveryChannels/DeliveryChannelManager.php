<?php

namespace App\Support\DeliveryChannels;

use App\Contracts\DeliveryChannel;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class DeliveryChannelManager
{
    /** @return Collection<string, DeliveryChannel> */
    public function all(): Collection
    {
        return collect(config('delivery.channels'))
            ->map(fn (string $class) => app($class));
    }

    public function get(string $key): DeliveryChannel
    {
        $class = config("delivery.channels.{$key}");

        if (! $class) {
            throw new InvalidArgumentException("Canal de entrega desconocido: {$key}");
        }

        return app($class);
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, config('delivery.channels', []));
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys(config('delivery.channels', []));
    }

    /** Opciones para <select>: ['email' => 'Email', ...] */
    public function options(): array
    {
        return $this->all()->map(fn (DeliveryChannel $c) => $c->label())->all();
    }
}
