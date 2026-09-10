<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        | Cloudflare R2 (compatible S3). En local no hay credenciales, así que
        | cae automáticamente en el disco "local" (ver más abajo).
        */
        'r2' => env('R2_ACCESS_KEY_ID') ? [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => 'auto',
            'bucket' => env('R2_BUCKET'),
            'endpoint' => env('R2_ENDPOINT'),
            'url' => env('R2_URL'),
            'use_path_style_endpoint' => true,
            'throw' => true,
            'report' => true,
            'visibility' => 'private',
        ] : [
            'driver' => 'local',
            'root' => storage_path('app/private/r2'),
            'throw' => true,
            'report' => true,
            // Sin esto, Flysystem crea ficheros/carpetas en 0600/0700 (solo el dueño). En
            // el VPS el proceso web (www-data) y el de despliegue (deploy) son usuarios
            // distintos que comparten acceso vía ACL de grupo — un directorio 0700 anula
            // esa ACL (el mask se recalcula a "---"), así que cualquiera de los dos que NO
            // haya creado la carpeta se queda sin poder escribir en ella.
            'permissions' => [
                'file' => ['private' => 0660, 'public' => 0664],
                'dir' => ['private' => 0770, 'public' => 0775],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
