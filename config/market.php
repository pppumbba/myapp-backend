<?php

return [

    /*
    | Where people and companies are checked. "fake" answers from the test lists below;
    | the real DAN and ХУР connections come once the government access agreement exists.
    */
    'dan' => ['driver' => env('MARKET_DAN_DRIVER', 'fake')],
    'khur' => ['driver' => env('MARKET_KHUR_DRIVER', 'fake')],

    /*
    | Where fees and escrow money are paid. "fake" shows a test pay page; "qpay" sends real
    | invoices once the merchant account exists (QPAY_* in .env).
    */
    'payments' => [
        'driver' => env('MARKET_PAYMENT_DRIVER', 'fake'),
        'qpay' => [
            'url' => env('QPAY_URL', 'https://merchant.qpay.mn'),
            'client_id' => env('QPAY_CLIENT_ID'),
            'client_secret' => env('QPAY_CLIENT_SECRET'),
            'invoice_code' => env('QPAY_INVOICE_CODE'),
        ],
    ],

    /*
    | Money the market holds for a contract's payment steps. "test" until a licensed partner
    | is signed: the ledger works, no real money is held. A step the vendor asked to be paid
    | is released by itself after this many days unless the buyer answers or a dispute freezes it.
    */
    'escrow' => [
        'mode' => env('MARKET_ESCROW_MODE', 'test'),
        'auto_release_days' => (int) env('MARKET_ESCROW_AUTO_RELEASE_DAYS', 7),
    ],

    // Tenders: limits and defaults (spec §9).
    'tenders' => [
        'max_items' => (int) env('MARKET_TENDER_MAX_ITEMS', 200),
        'max_specs' => (int) env('MARKET_TENDER_MAX_SPECS', 50),
        // A bid stays binding this many days after the close date unless the rule names a date.
        'validity_days' => (int) env('MARKET_TENDER_VALIDITY_DAYS', 30),
        // How long a final-offer (best and final) round runs unless the poster names a date.
        'final_round_days' => (int) env('MARKET_TENDER_FINAL_ROUND_DAYS', 3),
    ],

    // Test people and companies the fake drivers answer with.
    'fake' => [
        'citizens' => [
            ['registry_number' => 'УБ90010112', 'name' => 'Бат-Эрдэнэ Ганбаатар'],
            ['registry_number' => 'УБ85051523', 'name' => 'Сарангэрэл Должин'],
            ['registry_number' => 'ДА92120334', 'name' => 'Тэмүүлэн Болд'],
        ],
        'companies' => [
            ['registry_number' => '5123456', 'name' => 'Говь Ханган ХХК', 'status' => 'active', 'director_registry_number' => 'УБ90010112', 'director_name' => 'Бат-Эрдэнэ Ганбаатар'],
            ['registry_number' => '6234567', 'name' => 'Талст Үйлчилгээ ХХК', 'status' => 'active', 'director_registry_number' => 'УБ85051523', 'director_name' => 'Сарангэрэл Должин'],
            ['registry_number' => '2345678', 'name' => 'Хуучин Наймаа ХХК', 'status' => 'liquidated', 'director_registry_number' => 'ДА92120334', 'director_name' => 'Тэмүүлэн Болд'],
        ],
    ],

];
