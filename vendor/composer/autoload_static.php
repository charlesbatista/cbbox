<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInite06f9c27c1aada82546ab7c68e919c5f
{
    public static $classMap = array (
        'CBBox' => __DIR__ . '/../..' . '/src/CBBox.php',
        'CBBox_Helpers' => __DIR__ . '/../..' . '/src/CBBox_Helpers.php',
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->classMap = ComposerStaticInite06f9c27c1aada82546ab7c68e919c5f::$classMap;

        }, null, ClassLoader::class);
    }
}
