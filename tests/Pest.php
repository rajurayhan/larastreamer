<?php

declare(strict_types=1);

use Raju\Streamer\Tests\RoutedTestCase;
use Raju\Streamer\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->extend(RoutedTestCase::class)->in('Enabled');
