<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/*
| MySQL on Aiven, driven entirely by environment variables. The LavaLust
| database kernel verifies Aiven TLS when DB_SSL_CA points to its CA file.
*/
$database['main'] = array(
    'driver'    => getenv('DB_DRIVER')   ?: 'mysql',
    'hostname'  => getenv('DB_HOST')     ?: 'localhost',
    'port'      => getenv('DB_PORT')     ?: '3306',
    'username'  => getenv('DB_USER')     ?: 'avnadmin',
    'password'  => getenv('DB_PASSWORD') ?: '',
    'database'  => getenv('DB_NAME')     ?: 'edusphere',
    'charset'   => getenv('DB_CHARSET')  ?: 'utf8mb4',
    'dbprefix'  => getenv('DB_PREFIX')   ?: '',
    'path'      => ''
);
