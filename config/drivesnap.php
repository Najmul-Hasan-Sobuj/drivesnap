<?php

return [
    'prefix' => 'drivesnap',
    'middleware' => ['web', 'auth'],
    'guard' => null,
    'page' => 'drivesnap/Dashboard',
    'abilities' => [
        'view' => 'viewDriveSnap',
        'create' => 'manageDriveSnap',
        'download' => 'manageDriveSnap',
        'delete' => 'manageDriveSnap',
        'manage' => 'manageDriveSnap',
    ],
    'encryption_key' => env('DRIVESNAP_ENCRYPTION_KEY'),
    'client_id' => env('DRIVESNAP_GOOGLE_CLIENT_ID'),
    'client_secret' => env('DRIVESNAP_GOOGLE_CLIENT_SECRET'),
    'revoke_on_disconnect' => true,
    'queue' => env('DRIVESNAP_QUEUE', 'backups'),
    'backup_binary' => env('DRIVESNAP_DUMP_BINARY', 'mysqldump'),
    'connection' => env('DRIVESNAP_DB_CONNECTION'),
    'daily_at' => '02:15',
    'timezone' => env('APP_TIMEZONE', 'UTC'),
];
