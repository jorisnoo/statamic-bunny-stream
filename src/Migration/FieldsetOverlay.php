<?php

namespace Noo\BunnyStream\Migration;

use Statamic\Facades\Fieldset as FieldsetFacade;
use Statamic\Fields\Fieldset;
use Statamic\Fields\FieldsetRepository;

/**
 * Serves converted fieldsets by exact handle while a migration batch is prepared.
 *
 * Statamic's repository cache reads handles with colon/dot notation, so namespaced
 * (addon::handle) and nested (folder.handle) fieldsets are re-read from disk on every
 * lookup, which would hide in-memory conversions from the blueprints importing them.
 */
class FieldsetOverlay extends FieldsetRepository
{
    private array $converted = [];

    private function __construct(private FieldsetRepository $base)
    {
        $this->directory = $base->directory;
        $this->hints = $base->hints;
    }

    public static function install(): self
    {
        $overlay = new self(app(FieldsetRepository::class));
        app()->instance(FieldsetRepository::class, $overlay);
        FieldsetFacade::clearResolvedInstance(FieldsetRepository::class);

        return $overlay;
    }

    public function uninstall(): void
    {
        app()->instance(FieldsetRepository::class, $this->base);
        FieldsetFacade::clearResolvedInstance(FieldsetRepository::class);
    }

    public function remember(Fieldset $fieldset): void
    {
        $this->converted[$this->key($fieldset->handle())] = $fieldset;
    }

    public function find(string $handle): ?Fieldset
    {
        return $this->converted[$this->key($handle)] ?? parent::find($handle);
    }

    private function key(string $handle): string
    {
        return str_replace('/', '.', $handle);
    }
}
