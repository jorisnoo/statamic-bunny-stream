<?php

namespace Noo\BunnyStream\Assets;

use Statamic\Assets\Asset;

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
        // Skip brand-new assets: there is nothing to keep, and hydrating would run getID3 twice.
        if (! $this->generatingMeta && ($this->meta !== null || $this->disk()->exists($this->metaPath()))) {
            $this->generatingMeta = true;
            try {
                $this->hydrate();
            } finally {
                $this->generatingMeta = false;
            }
        }

        $meta = parent::generateMeta();
        $loaded = $this->getOriginal('data.bunny_stream');
        if (($meta['data']['bunny_stream'] ?? null) === $loaded && $this->disk()->exists($this->metaPath())) {
            $meta['data']['bunny_stream'] = Streams::persisted($this);
        }

        return $meta;
    }
}
