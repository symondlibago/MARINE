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
        'address' => env('COMPANY_ADDRESS', "BLK 239, Unit 15-92, Lorong 1 Toa Payoh\nSingapore 310239"),
        'phone' => env('COMPANY_PHONE', '(65) 82277151'),
        'uen' => env('COMPANY_UEN', 'S3500706W'),
    ],

    /*
    | Company base currency used to total multi-currency spend in reports.
    | Document amounts are converted via their stored exchange_rate.
    */
    'base_currency' => env('PROCUREMENT_BASE_CURRENCY', 'USD'),

    /*
    | Navision / Business Central CSV export (Phase 4). Tune the column values,
    | date format and delimiter here (or via env) so the CSV matches whatever
    | your BC purchase-invoice import expects — no code changes needed.
    */
    'navision' => [
        // Default G/L account No. for invoice lines that don't carry their own.
        'default_account' => env('NAV_DEFAULT_ACCOUNT', ''),
        // G/L account No. used for the "other charges" (freight etc.) line.
        'charges_account' => env('NAV_CHARGES_ACCOUNT', ''),
        // Line type written to the CSV: 'G/L Account' or 'Item'.
        'line_type' => env('NAV_LINE_TYPE', 'G/L Account'),
        // PHP date() format for date columns, e.g. 'Y-m-d' or 'd/m/Y'.
        'date_format' => env('NAV_DATE_FORMAT', 'Y-m-d'),
        // CSV field delimiter.
        'delimiter' => env('NAV_DELIMITER', ','),
    ],

];
