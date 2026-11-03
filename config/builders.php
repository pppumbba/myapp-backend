<?php

/*
 * What a builder who is not an admin may put into the metas they make (see App\Support\Builders).
 * Admins are not limited by any of this.
 */
return [

    /*
     * The meta types a builder may make. Left out on purpose: query and stage_query (SQL),
     * notification (mails anyone), workflow, theme, website, screen and widget_template.
     */
    'types' => [
        'app', 'calendar', 'chart', 'dashboard', 'datagrid', 'excel_import', 'form', 'gantt', 'get',
        'matrix', 'page', 'report', 'statement', 'tabs', 'widget', 'workspace',
    ],

    /*
     * Tables a builder's metas may not read or write: sign-in, roles and permissions, the
     * metas themselves, files, chats, logs, framework tables and the tender market. A name
     * matches a prefix when it starts with it.
     */
    'system_tables' => [
        'users', 'user_roles', 'user_notifications', 'roles', 'role_log', 'social_accounts',
        'personal_access_tokens', 'password_reset_tokens', 'sessions', 'session_values',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations',
        'autonumbers', 'record_log', 'translations', 'languages', 'tags', 'widgets',
        'contents', 'content_map', 'chats', 'chat_members', 'chat_messages', 'schedules',
        'patches', 'patch_installs', 'patch_metas', 'site_domains', 'site_releases', 'site_visits',
        'companies', 'company_invites', 'company_members', 'contracts', 'contract_disputes',
        'contract_events', 'contract_items', 'contract_signatures', 'contract_steps', 'payments',
        'escrow_entries', 'ratings', 'representation_requests',
    ],
    'system_prefixes' => ['meta_', 'metas', 'wfm_', 'market_', 'pma__'],

    /** Meta columns only admins set: SQL, external services, form scripts, and opening a meta to everyone. */
    'admin_columns' => [
        'query_string', 'is_public', 'is_open',
        'load_expression_string', 'event_expression_string', 'before_save_expression_string',
        'after_save_expression_string', 'var_fnc_expression_string',
        'service_url', 'service_headers', 'service_query', 'service_list_body', 'service_list_path',
        'service_total_path', 'service_record_path', 'service_update_method',
    ],
];
