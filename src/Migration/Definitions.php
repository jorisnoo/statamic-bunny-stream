<?php

namespace Noo\BunnyStream\Migration;

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

    public static function convert(array $node, array $handles, string $container, string $library): array
    {
        if (isset($node['handle']) && ($node['field']['type'] ?? null) === 'bunny'
            && (in_array('*', $handles, true) || in_array($node['handle'], $handles, true))) {
            $node['field'] = array_merge($node['field'], [
                'type' => 'assets', 'container' => $container, 'max_files' => 1,
                'bunny_stream_migrated' => $library,
            ]);
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) { $node[$key] = self::convert($value, $handles, $container, $library); }
        }
        return $node;
    }
}
