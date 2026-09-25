<?php

namespace Noo\BunnyStream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Noo\BunnyStream\Migration\Journal;

class RollbackCommand extends Command
{
    protected $signature = 'bunny-stream:rollback {run}';
    protected $description = 'Restore a migration journal without deleting any video or asset';

    public function handle(): int
    {
        if (! app()->isDownForMaintenance()) { $this->error('Enter maintenance mode and stop content writers before rollback.'); return self::FAILURE; }
        $lock = Cache::lock('bunny-stream:migrate', 3600);
        if (! $lock->get()) { $this->error('Another migration is running.'); return self::FAILURE; }
        try {
            Journal::apply($this->argument('run'), true);
            $this->call('statamic:stache:clear');
            foreach (\Noo\BunnyStream\Assets\Streams::assets() as $asset) { $asset->cacheStore()->forget($asset->metaCacheKey()); }
            if (config('statamic.static_caching.strategy')) { $this->call('statamic:static:clear'); }
            $this->info('Rolled back. Restore matching templates before reopening editing.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } finally { $lock->release(); }
    }
}
