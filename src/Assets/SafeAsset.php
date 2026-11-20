<?php

namespace Noo\BunnyStream\Assets;

use Statamic\Assets\Asset;
use Statamic\Facades\YAML;

/**
 * Statamic's asset with two data-loss fixes.
 *
 * When a stored width, height or duration is null (getID3 can't read it, e.g. a WebM
 * recording without a duration header, or an upload's empty placeholder), Statamic
 * regenerates the .meta file from the asset's in-memory data. Assets built by queries
 * (such as the CP asset listing) haven't loaded that data yet, so the regenerated file
 * was written with `data: {}`, erasing alt text, titles, custom fields and the Bunny
 * stream record. Loading the data first keeps it. And a Bunny stream record this request
 * did not change is taken from disk, so a copy loaded before a queue worker wrote can't
 * undo that write.
 */
class SafeAsset extends Asset
{
    private bool $generatingMeta = false;

    public function generateMeta()
    {
        if ($this->generatingMeta || ! $this->path() || ! Streams::enabled($this)) {
            return parent::generateMeta();
        }

        $disk = $this->disk();
        if (! $disk->exists($path = $this->metaPath())) {
            return parent::generateMeta();
        }

        $this->generatingMeta = true;
        try {
            if (config('statamic.assets.cache_meta')) {
                $this->hydrate();
                $meta = parent::generateMeta();
                $loaded = $this->getOriginal('data.bunny_stream');
                $stored = ($meta['data']['bunny_stream'] ?? null) === $loaded
                    ? Streams::persisted($this) : null;
            } else {
                // Recalculate file attributes, but still load custom data from the sidecar.
                // getOriginal() calls meta('data') and would re-enter this branch.
                $contents = $disk->get($path);
                $storedData = $contents ? (YAML::parse($contents)['data'] ?? []) : [];
                $meta = parent::generateMeta();
                if ($this->meta === null) {
                    $meta['data'] = array_diff_key(array_replace($storedData, $meta['data']), array_flip($this->removedData));
                }
                $original = $this->getRawOriginal()['data'] ?? null;
                $stored = $storedData['bunny_stream'] ?? null;
                $loaded = is_array($original) ? ($original['bunny_stream'] ?? null) : $stored;
            }

            if (($meta['data']['bunny_stream'] ?? null) === $loaded && (Streams::managed($stored) || Streams::managed($loaded))) {
                $meta['data']['bunny_stream'] = $stored;
            }
        } finally {
            $this->generatingMeta = false;
        }

        return $meta;
    }
}
