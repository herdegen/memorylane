<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bascule du cache (CACHE_STORE=database → redis) : recopie les entrées
 * encore valides avec leur durée restante, pour ne pas reperdre les
 * résultats coûteux (géocodage, photos de lieux, fêtes du jour…).
 */
class CopyDatabaseCache extends Command
{
    protected $signature = 'cache:copy-from-database {--to=redis : store de destination}';

    protected $description = 'Copie les entrées valides du cache en base vers un autre store';

    public function handle(): int
    {
        $source = Cache::store('database');
        $target = Cache::store($this->option('to'));
        $prefix = $source->getStore()->getPrefix();
        $copied = 0;

        DB::connection(config('cache.stores.database.connection'))
            ->table(config('cache.stores.database.table', 'cache'))
            ->where('expiration', '>', time())
            ->orderBy('key')
            ->each(function ($row) use ($source, $target, $prefix, &$copied) {
                $key = $prefix === '' ? $row->key : Str::after($row->key, $prefix);
                $value = $source->get($key);
                $ttl = $row->expiration - time();

                if ($value !== null && $ttl > 0) {
                    $target->put($key, $value, $ttl);
                    $copied++;
                }
            });

        $this->info("{$copied} entrée(s) copiée(s) vers « {$this->option('to')} ».");

        return self::SUCCESS;
    }
}
