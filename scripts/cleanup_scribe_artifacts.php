<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$targets = [
    $root.'/.scribe',
    $root.'/resources/views/scribe',
    $root.'/public/vendor/scribe',
];

/**
 * @param string $path
 */
function removePath(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        removePath($path.DIRECTORY_SEPARATOR.$item);
    }

    @rmdir($path);
}

foreach ($targets as $target) {
    removePath($target);
}

echo "Scribe UI artifacts cleaned.\n";
