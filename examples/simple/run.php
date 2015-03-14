<?php

require __DIR__."/MyApplication.php";

$app = new MyApplication();
$app->startWorkers(10);
