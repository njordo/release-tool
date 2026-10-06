<?php

use Financialplugins\ReleaseTool\Release;

require __DIR__ . '/vendor/autoload.php';

Release::ensurePharWritable($argv);

new Release($argv);
