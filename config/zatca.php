<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ZATCA Environment
    |--------------------------------------------------------------------------
    |
    | Supported values: 'sandbox', 'simulation', 'production'
    |
    */
    'env' => env('ZATCA_ENV', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | OpenSSL Binary Path
    |--------------------------------------------------------------------------
    |
    | Absolute path to OpenSSL executable.
    |
    */
    'openssl_path' => env('ZATCA_OPENSSL_PATH', 'openssl'),

    /*
    |--------------------------------------------------------------------------
    | ZATCA Gateway URLs
    |--------------------------------------------------------------------------
    |
    */
    'urls' => [
        'sandbox'    => env('ZATCA_SANDBOX_URL', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal'),
        'simulation' => env('ZATCA_SIMULATION_URL', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation'),
        'production' => env('ZATCA_PRODUCTION_URL', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Helper Flag for Production
    |--------------------------------------------------------------------------
    |
    | Determines certificate signing type (ZATCA-Code-Signing vs TSTZATCA-Code-Signing)
    |
    */
    'is_production' => env('ZATCA_ENV') === 'production',
];

