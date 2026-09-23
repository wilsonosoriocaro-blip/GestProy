<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Project Codes
    |--------------------------------------------------------------------------
    |
    | When a project is created without a code, one is generated as
    | {prefix}-{year}-{sequence}, e.g. TED-2026-007. The sequence restarts
    | every year.
    |
    */

    'code_prefix' => env('PROJECTS_CODE_PREFIX', 'TED'),

    'code_sequence_digits' => 3,

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    'per_page' => 15,

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | "channels": per-person channels. "database" feeds the in-app bell and
    | "mail" sends email (each person can turn email off in their settings).
    |
    | "teams_webhook_url": Microsoft Teams incoming webhook for team-wide
    | alerts (a project goes at risk). Leave empty to disable.
    |
    | "digest_time": when the daily digest of due and overdue work is sent,
    | on Colombian business days only.
    |
    */

    'notifications' => [
        'channels' => array_filter(explode(',', (string) env('PROJECTS_NOTIFICATION_CHANNELS', 'database,mail'))),
        'teams_webhook_url' => env('PROJECTS_TEAMS_WEBHOOK_URL'),
        'digest_time' => env('PROJECTS_DIGEST_TIME', '07:00'),
    ],

];
