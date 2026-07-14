<?php

namespace App\Console\Commands;

use App\Jobs\AutoFinalizeExportsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Throwable;

class CleanupAutoFinalizeExportJobs extends Command
{
    protected $signature = 'queue:cleanup-auto-finalize
        {--force : Delete matching jobs; without this option the command only reports them}';

    protected $description = 'Remove duplicate AutoFinalizeExportsJob payloads from the Redis queue';

    public function handle(): int
    {
        $connectionName = (string) config('queue.default');
        $connectionConfig = config("queue.connections.{$connectionName}", []);

        if (($connectionConfig['driver'] ?? null) !== 'redis') {
            $this->error("Queue connection [{$connectionName}] does not use Redis.");

            return self::FAILURE;
        }

        $redisConnection = (string) ($connectionConfig['connection'] ?? 'default');
        $queue = (string) ($connectionConfig['queue'] ?? 'default');
        $prefix = "queues:{$queue}";
        $targets = [
            ['key' => $prefix, 'type' => 'list'],
            ['key' => "{$prefix}:delayed", 'type' => 'zset'],
            ['key' => "{$prefix}:reserved", 'type' => 'zset'],
        ];

        try {
            $redis = Redis::connection($redisConnection);
            $matches = [];

            foreach ($targets as $target) {
                $payloads = $target['type'] === 'list'
                    ? $redis->lrange($target['key'], 0, -1)
                    : $redis->zrange($target['key'], 0, -1);

                foreach ($payloads as $payload) {
                    if ($this->isAutoFinalizePayload((string) $payload)) {
                        $matches[] = $target + ['payload' => (string) $payload];
                    }
                }
            }

            if ($matches === []) {
                $this->info('AutoFinalizeExportsJob duplicates were not found.');

                return self::SUCCESS;
            }

            $this->warn(sprintf(
                'Found %d AutoFinalizeExportsJob payload(s) in queue [%s].',
                count($matches),
                $queue
            ));

            if (! $this->option('force')) {
                $this->line('Dry run only. Re-run with --force to delete only these payloads.');

                return self::SUCCESS;
            }

            $removed = 0;
            foreach ($matches as $match) {
                $removed += $match['type'] === 'list'
                    ? (int) $redis->lrem($match['key'], 0, $match['payload'])
                    : (int) $redis->zrem($match['key'], $match['payload']);
            }

            $this->info("Removed {$removed} AutoFinalizeExportsJob payload(s). Other jobs were preserved.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Unable to inspect the Redis queue: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function isAutoFinalizePayload(string $payload): bool
    {
        $data = json_decode($payload, true);
        if (! is_array($data)) {
            return false;
        }

        return ($data['displayName'] ?? null) === AutoFinalizeExportsJob::class
            || ($data['data']['commandName'] ?? null) === AutoFinalizeExportsJob::class;
    }
}
