<?php

/*
 * Customers as schemas (see App\Support\CustomerSchema): the main schema holds users and logins,
 * each customer's schema holds its metas and data.
 */
return [

    /** New customer schemas are named this plus a random part. */
    'schema_prefix' => 'cust_',

    /** What new schemas are built from (App\Support\CoreStructure). */
    'core_file' => database_path('schema/customer-core.sql'),

    /** The customer that adopted the database the app had before customers existed. */
    'first_code' => env('CUSTOMER_FIRST_CODE', 'main'),

    /** Tables that live in the main schema only. */
    'main_tables' => [
        'users', 'social_accounts', 'password_reset_tokens', 'personal_access_tokens', 'sessions',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations',
        'customers', 'customer_users', 'customer_invites', 'customer_domains',
    ],

    /*
     * App and demo tables: they stay in the first customer's schema and are never made in a new
     * one. A table matches a prefix when its name starts with it.
     */
    'app_prefixes' => [
        'cal_', 'climb_', 'cm_', 'crm_', 'eam_', 'lms_', 'pay_', 'pm_', 'pos_', 'proc_', 'school_',
        'tender_', 'wand_', 'web_contact_', 'wh_', 'xl_', 'market_', 'company_', 'contract_',
    ],
    'app_tables' => ['tenders', 'companies', 'contracts', 'payments', 'escrow_entries', 'ratings', 'representation_requests'],

    /** Built-in rows a new schema needs to draw anything; they are not the customer's metas. */
    'lookup_tables' => ['meta_types', 'languages', 'widgets'],
];
