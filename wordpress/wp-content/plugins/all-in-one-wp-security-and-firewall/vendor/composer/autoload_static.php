<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitdcd47a42ec2cd55d489a6faa9fdc2ce2
{
    public static $prefixLengthsPsr4 = array (
        'I' => 
        array (
            'IPLib\\' => 6,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'IPLib\\' => 
        array (
            0 => __DIR__ . '/..' . '/mlocati/ip-lib/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitdcd47a42ec2cd55d489a6faa9fdc2ce2::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitdcd47a42ec2cd55d489a6faa9fdc2ce2::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitdcd47a42ec2cd55d489a6faa9fdc2ce2::$classMap;

        }, null, ClassLoader::class);
    }
}