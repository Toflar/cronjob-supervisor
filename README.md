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

(new Supervisor('/some/directory/you/want/to/store/your/state'))
    ->withCommand(new BasicCommand('sleep 10', 2, function () {
        return new Process(['sleep', '10']);
    }))
    ->withCommand(new BasicCommand('sleep 20', 4, function () {
        return new Process(['sleep', '29']);
    }))
    ->supervise()
;
```

3. Configure the minutely cronjob

`* * * * * /path/to/your/php/binary/php runner.php`


That's it. The `Supervisor` will take care that even if your jobs are still running after a minute has passed, only 
ever your maximum number of processes will be created.

For this to work, it uses `ps -p <pid>` to check for the process details. Windows is currently not supported.