<?php

declare(strict_types=1);

use App\Services\DatasetExporter;
use App\Services\DatasetManager;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$action = $argv[1] ?? throw new RuntimeException('Action is required.');

try {
    if ($action === 'add') {
        $app->make(DatasetManager::class)->add(
            $argv[2] ?? throw new RuntimeException('Dataset id is required.'),
            $argv[3] ?? throw new RuntimeException('Run id is required.'),
        );
        echo 'added';
    } elseif ($action === 'render') {
        $user = User::query()->findOrFail($argv[3] ?? throw new RuntimeException('User id is required.'));
        echo $app->make(DatasetExporter::class)->render(
            $argv[2] ?? throw new RuntimeException('Export id is required.'),
            ActingPrincipal::local('web', $user),
        );
    } else {
        throw new RuntimeException('Unknown action.');
    }
} catch (Throwable $throwable) {
    echo 'blocked:'.$throwable::class;
}
