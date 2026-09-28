<?php declare(strict_types=1);

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
];

$loader = null;
foreach ($autoloadCandidates as $autoloadFile) {
    if (is_file($autoloadFile)) {
        $loader = require $autoloadFile;
        break;
    }
}

if ($loader === null) {
    fwrite(STDERR, "No Composer autoloader found. Run `composer install` in the plugin or the project root.\n");
    exit(1);
}

$loader->addPsr4('BlueSnap\\', __DIR__ . '/../src/');
$loader->addPsr4('BlueSnap\\Tests\\', __DIR__);
