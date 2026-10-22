# Cronjob Supervisor

Need to have a number of workers on some server but have no access to any daemon like `supervisord` or the likes but
can configure a minutely cronjob? This library might come in handy for you then.

1. Installation

`composer require toflar/cronjob-supervisor`

2. Create your `runner.php`:

```php
<?php

require_once 'vendor/autoload.php';

use Symfony\Component\Process\Process;
use Toflar\CronjobSupervisor\BasicCommand;
use Toflar\CronjobSupervisor\Supervisor;

    $supervisor = Supervisor::withDefaultProviders('/some/directory/you/want/to/store/your/state');
    
    $supervisor
        ->withCommand(new BasicCommand('sleep 10', 2, function () {
            return new Process(['sleep', '10']);
        }))
        ->withCommand(new BasicCommand('sleep 29', 4, function () {
            return new Process(['sleep', '29']);
        }))
    ->supervise()
;
```

3. Configure the minutely cronjob

`* * * * * /path/to/your/php/binary/php runner.php`


That's it. The `Supervisor` will take care that even if your jobs are still running after a minute has passed, only 
ever your maximum number of processes will be created.

For this to work, it uses multiple providers to check if processes are still running. Currently supported are:

* `ps -p <pid>` [Linux]
* `tasklist /FI PID eq <pid>` [Windows]
* `flock` - [Linux, Windows] 

Which means it runs everywhere (`flock` as the fallback).

### Worker timeouts and output

The supervisor respects each Symfony Process timeout, including idle timeouts. Symfony Process defaults to a
60-second timeout. For workers intended to run indefinitely, explicitly create them with `timeout: null`:

```php
return (new Process(['php', 'worker.php'], timeout: null))->disableOutput();
```

Disable output when it is not needed, or redirect worker logs to a managed destination. Captured output is retained
by Symfony Process for the lifetime of the Process object and can spill to temporary files.

A timed-out worker is stopped without aborting supervision of other workers. Its slot is replaced on the next
supervision tick. Listen to `Supervisor::EVENT_PROCESS_TIMED_OUT` to receive the PID and the Symfony
`ProcessTimedOutException`. The regular `EVENT_PROCESS_FINISHED` event still follows when the worker is collected.

### Batched process checks

Native providers check workers owned by other runners in batches on each supervision tick. Unix uses bounded
`ps` queries and Windows uses one `tasklist` query, instead of starting a command for every PID.
Owned children continue to use Symfony Process directly. Custom providers retain individual PID checks unless
they implement `BatchProviderInterface`. Failed batch queries fall back to individual checks.
