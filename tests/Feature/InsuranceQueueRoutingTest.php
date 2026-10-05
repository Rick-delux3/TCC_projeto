<?php

use App\Jobs\ApplyFinalAnalysisTagToLeadLoversJob;
use App\Jobs\ApplyManualLeadResultTagJob;
use App\Jobs\CompleteInsuranceAnalysesBatchJob;
use App\Jobs\RetryLeadLoversOutageJob;
use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\SendAnalysisResultsEmailJob;
use App\Jobs\SendLeadToLeadLoversJob;
use App\Jobs\StartInsuranceAnalysesBatchJob;
use App\Jobs\SyncProviderAnalysisStatusJob;
use App\Jobs\SyncTooAnalysisStatusJob;
use App\Jobs\UpdateLeadOnLeadLoversJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

it('persists each job on its dedicated database queue', function (string $jobClass, array $arguments, string $queue) {
    Http::preventStrayRequests();
    config(['queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite']);
    $job = new $jobClass(...$arguments);
    dispatch($job)->beforeCommit();

    expect(DB::table('jobs')->sole()->queue)->toBe($queue)
        ->and(Queue::connection()->pop('default'))->toBeNull();
    $queued = Queue::connection()->pop($queue);
    expect($queued)->not->toBeNull();
    $queued->delete();
    Http::assertNothingSent();
})->with([
    'start' => [StartInsuranceAnalysesBatchJob::class, [1], 'insurance-analyses'],
    'execute' => [RunProviderAnalysisJob::class, [1, 'attempt'], 'insurance-analyses'],
    'complete' => [CompleteInsuranceAnalysesBatchJob::class, [1, 'attempt'], 'insurance-analyses'],
    'manual consultation' => [SyncProviderAnalysisStatusJob::class, [1, 'attempt'], 'insurance-analyses'],
    'automatic consultation' => [SyncProviderAnalysisStatusJob::class, [1, 'attempt', false, true], 'insurance-analyses'],
    'Too consultation' => [SyncTooAnalysisStatusJob::class, [1, 'attempt'], 'insurance-analyses'],
    'documents' => [SendAnalysisResultsEmailJob::class, [1, 'attempt'], 'insurance-results'],
    'LeadLovers creation' => [SendLeadToLeadLoversJob::class, [1], 'leadlovers'],
    'LeadLovers recovery' => [RetryLeadLoversOutageJob::class, [1], 'leadlovers'],
    'LeadLovers update' => [UpdateLeadOnLeadLoversJob::class, [1], 'leadlovers'],
    'final tag' => [ApplyFinalAnalysisTagToLeadLoversJob::class, [1, 'attempt'], 'leadlovers'],
    'manual tag' => [ApplyManualLeadResultTagJob::class, [1, 'approved', 1], 'leadlovers'],
]);

it('runs four isolated workers concurrently without consuming integration jobs', function () {
    $directory = sys_get_temp_dir().'/insurance-workers-'.bin2hex(random_bytes(12));
    mkdir($directory);
    $database = $directory.'/queue.sqlite';
    touch($database);
    $processes = [];
    config([
        'database.connections.concurrency' => ['driver' => 'sqlite', 'database' => $database, 'busy_timeout' => 10000, 'journal_mode' => 'WAL'],
        'queue.connections.concurrency' => ['driver' => 'database', 'connection' => 'concurrency', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 60, 'after_commit' => false],
    ]);

    try {
        Schema::connection('concurrency')->create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        foreach (range(1, 4) as $id) {
            dispatch((static function () use ($directory, $id): void {
                file_put_contents($directory.'/'.$id.'.started', json_encode(['pid' => getmypid(), 'time' => microtime(true)]));
                $deadline = microtime(true) + 15;
                while (count(glob($directory.'/*.started')) < 4) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Four workers did not overlap.');
                    }
                    usleep(20000);
                }
                usleep($id * 100000);
                file_put_contents($directory.'/'.$id.'.finished', (string) microtime(true));
            })->bindTo(null, null))->onConnection('concurrency')->onQueue('insurance-analyses')->beforeCommit();
        }
        dispatch((static function (): void {
            throw new RuntimeException('The analysis worker consumed an integration job.');
        })->bindTo(null, null))->onConnection('concurrency')->onQueue('leadlovers')->beforeCommit();

        $worker = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
config([
    'database.connections.concurrency' => ['driver' => 'sqlite', 'database' => $argv[1], 'busy_timeout' => 10000, 'journal_mode' => 'WAL'],
    'queue.connections.concurrency' => ['driver' => 'database', 'connection' => 'concurrency', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 60, 'after_commit' => false],
    'queue.failed.driver' => 'null',
]);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Queue::failing(function ($event): void {
    fwrite(STDERR, $event->exception::class.': '.$event->exception->getMessage());
});
$status = $kernel->call('queue:work', ['connection' => 'concurrency', '--queue' => 'insurance-analyses', '--once' => true, '--tries' => 1, '--timeout' => 20, '--sleep' => 0]);
echo $kernel->output();
exit($status);
PHP;

        foreach (range(1, 4) as $id) {
            $process = new Process([PHP_BINARY, '-r', $worker, $database], base_path(), [
                'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/no-config-cache.php',
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => false,
                'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array',
                'BROADCAST_CONNECTION' => 'null', 'LOG_CHANNEL' => 'null',
            ], timeout: 30);
            $process->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
        }

        $starts = collect(glob($directory.'/*.started'))->map(fn (string $path): array => json_decode(file_get_contents($path), true));
        $finishes = collect(glob($directory.'/*.finished'))->map(fn (string $path): float => (float) file_get_contents($path));
        expect($starts)->toHaveCount(4, implode("\n", array_map(fn (Process $process): string => $process->getOutput().$process->getErrorOutput(), $processes)))
            ->and($starts->pluck('pid')->unique())->toHaveCount(4)
            ->and($finishes)->toHaveCount(4)
            ->and($starts->max('time'))->toBeLessThanOrEqual($finishes->min())
            ->and(DB::connection('concurrency')->table('jobs')->pluck('queue')->all())->toBe(['leadlovers']);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        DB::purge('concurrency');
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
});
