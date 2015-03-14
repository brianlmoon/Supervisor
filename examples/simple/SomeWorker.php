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
