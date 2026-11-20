<?php

namespace Noo\BunnyStream\Migration;

use Facades\Statamic\Fields\FieldRepository as FieldFacade;
use Illuminate\Support\Collection;
use Statamic\Facades\Blink;
use Statamic\Facades\Fieldset as FieldsetFacade;
use Statamic\Fields\Fieldset;
use Statamic\Fields\FieldRepository;
use Statamic\Fields\FieldsetRepository;

/** Resolves imports and individual references against the same in-memory batch. */
class FieldsetOverlay extends FieldsetRepository
{
    private array $converted = [];
    private array $resolving = [];
    private ?\Closure $convert = null;

    private function __construct(private FieldsetRepository $base, private FieldRepository $fields)
    {
        $this->directory = $base->directory;
        $this->hints = $base->hints;
    }

    public static function install(): self
    {
        $overlay = new self(app(FieldsetRepository::class), app(FieldRepository::class));
        app()->instance(FieldsetRepository::class, $overlay);
        app()->instance(FieldRepository::class, new class($overlay, $overlay->fields) extends FieldRepository {
            public function __construct(FieldsetRepository $fieldsets, private FieldRepository $base)
            {
                parent::__construct($fieldsets);
            }

            public function computedDefault(string $key, \Closure $callback): void
            {
                $this->base->computedDefault($key, $callback);
            }

            public function resolveComputedDefault(string $key, mixed $payload = null): mixed
            {
                return $this->base->resolveComputedDefault($key, $payload);
            }
        });
        FieldsetFacade::clearResolvedInstance(FieldsetRepository::class);
        FieldFacade::clearResolvedInstance(FieldRepository::class);
        Blink::flush();

        return $overlay;
    }

    public function uninstall(): void
    {
        app()->instance(FieldsetRepository::class, $this->base);
        app()->instance(FieldRepository::class, $this->fields);
        FieldsetFacade::clearResolvedInstance(FieldsetRepository::class);
        FieldFacade::clearResolvedInstance(FieldRepository::class);
        Blink::flush();
    }

    public static function refresh(): void
    {
        app(FieldsetRepository::class)->fieldsets = [];
        Blink::flush();
        foreach (['blueprints.found', 'blueprints.from-file', 'blueprints.paths-in-namespace'] as $store) {
            Blink::store($store)->flush();
        }
    }

    public function convertUsing(\Closure $convert): void
    {
        $this->convert = $convert;
    }

    public function find(string $handle): ?Fieldset
    {
        $key = str_replace('/', '.', $handle);
        if (isset($this->converted[$key])) {
            return $this->converted[$key];
        }
        if (isset($this->resolving[$key])) {
            throw new \RuntimeException('Circular fieldset reference: '.$handle);
        }
        if (! ($fieldset = parent::find($handle)) || ! $this->convert) {
            return $fieldset;
        }

        $this->resolving[$key] = true;
        try {
            $fieldset = clone $fieldset;
            ($this->convert)($fieldset);
            return $this->converted[$key] = $fieldset;
        } finally {
            unset($this->resolving[$key]);
        }
    }

    public function all(): Collection
    {
        return $this->base->all();
    }
}
