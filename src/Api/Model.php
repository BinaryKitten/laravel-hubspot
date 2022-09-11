<?php

namespace STS\HubSpot\Api;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\ForwardsCalls;
use Illuminate\Support\Traits\Macroable;
use STS\HubSpot\Api\Concerns\HasAssociations;

abstract class Model
{
    use ForwardsCalls, Macroable, HasAssociations;

    protected string $type;

    protected array $endpoints = [];
    protected array $schema = [];

    protected array $payload = [];
    protected array $properties = [];

    public function __construct(array $payload = [])
    {
        $this->payload = $payload;
        $this->properties = Arr::get($payload, 'properties', []);
    }

    public static function factory($type): self
    {
        $class = "STS\\HubSpot\\Crm\\" . Str::ucfirst(Str::singular($type));

        return new $class;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function endpoint($key, $fill = []): string
    {
        return str_replace(
            array_map(fn($key) => "{" . $key . "}", array_keys($fill)),
            array_values($fill),
            $this->endpoints[$key]
        );
    }

    public function builder(): Builder
    {
        return app(Builder::class)->for($this);
    }

    public function __get($key)
    {
        if(in_array($key, ['contacts','companies','deals','tickets'])) {
            return $this->getAssociations($key);
        }

        if($key === "company") {
            return $this->getAssociations('companies')->first();
        }

        if(array_key_exists($key, $this->payload)) {
            return $this->get($key);
        }

        return Arr::get($this->properties, $key);
    }

    public function get($key, $default = null): mixed
    {
        return $this->cast(
            Arr::get($this->payload, $key, $default),
            Arr::get($this->schema, $key, 'string')
        );
    }

    protected function cast($value, $type = "string"): mixed
    {
        return match($type) {
            'int' => (int) $value,
            'datetime' => Carbon::parse($value),
            'array' => (array) $value,
            'string' => (string) $value,
            default => $value
        };
    }

    public function __set($key, $value)
    {
        $this->properties[$key] = $value;
    }

    public function __isset($key)
    {
        return isset($this->properties[$key]);
    }

    public static function __callStatic($method, $parameters)
    {
        return (new static)->$method(...$parameters);
    }

    public function __call($method, $parameters)
    {
        return $this->forwardCallTo($this->builder(), $method, $parameters);
    }
}
