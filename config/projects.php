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

];
