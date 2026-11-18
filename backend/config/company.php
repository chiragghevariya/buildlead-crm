<?php

/*
| Company details printed on quotes, invoices and emails (demo company).
*/

return [
    'name' => env('COMPANY_NAME', 'BuildLead Bau GmbH'),
    'tagline' => 'Bauen in Berlin & Brandenburg',
    'street' => env('COMPANY_STREET', 'Friedrichstraße 123'),
    'postcode' => env('COMPANY_POSTCODE', '10117'),
    'city' => env('COMPANY_CITY', 'Berlin'),
    'country' => 'Deutschland',
    'phone' => env('COMPANY_PHONE', '+49 30 5550 1000'),
    'email' => env('COMPANY_EMAIL', 'angebote@buildlead.test'),
    'website' => env('COMPANY_WEBSITE', 'www.buildlead.test'),

    // Legal (Impressum) — demo values
    'managing_director' => 'Katrin Hoffmann',
    'register' => 'Amtsgericht Charlottenburg, HRB 123456 B',
    'vat_id' => 'DE123456789',
    'tax_number' => '27/123/45678',

    // Bank (ISO example IBAN — not a real account)
    'bank' => 'Berliner Sparkasse',
    'iban' => 'DE89 3704 0044 0532 0130 00',
    'bic' => 'COBADEFFXXX',

    'vat_rate' => 19,
    'quote_validity_days' => 30,
    'payment_terms_days' => 14,
    // An invoice may not push a project's invoiced total above this share of its budget.
    'invoice_budget_cap' => 1.10,
];
