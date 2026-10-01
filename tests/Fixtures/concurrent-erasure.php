<?php

declare(strict_types=1);

use App\Services\SubjectErasure;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$appId = $argv[1] ?? throw new RuntimeException('App id is required.');
$subject = $argv[2] ?? throw new RuntimeException('Subject is required.');
$cutoff = $argv[3] ?? throw new RuntimeException('Cutoff is required.');

$app->make(SubjectErasure::class)->erase($appId, $subject, CarbonImmutable::parse($cutoff));
