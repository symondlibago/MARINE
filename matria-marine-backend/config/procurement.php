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
        'phone' => env('COMPANY_PHONE', '(65) 91295283'),
        'email' => env('COMPANY_EMAIL', 'sales@matriamarine.com'),
        'website' => env('COMPANY_WEBSITE', 'www.matriamarine.com'),
        'uen' => env('COMPANY_UEN', '53500706W'),
        // Bank details printed on invoices (how the customer pays us).
        'bank_name' => env('COMPANY_BANK_NAME', 'DBS Bank Singapore'),
        'bank_account' => env('COMPANY_BANK_ACCOUNT', '0721307723'),
        'bank_swift' => env('COMPANY_BANK_SWIFT', 'DBSSSGSG'),
    ],

    /*
    | The currency the books are kept and reported in.
    |
    | Singapore dollars, because that is what the GST return and the financial
    | statements are filed in — a set of books totalled in anything else has to
    | be converted again before it can be submitted, which is a second place
    | for the figures to go wrong.
    |
    | Every document stores the rate it was converted at (exchange_rate), and
    | the reports total those converted amounts. Changing this value does NOT
    | restate the rates already recorded against existing documents, so it is
    | not something to switch on a whim.
    */
    'base_currency' => env('PROCUREMENT_BASE_CURRENCY', 'SGD'),

];
