<?php

namespace Noo\BunnyStream\Migration;

use Facades\Statamic\Fields\FieldRepository;
use Statamic\Facades\Fieldset;

class Definitions
{
    public static function hasMigrated(array $node): bool
    {
        if (isset($node['bunny_stream_migrated'])) { return true; }
        if (isset($node['import']) || (isset($node['handle']) && is_string($node['field'] ?? null))) {
            foreach ((new \Statamic\Fields\Fields([$node]))->all() as $field) {
                if (self::hasMigrated($field->config())) { return true; }
            }
        }
        foreach ($node as $value) {
            if (is_array($value) && self::hasMigrated($value)) { return true; }
        }
        return false;
    }

    public static function clearImports(array $node): void
    {
        if (isset($node['import'])) {
            \Statamic\Facades\Blink::forget('blueprint-imported-fields-'.md5(json_encode($node)));
        }
        foreach ($node as $value) {
            if (is_array($value)) { self::clearImports($value); }
        }
    }

    public static function convert(array $node, array $handles, string $container, string $library, string $path = ''): array
    {
        $asset = ['type' => 'assets', 'container' => $container, 'max_files' => 1, 'bunny_stream_migrated' => $library];
        $selected = fn ($handle) => in_array('*', $handles, true) || in_array($handle, $handles, true);
        $handle = $node['handle'] ?? null;
        $field = $node['field'] ?? null;
        $keyPath = $path === '' || str_ends_with($path, ':') ? $path : $path.'.';

        if ($handle !== null && is_array($field) && ($field['type'] ?? null) === 'bunny' && $selected($handle)) {
            $node['field'] = array_replace($field, $asset);
        } elseif ($handle !== null && is_string($field) && ! empty($node['config'])) {
            $base = FieldRepository::find($field)?->config() ?? [];
            $node['config'] = self::overrides($node['config'], $base, $selected($handle), $asset, $keyPath.'config');
        } elseif (isset($node['import']) && ! empty($node['config'])) {
            $fields = Fieldset::find($node['import'])?->fields();
            foreach ($node['config'] as $handle => $overrides) {
                if (! is_array($overrides)) { continue; }
                $base = $fields?->get($handle)?->config() ?? [];
                $node['config'][$handle] = self::overrides($overrides, $base, $selected($handle), $asset, $keyPath.'config.'.$handle);
            }
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::convert($value, $handles, $container, $library, $keyPath.$key);
            }
        }

        if ($handle !== null && is_array($node['field'] ?? null)) {
            $config = $node['field'];
            $fieldPath = $keyPath.'field';
            self::assertDefault($config, $config, $fieldPath);
            if ((isset($config['fields']) || isset($config['sets'])) && ! in_array($config['type'] ?? 'text', ['replicator', 'grid', 'group', 'bard'], true)
                && self::hasMigrated($config)) {
                throw new \RuntimeException('Unsupported nested fieldtype at '.$fieldPath.'.type: '.($config['type'] ?? 'text'));
            }
        }

        return $node;
    }

    private static function overrides(array $overrides, array $base, bool $selected, array $asset, string $path): array
    {
        if (($overrides['type'] ?? null) === 'bunny') {
            if (isset($base['bunny_stream_migrated'])) {
                // Keep inheriting the converted definition, including its container and library.
                unset($overrides['type']);
            } elseif ($selected) {
                $overrides = array_replace($overrides, $asset);
            }
        }
        if (isset($base['bunny_stream_migrated'])) {
            foreach (array_keys($asset) as $key) {
                if (array_key_exists($key, $overrides) && $overrides[$key] !== ($base[$key] ?? null)) {
                    throw new \RuntimeException('Conflicting migrated field override at '.$path.'.'.$key.'. Remove it before running this batch.');
                }
            }
        }
        if (isset($overrides['bunny_stream_migrated']) && ! array_key_exists('default', $overrides)
            && ($base['default'] ?? null) !== null && $base['default'] !== '' && $base['default'] !== []) {
            throw new \RuntimeException('Unsupported inherited default at '.$path.'.default. Override it with an empty value or remove the source default before running this batch.');
        }
        self::assertDefault($overrides, array_replace($base, $overrides), $path);

        return $overrides;
    }

    private static function assertDefault(array $config, array $effective, string $path): void
    {
        $default = $config['default'] ?? null;
        if ($default !== null && $default !== '' && $default !== [] && self::hasMigrated($effective)) {
            throw new \RuntimeException('Unsupported default at '.$path.'.default. Resolve or remove it before running this batch.');
        }
    }
}
