<?php

declare(strict_types=1);

return [
    'default' => env('FIREBASE_PROJECT', 'ironlock'),

    'projects' => [
        'ironlock' => [
            'credentials' => env('FIREBASE_CREDENTIALS'),
            // FCM only — no database URL, no storage bucket, no Firebase Auth.
            // IronLock uses MySQL for all data and authentication; local disk for files.
        ],
    ],
];
