<?php

return [

    /*
    | Base URL of the React front-end. Used to build the public vendor
    | magic-link (/quote/{token}) that goes out in "send to all" emails.
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    /*
    | Company details printed on generated documents (invoice / quotation /
    | enquiry / delivery note). Edit here to update every document at once.
    */
    'company' => [
        'name' => env('COMPANY_NAME', 'MATRIA MARINE SERVICES'),
        'address' => env('COMPANY_ADDRESS', "192 Pandan Loop, #06-29 Unit B,\nPantech Business Hub\nSingapore 128381"),
        'phone' => env('COMPANY_PHONE', '(65) 82277151'),
        'uen' => env('COMPANY_UEN', 'S3500706W'),
    ],

    /*
    | Company base currency used to total multi-currency spend in reports.
    | Document amounts are converted via their stored exchange_rate.
    */
    'base_currency' => env('PROCUREMENT_BASE_CURRENCY', 'USD'),

];
