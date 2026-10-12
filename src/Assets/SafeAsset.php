<?php

namespace Noo\BunnyStream\Assets;

use Statamic\Assets\Asset;

/**
 * Statamic's asset with one data-loss fix.
 *
 * When a stored width, height or duration is null (getID3 can't read it, e.g. a WebM
 * recording without a duration header), Statamic regenerates the .meta file from the
 * asset's in-memory data. Assets built by queries (such as the CP asset listing) haven't
 * loaded that data yet, so the regenerated file was written with `data: {}`, erasing alt
 * text, titles, custom fields and the Bunny stream record. Loading the data first keeps it.
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

        return parent::generateMeta();
    }
}
