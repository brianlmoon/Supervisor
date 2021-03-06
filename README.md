# Supervisor - Manage a pool of PHP process

The Supervisor class will manage child process by taking a callback to be called after the child is forked. It will restart new children to replace ones that exit.

## Why?

Supervisor is useful for any application that needs to have long running PHP processes.

Modern application development sometimes requires running long running PHP processes. Because Supervisor is PHP, it allows you to fork and manage processes that run existing PHP code without any modification.

One such case is managing Gearman workers. In fact, this project was inspired by how [GearmanManager](https://github.com/brianlmoon/GearmanManager/) manages workers. The plan is to make it the code that handles the process management in future versions of GearmanManager.

## Example

```php
<?php

// Some Worker Class

class SomeWorker
{
    public $keepWorking = true;

    public function doWork()
    {
        while($this->keepWorking) {
            // do stuff
            usleep(500000);
        }
    }
}
```
```php
<?php

require __DIR__."/../../src/Supervisor.php";
require __DIR__."/SomeWorker.php";

use \Moonspot\Supervisor\Supervisor;

class MyApplication
{
    protected $super;

    protected $worker;

    public function __construct()
    {
        $this->super = new Supervisor(
            array($this, "monitor"),
            array($this, "log"),
            array($this, "handleSignal")
        );

    }

    public function startWorkers($count)
    {
        for($x=0; $x<$count; $x++){
            $this->super->addChild(
                array($this, "startWorker"),
                array(),
                3600000
            );
        }

        $this->super->wait();
    }

    public function startWorker()
    {
        $this->worker = new SomeWorker();
        $this->worker->doWork();
    }

    public function handleSignal($signal) {
        $this->worker->keepWorking = false;
    }

    public function monitor()
    {
        // we could call $this->super->stop() or $this->super->restart() here
    }

    public function log($log)
    {
        list($sec, $ms) = explode(".", number_format(microtime(true), 3));
        echo "[".date("Y-m-d H:i:s").".$ms] $log\n";
    }
}
```
```php
<?php

require __DIR__."/MyApplication.php";

$app = new MyApplication();
$app->startWorkers(10);
```