<?php

use Hojabbr\Social\Tests\TestCase;

// Every test gets the container: a driver reads its own config slice, but
// Http::fake() and the manager's singleton binding both need an application.
pest()->extend(TestCase::class)->in('Unit');
