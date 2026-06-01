<?php
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'dbname'   => 'nas_media',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],
    'app' => [
        'name'        => 'NAS影库',
        'version'     => '2.0.0',
        'debug'       => false,
        'timezone'    => 'Asia/Shanghai',
        'secret_key'  => 'CHANGE_THIS_TO_A_RANDOM_STRING',
        'cache_dir'   => __DIR__ . '/cache',
        'poster_lang' => 'zh-CN',
    ],
    'session' => [
        'name'            => 'nasmedia_sid',
        'cookie_lifetime' => 0,
        'cookie_path'     => '/',
        'cookie_domain'   => '',
        'cookie_secure'   => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'gc_maxlifetime'  => 14400,
        'regenerate_ttl'  => 1800,
    ],
    'tmdb' => [
        'api_key'  => '',
        'language' => 'zh-CN',
        'base_url' => 'https://api.themoviedb.org/3',
        'img_base' => 'https://image.tmdb.org/t/p/',
    ],
    'video' => [
        'extensions' => ['mp4', 'mkv', 'avi', 'wmv', 'flv', 'mov', 'm4v', 'ts', 'rmvb', 'rm', 'mpg', 'mpeg', 'webm'],
        'subtitle_extensions' => ['srt', 'ass', 'ssa', 'vtt', 'sub'],
    ],
    'scan' => [
        'batch_size'      => 50,
        'auto_metadata'   => true,
        'skip_existing'   => true,
    ],
];
