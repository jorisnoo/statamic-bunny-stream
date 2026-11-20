<?php

namespace Noo\BunnyStream\Migration;

use Illuminate\Support\Str;
use Noo\BunnyStream\Assets\Streams;
use Statamic\Data\DataReferenceUpdater;
use Statamic\Facades\Asset;
use Statamic\Fields\Blueprint;
use Statamic\Support\Arr;

class References extends DataReferenceUpdater
{
    private bool $checkPaths = true;
    private array $problems = [];
    private array $unresolved = [];

    // A restored snapshot remains editable even if its video was deleted.
    // Preserve the legacy GUID in the data until an editor replaces or removes it.
    public function lenient(): static
    {
        $this->checkPaths = false;

        return $this;
    }

    public function convert(?Blueprint $blueprint = null): bool
    {
        $this->updated = false;
        $this->problems = $this->unresolved = [];
        $fields = $blueprint
            ? $blueprint->fields()->all()->map(fn ($field) => (clone $field)->setParent($this->item))
            : $this->getTopLevelFields();
        $this->recursivelyUpdateFields($fields);
        if ($this->problems) {
            throw new \RuntimeException(implode("\n", $this->problems));
        }

        return (bool) $this->updated;
    }

    public function unresolved(): array
    {
        return $this->unresolved;
    }

    protected function recursivelyUpdateFields($fields, $dottedPrefix = null)
    {
        foreach ($fields as $field) {
            $path = $dottedPrefix.$field->handle();
            $config = $field->config();
            if (! in_array($field->type(), ['replicator', 'grid', 'group', 'bard'], true)
                && (isset($config['fields']) || isset($config['sets'])) && Definitions::hasMigrated($config)) {
                $this->problems[] = 'Unsupported nested fieldtype '.$field->type().' at '.$path;
                continue;
            }
            if (! $library = $field->get('bunny_stream_migrated')) { continue; }
            if ($field->type() !== 'assets' || $field->get('max_files') !== 1) {
                $this->problems[] = 'Remove conflicting fieldset overrides for '.$path.' before migrating.';
                continue;
            }
            $data = $this->item->data()->all();
            $value = Arr::get($data, $path);
            if ($value === null || $value === '' || $value === []) { continue; }
            if (! is_string($value)) {
                $this->problems[] = 'Expected one video reference at '.$path;
                continue;
            }
            $container = $field->get('container');
            if (! Str::isUuid($value)) {
                if (! $this->checkPaths) { continue; }
                $asset = Asset::find($container.'::'.$value);
                if (! $asset || ! $asset->exists()) {
                    $this->problems[] = 'Missing migrated asset at '.$path.': '.$value;
                }
                continue;
            }
            $matches = [];
            foreach (Streams::assets(true) as $asset) {
                $stream = $asset->get('bunny_stream', []);
                if (in_array($value, array_merge($stream['legacy_guids'] ?? [], [$stream['guid'] ?? null]), true) && ($stream['library_id'] ?? null) === (string) $library) {
                    $matches[$asset->id()] = $asset;
                }
            }
            $matches = array_values($matches);
            if (count($matches) !== 1 || $matches[0]->containerHandle() !== $container || ! $matches[0]->exists()) {
                if ($this->checkPaths) {
                    $this->problems[] = 'Unresolved or ambiguous Bunny reference at '.$path.': '.$value;
                } else {
                    $this->unresolved[$path] = $value;
                }
                continue;
            }
            Arr::set($data, $path, $matches[0]->path());
            $this->item->data($data);
            $this->updated = true;
        }
        $this->updateNestedFieldValues($fields, $dottedPrefix);
    }
}
