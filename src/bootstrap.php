<?php

$autoloaderPath = realpath( __DIR__ . '/../../../autoload.php' );

if ( is_readable( $autoloaderPath ) ) {
    require $autoloaderPath;
} else {
    require __DIR__ . '/../vendor/autoload.php';
}
