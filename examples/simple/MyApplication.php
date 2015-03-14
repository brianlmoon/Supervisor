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
