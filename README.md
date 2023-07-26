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
use Toflar\CronjobSupervisor\CommandInterface;
use Toflar\CronjobSupervisor\Supervisor;

class SleepCommand implements CommandInterface
{
    public function __construct(private int $sleep, private int $numProcs)
    {
    }

    public function getIdentifier(): string
    {
        return 'sleep ' . $this->sleep;
    }

    public function getNumProcs()
    {
        return $this->numProcs;
    }

    public function startNewProcess(): Process
    {
        $process = (new Process(['sleep' , $this->sleep]));
        $process->start();

        return $process;
    }
}

(new Supervisor(__DIR__ ))
    ->withCommand(new SleepCommand(10, 2))
    ->withCommand(new SleepCommand(20, 4))
    ->supervise()
;
```

3. Configure the minutely cronjob

`* * * * * /path/to/your/php/binary/php runner.php`


That's it. The `Supervisor` will take care that even if your jobs are still running after a minute has passed, only 
ever your maximum number of processes will be created.

**Important:**

Do not expect any support for this library - it's just a quick hack trying to solve some issue for a personal 
project. If it comes in handy for you - go ahead and use it.