<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenSSL Binary Path
    |--------------------------------------------------------------------------
    |
    | Path to the OpenSSL binary executable. If null or empty, EGS will auto-detect
    | the available OpenSSL binary from Laragon utils or system PATH.
    |
    */
    'openssl_path' => env('ZATCA_OPENSSL_PATH'),

    /*
    |--------------------------------------------------------------------------
    | ZATCA Environment
    |--------------------------------------------------------------------------
    |
    | Options:
    | - 'developer-portal' (Sandbox)
    | - 'simulation' (Pre-production testing)
    | - 'core' (Live Production)
    |
    */
    'environment' => env('ZATCA_ENV', 'developer-portal'),
];
