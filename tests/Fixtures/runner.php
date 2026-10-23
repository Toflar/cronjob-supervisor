<?php

declare(strict_types=1);

require_once __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Toflar\CronjobSupervisor\BasicCommand;
use Toflar\CronjobSupervisor\Provider\ProviderInterface;
use Toflar\CronjobSupervisor\Provider\WindowsTaskListProvider;
use Toflar\CronjobSupervisor\Supervisor;

$providerClass = $argv[1] ?? '';
$directory = $argv[2] ?? '';
if (!is_a($providerClass, ProviderInterface::class, true) || '' === $directory) {
    throw new InvalidArgumentException('A supported provider class and isolated storage directory are required.');
}

$trace = static function (string $event, int $pid, bool|null $running = null) use ($directory): void {
    (new Filesystem())->appendToFile($directory.'/runner-'.getmypid().'.jsonl', json_encode([
        'time' => microtime(true),
        'event' => $event,
        'pid' => $pid,
        'running' => $running,
    ], JSON_THROW_ON_ERROR)."\n");
};
$provider = new $providerClass();
if ($provider instanceof WindowsTaskListProvider) {
    $provider = new class($trace) extends WindowsTaskListProvider {
        public function __construct(private readonly Closure $trace)
        {
        }

        public function isPidRunning(int $pid): bool
        {
            $running = parent::isPidRunning($pid);
            ($this->trace)('checked', $pid, $running);

            return $running;
        }
    };
}
$supervisor = Supervisor::withProviders($directory, [$provider], 1);
$supervisor->on(Supervisor::EVENT_PROCESS_STARTED, static fn (int $pid) => $trace('started', $pid));
$supervisor->on(Supervisor::EVENT_PROCESS_FINISHED, static fn (int $pid) => $trace('finished', $pid));

foreach (['short' => 1, 'medium' => 2, 'long' => 62] as $identifier => $duration) {
    $supervisor = $supervisor->withCommand(new BasicCommand(
        $identifier,
        2,
        static function () use ($directory, $identifier, $duration, $trace): Process {
            $trace('creating-'.$identifier, getmypid());

            return (new Process([PHP_BINARY, __DIR__.'/worker.php', $directory, $identifier, (string) $duration], env: ['CRONJOB_TEST_RUNNER_PID' => (string) getmypid()], timeout: null))->disableOutput();
        },
    ));
}

$supervisor->supervise();
