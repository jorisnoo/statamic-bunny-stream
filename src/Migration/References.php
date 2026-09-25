<?php

namespace Noo\BunnyStream\Migration;

use Illuminate\Support\Str;
use Noo\BunnyStream\Assets\Streams;
use Statamic\Data\DataReferenceUpdater;
use Statamic\Facades\Asset;
use Statamic\Support\Arr;

class References extends DataReferenceUpdater
{
    public function convert(): bool
    {
        $this->recursivelyUpdateFields($this->getTopLevelFields());
        return (bool) $this->updated;
    }

    protected function recursivelyUpdateFields($fields, $dottedPrefix = null)
    {
        foreach ($fields as $field) {
            $config = $field->config();
            if (! in_array($field->type(), ['replicator', 'grid', 'group', 'bard'], true)
                && (isset($config['fields']) || isset($config['sets'])) && Definitions::hasMigrated($config)) {
                throw new \RuntimeException('Unsupported nested fieldtype '.$field->type().' at '.$field->handle());
            }
            if (! $library = $field->get('bunny_stream_migrated')) { continue; }
            if ($field->type() !== 'assets' || $field->get('max_files') !== 1) {
                throw new \RuntimeException('Remove conflicting fieldset overrides for '.$field->handle().' before migrating.');
            }
            $path = $dottedPrefix.$field->handle();
            $data = $this->item->data()->all();
            $value = Arr::get($data, $path);
            if ($value === null || $value === '' || $value === []) { continue; }
            if (! is_string($value)) { throw new \RuntimeException('Expected one video reference at '.$path); }
            $container = $field->get('container');
            if (! Str::isUuid($value)) {
                $asset = Asset::find($container.'::'.$value);
                if (! $asset || ! $asset->exists()) { throw new \RuntimeException('Missing migrated asset at '.$path.': '.$value); }
                continue;
            }
            $matches = [];
            foreach (Streams::assets() as $asset) {
                $stream = $asset->get('bunny_stream', []);
                if (in_array($value, array_merge($stream['legacy_guids'] ?? [], [$stream['guid'] ?? null]), true) && ($stream['library_id'] ?? null) === (string) $library) {
                    $matches[$asset->id()] = $asset;
                }
            }
            $matches = array_values($matches);
            if (count($matches) !== 1 || $matches[0]->containerHandle() !== $container || ! $matches[0]->exists()) {
                throw new \RuntimeException('Unresolved or ambiguous Bunny reference at '.$path.': '.$value);
            }
            Arr::set($data, $path, $matches[0]->path());
            $this->item->data($data);
            $this->updated = true;
        }
        $this->updateNestedFieldValues($fields, $dottedPrefix);
    }
}
