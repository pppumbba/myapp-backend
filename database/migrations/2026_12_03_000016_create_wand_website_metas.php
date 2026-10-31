<?php

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\Role;
use App\Models\SiteRelease;
use App\Models\User;
use App\Models\Widget;
use App\Support\SiteChecks;
use App\Support\Sites;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The marketing website of the platform itself, "Wand", built only from metas
 * (table: 2026_12_03_000015_create_wand_demo_requests_table):
 *
 *   /                          Home       banner, how it works, links to the feature areas
 *   /features                  Features   how a screen is made; lists its sub pages
 *   ├─ /features/data-and-forms           grids, forms, workspaces, charts, calendars, query builder, Excel
 *   ├─ /features/automation               process flows, statuses, notifications, schedules, stage queries, scripts
 *   ├─ /features/documents                PDF designer, PDF import, amounts in words, document fields, files
 *   ├─ /features/websites                 site pages, looks, checks, releases, domains, public forms
 *   └─ /features/control                  roles, versions, patches, record log, languages, themes, where used
 *   /apps                      Apps       the apps built on the platform (CRM, contracts, warehouse, assets, tenders)
 *   /pricing                   Pricing    no prices: a quote after a call
 *   /contact                   Contact    the public demo request form (wand_demo_requests), admins notified
 *
 * Header: Features (with its sub pages), Apps, Pricing, Contact. The footer lists every page.
 * Look Cobalt with the Wand theme's indigo #4f39f6 as its accent. No social links (none given).
 * No figures, customers or quotes on purpose: there is no real proof to show yet.
 *
 * Pages are published as the first administrator; publishing stops on any floor or reflex
 * finding, so the migration fails rather than putting a page out that the checks refuse.
 */
return new class extends Migration
{
    private const SITE = 'site_wand';

    /** @var array<string, int> */
    private array $types = [];

    private int $order = 0;

    public function up(): void
    {
        if (! Schema::hasTable('wand_demo_requests')) {
            return;
        }
        $this->types = DB::table('meta_types')->pluck('id', 'code')->all();
        // A database without the meta types (a fresh test database) has nothing to build on.
        if (array_diff(['datagrid', 'form', 'notification', 'page', 'theme', 'website', 'widget'], array_keys($this->types))) {
            return;
        }

        DB::transaction(function () {
            $theme = $this->theme();
            [$form, $grid] = $this->demoForm();
            $this->notification($form, $grid);

            $site = Meta::updateOrCreate(['code' => self::SITE], [
                'name' => 'Wand website',
                'type_id' => $this->types['website'],
                'description' => 'The marketing website of the Wand platform: features, apps built on it, pricing and a demo request form.',
                'is_active' => true,
            ]);

            $pages = $this->pages();
            $admin = $this->admin() ?? new User;

            Sites::save($site, [
                'site' => [
                    'title' => 'Wand',
                    'tagline' => 'No-code business apps',
                    'description' => 'Wand builds business apps from data: grids, forms, workflows, documents and websites, without writing code.',
                    'theme_meta_id' => $theme->id,
                    'look' => 'cobalt',
                    'brand_accent' => true,
                    'header' => 'edge',
                    'footer_style' => 'columns',
                    'footer' => "Wand, a no-code platform for business apps.\nThis website is built with Wand.",
                    'lang' => 'en',
                    'social' => [],
                ],
                'domains' => [['host' => 'wand.test', 'is_primary' => true]],
                'pages' => [
                    ['label' => 'Home', 'slug' => '', 'page_meta_id' => $pages['home']->id, 'in_menu' => false, 'in_footer' => true,
                        'title' => 'Wand · Build business apps without code',
                        'description' => 'Wand turns the grids, forms, workflows and documents you describe into working business apps.'],
                    ['label' => 'Features', 'slug' => 'features', 'page_meta_id' => $pages['features']->id, 'in_menu' => true,
                        'title' => 'Features of the Wand app builder',
                        'description' => 'How a Wand screen is made, and the areas it covers: data and forms, automation, documents, websites and control.',
                        'children' => [
                            ['label' => 'Data and forms', 'slug' => 'data-and-forms', 'page_meta_id' => $pages['data']->id, 'in_menu' => true,
                                'title' => 'Data grids and forms · Wand',
                                'description' => 'Grids, forms with detail lines, workspaces, dashboards, calendars, Gantt, a query builder and Excel import and export.'],
                            ['label' => 'Automation', 'slug' => 'automation', 'page_meta_id' => $pages['automation']->id, 'in_menu' => true,
                                'title' => 'Automation · Wand',
                                'description' => 'Process flows, workflow statuses, notifications, scheduled forms, stage queries and form scripts.'],
                            ['label' => 'Documents', 'slug' => 'documents', 'page_meta_id' => $pages['documents']->id, 'in_menu' => true,
                                'title' => 'Documents and PDFs · Wand',
                                'description' => 'Design PDF documents once and print them from any record, starting from a blank page or your own PDF.'],
                            ['label' => 'Websites', 'slug' => 'websites', 'page_meta_id' => $pages['websites']->id, 'in_menu' => true,
                                'title' => 'Websites · Wand',
                                'description' => 'Public websites built from widgets on the same data, checked before every release and published on your own domain.'],
                            ['label' => 'Control', 'slug' => 'control', 'page_meta_id' => $pages['control']->id, 'in_menu' => true,
                                'title' => 'Roles, versions and patches · Wand',
                                'description' => 'Roles and permissions, version history, patches, record logs, languages and themes for teams that build together.'],
                        ]],
                    ['label' => 'Apps', 'slug' => 'apps', 'page_meta_id' => $pages['apps']->id, 'in_menu' => true,
                        'title' => 'Apps built on Wand',
                        'description' => 'CRM, contract management, warehouse, asset management and a tender marketplace, all built on the Wand platform.'],
                    ['label' => 'Pricing', 'slug' => 'pricing', 'page_meta_id' => $pages['pricing']->id, 'in_menu' => true,
                        'title' => 'Pricing · Wand',
                        'description' => 'Wand is priced per setup. Tell us what you want to build and who will use it, and we will send a quote.'],
                    ['label' => 'Contact', 'slug' => 'contact', 'page_meta_id' => $pages['contact']->id, 'in_menu' => true,
                        'title' => 'Book a demo of Wand',
                        'description' => 'Ask for a walk through Wand. Tell us what you want to build and we will reply by email.'],
                ],
            ], $admin);

            $this->publish($site->refresh());
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $site = DB::table('metas')->where('code', self::SITE)->value('id');
            if ($site) {
                DB::table('site_releases')->where('site_meta_id', $site)->delete();
                DB::table('site_domains')->where('site_meta_id', $site)->delete();
                DB::table('site_visits')->where('site_meta_id', $site)->delete();
            }
            $ids = DB::table('metas')->where('code', 'like', 'site\_wand%')->orWhere('code', 'like', 'wand\_%')->orWhere('code', 'theme_wand')->pluck('id');
            DB::table('meta_notification_map')->whereIn('notification_id', $ids)->orWhereIn('meta_id', $ids)->delete();
            DB::table('meta_meta_map')->whereIn('src_meta_id', $ids)->orWhereIn('trg_meta_id', $ids)->delete();
            DB::table('meta_params')->whereIn('main_meta_id', $ids)->delete();
            DB::table('meta_params')->whereIn('ref_meta_id', $ids)->update(['ref_meta_id' => null]);
            DB::table('metas')->whereIn('ref_meta_id', $ids)->update(['ref_meta_id' => null]);
            DB::table('metas')->whereIn('id', $ids)->delete();
        });
    }

    /** The platform's own indigo, as a theme the site wears (it shows in the theme pickers too). */
    private function theme(): Meta
    {
        return Meta::updateOrCreate(['code' => 'theme_wand'], [
            'name' => 'Wand',
            'type_id' => $this->types['theme'],
            'description' => 'Wand\'s own indigo, worn by the Wand website.',
            'json_config' => ['theme' => ['brand' => '#4f39f6', 'gray' => 'slate', 'radius' => 'default', 'font' => 'inter', 'density' => 'default', 'mode' => 'any', 'default' => false, 'apps' => []]],
            'is_active' => true,
        ]);
    }

    /** @return array{0: Meta, 1: Meta} the public demo request form and the admins' grid of requests */
    private function demoForm(): array
    {
        $form = Meta::updateOrCreate(['code' => 'wand_demo_request_form'], [
            'name' => 'Book a demo',
            'type_id' => $this->types['form'],
            'table_name' => 'wand_demo_requests',
            'description' => 'Fields marked * are needed. We reply by email.',
            'is_active' => true,
            'is_public' => true,
        ]);
        $grid = Meta::updateOrCreate(['code' => 'wand_demo_requests_grid'], [
            'name' => 'Demo requests',
            'type_id' => $this->types['datagrid'],
            'table_name' => 'wand_demo_requests',
            'description' => 'Demo requests sent from the Wand website.',
            'is_active' => true,
        ]);
        $interest = ['render_type' => 'select', 'json_config' => ['options' => ['A new business app', 'Moving off spreadsheets', 'A website', 'Something else']]];
        foreach ([$form, $grid] as $meta) {
            MetaParam::where('main_meta_id', $meta->id)->delete();
            $rows = [
                ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
                ['name', 'Your name', 'string', ['is_required' => true, 'is_criteria' => true]],
                ['email', 'Work email', 'string', ['is_required' => true, 'render_type' => 'email', 'is_criteria' => true]],
                ['company', 'Company', 'string', ['is_criteria' => $meta === $grid]],
                ['interest', 'What you have in mind', 'string', $interest + ['is_criteria' => $meta === $grid]],
                ['message', 'Tell us a little more', 'string', ['is_required' => true, 'render_type' => 'textarea']],
            ];
            if ($meta === $grid) {
                $rows[] = ['created_at', 'Sent', 'datetime', []];
            }
            foreach ($rows as $i => [$field, $label, $type, $extra]) {
                MetaParam::create(['main_meta_id' => $meta->id, 'order_number' => $i + 1, 'field_path' => $field, 'param_name' => $field, 'label_name' => $label, 'data_type' => $type] + $extra);
            }
        }

        return [$form, $grid];
    }

    /** Tells administrators when a visitor asks for a demo. */
    private function notification(Meta $form, Meta $grid): void
    {
        $meta = Meta::updateOrCreate(['code' => 'wand_notify_demo_request'], [
            'name' => 'New demo request',
            'type_id' => $this->types['notification'],
            'description' => 'Tells administrators when a visitor sends the demo request form on the Wand website.',
            'json_config' => [
                'event' => 'created',
                'source_meta_id' => $form->id,
                'kind' => 'info',
                'title' => 'Demo request from {name}',
                'body' => '{email} ({company}), {interest}: {message}',
                'link_meta_id' => $grid->id,
                'link_record' => false,
                'exclude_actor' => true,
            ],
            'is_active' => true,
        ]);
        MetaParam::where('main_meta_id', $meta->id)->delete();
        MetaParam::create(['main_meta_id' => $meta->id, 'order_number' => 1, 'field_path' => 'admin', 'param_name' => 'admin', 'data_type' => 'role', 'is_show' => true]);
    }

    /** @return array<string, Meta> the page metas with their widgets, by key */
    private function pages(): array
    {
        $p = [];

        $p['home'] = $this->page('site_wand_home', 'Home');
        $this->place($p['home'], 'hero', 12, [
            'title' => 'Build business apps without writing code',
            'subtitle' => 'Wand turns the grids, forms and flows you describe into apps.',
            'button_label' => 'Book a demo',
            'url' => '/contact',
            'tone' => 'light',
        ]);
        $this->place($p['home'], 'list', 12, ['title' => 'How Wand works', 'columns' => '3', 'items' => [
            ['title' => 'Describe it, don’t code it', 'text' => 'Screens, fields and rules are records you edit in a builder.'],
            ['title' => 'Change it while it runs', 'text' => 'Edits apply on save, and a version is kept before each one.'],
            ['title' => 'One platform, many apps', 'text' => 'Every app shares one login, one set of roles and one item catalogue.'],
        ]]);
        $this->place($p['home'], 'links', 12, ['title' => 'Explore', 'items' => [
            ['label' => 'Data and forms', 'description' => 'Grids, forms, workspaces and dashboards.', 'url' => '/features/data-and-forms'],
            ['label' => 'Automation', 'description' => 'Flows, statuses, notifications and schedules.', 'url' => '/features/automation'],
            ['label' => 'Documents', 'description' => 'PDFs designed once, printed from any record.', 'url' => '/features/documents'],
            ['label' => 'Websites', 'description' => 'Public sites like this one.', 'url' => '/features/websites'],
            ['label' => 'Apps built on Wand', 'description' => 'CRM, contracts, warehouse, assets and tenders.', 'url' => '/apps'],
        ]]);

        $p['features'] = $this->page('site_wand_features', 'Features');
        $this->place($p['features'], 'heading', 12, ['text' => 'One builder for the whole app', 'subtitle' => 'Every screen, rule and page is a record you can edit.', 'size' => 'large']);
        $this->place($p['features'], 'list', 12, ['title' => 'How a screen is made', 'columns' => '3', 'items' => [
            ['title' => 'Point it at your data', 'text' => 'A table, a query or a REST service. Fields come from its columns.'],
            ['title' => 'Arrange it in the studio', 'text' => 'Drag grids, forms, tabs and pages into an app’s menu.'],
            ['title' => 'Move it as a patch', 'text' => 'Freeze a release and install it on another server.'],
        ]]);

        $p['data'] = $this->page('site_wand_data', 'Data and forms');
        $this->place($p['data'], 'heading', 12, ['text' => 'Grids and forms from your tables', 'subtitle' => 'Point a screen at a table, a query or a REST API.', 'size' => 'large']);
        $this->place($p['data'], 'list', 12, ['columns' => '2', 'items' => [
            ['title' => 'Data grids', 'text' => 'Filters, sidebar facets, footer totals, frozen columns, trees and nested subgrids.'],
            ['title' => 'Forms', 'text' => 'Detail lines three levels deep, lookups, autonumbers, files and form scripts.'],
            ['title' => 'Workspaces', 'text' => 'One record with its related lists in tabs, and matrix tables.'],
            ['title' => 'Dashboards and charts', 'text' => 'Charts, statements and reports that drill down to the records.'],
            ['title' => 'Calendars and Gantt', 'text' => 'Dates and timelines drawn from any table.'],
            ['title' => 'Query builder and Excel', 'text' => 'Drag tables and joins into a query. Import and export .xlsx files.'],
        ]]);

        $p['automation'] = $this->page('site_wand_automation', 'Automation');
        $this->place($p['automation'], 'heading', 12, ['text' => 'Rules that run themselves', 'subtitle' => 'Saves, statuses and schedules start the next step.', 'size' => 'large']);
        $this->place($p['automation'], 'list', 12, ['columns' => '2', 'items' => [
            ['title' => 'Process flows', 'text' => 'One save fills in the next forms, all in one transaction.'],
            ['title' => 'Workflow statuses', 'text' => 'Draft, review, approved: each move checked against roles.'],
            ['title' => 'Notifications', 'text' => 'In-app and email alerts when a record is created or changes status.'],
            ['title' => 'Scheduled forms', 'text' => 'Run a form for every row of a grid, every day or month.'],
            ['title' => 'Stage queries', 'text' => 'Named SQL steps, with a dry run before they touch data.'],
            ['title' => 'Form scripts', 'text' => 'JavaScript on load, on change, and before or after a save.'],
        ]]);

        $p['documents'] = $this->page('site_wand_documents', 'Documents');
        $this->place($p['documents'], 'heading', 12, ['text' => 'Print-ready PDFs from any record', 'subtitle' => 'Design the page once; every record fills it in.', 'size' => 'large']);
        $this->place($p['documents'], 'list', 12, ['columns' => '2', 'items' => [
            ['title' => 'Document designer', 'text' => 'Bands, fields, line tables, signatures and page numbers, sized in millimetres.'],
            ['title' => 'Start from your own PDF', 'text' => 'Import an existing form and keep it as the background.'],
            ['title' => 'Amounts in words', 'text' => 'Totals written out in Mongolian, as invoices need them.'],
            ['title' => 'Document fields', 'text' => 'Fill in a document on a form, and keep its PDF with each save.'],
            ['title' => 'File attachments', 'text' => 'Drop one file or many onto a record’s field.'],
        ]]);

        $p['websites'] = $this->page('site_wand_websites', 'Websites');
        $this->place($p['websites'], 'heading', 12, ['text' => 'Public websites on the same data', 'subtitle' => 'This site is built with Wand, page by page.', 'size' => 'large']);
        $this->place($p['websites'], 'list', 12, ['columns' => '2', 'items' => [
            ['title' => 'Pages from widgets', 'text' => 'Banners, tables, tabs, galleries, forms and live charts.'],
            ['title' => 'Fifty looks', 'text' => 'Pick a look, a header and a footer; your theme sets the accent.'],
            ['title' => 'Checks before publishing', 'text' => 'Empty pages, missing alt text, weak contrast and broken links are caught.'],
            ['title' => 'Releases', 'text' => 'Publish now or at a set time, and restore an older release.'],
            ['title' => 'Your own domain', 'text' => 'Each site answers on its own host names.'],
            ['title' => 'Public forms', 'text' => 'Guests can send a form or book a time, and see nothing else.'],
        ]]);

        $p['control'] = $this->page('site_wand_control', 'Control');
        $this->place($p['control'], 'heading', 12, ['text' => 'Control who sees and changes what', 'subtitle' => 'Roles, history and releases for teams that build together.', 'size' => 'large']);
        $this->place($p['control'], 'list', 12, ['columns' => '2', 'items' => [
            ['title' => 'Roles and permissions', 'text' => 'Per screen and per status. Screens without rules stay with admins.'],
            ['title' => 'Version history', 'text' => 'A snapshot before every change, with a diff and a restore.'],
            ['title' => 'Patches', 'text' => 'Freeze a release, install it elsewhere, and roll it back if needed.'],
            ['title' => 'Record log', 'text' => 'Field by field history of every save on the screens you pick.'],
            ['title' => 'Languages', 'text' => 'English and Mongolian, for the interface and for your own screens.'],
            ['title' => 'Themes', 'text' => 'Brand colours, light and dark modes, and three text sizes.'],
        ]]);

        $p['apps'] = $this->page('site_wand_apps', 'Apps');
        $this->place($p['apps'], 'heading', 12, ['text' => 'Apps built on Wand', 'subtitle' => 'Business apps made from the same building blocks.', 'size' => 'large']);
        $this->place($p['apps'], 'list', 12, ['columns' => '1', 'items' => [
            ['title' => 'CRM', 'text' => 'Leads, customers of several types, quotations, sales orders and morning follow-up reminders.'],
            ['title' => 'Contract management', 'text' => 'Parties, payment schedules and penalties, a review workflow, PDF print and daily alerts.'],
            ['title' => 'Warehouse', 'text' => 'One document for every stock move, posted to a ledger at average cost.'],
            ['title' => 'Asset management', 'text' => 'Asset register, work orders, scheduled maintenance, transfers and book values.'],
            ['title' => 'Tender marketplace', 'text' => 'Verified company accounts, contracts signed online, fee invoices and escrow steps.'],
        ]]);
        $this->place($p['apps'], 'button', 12, ['label' => 'Ask for a walk through', 'url' => '/contact', 'style' => 'primary']);

        $p['pricing'] = $this->page('site_wand_pricing', 'Pricing');
        $this->place($p['pricing'], 'heading', 12, ['text' => 'Pricing for your setup', 'subtitle' => 'We send a quote after a short call.', 'size' => 'large']);
        $this->place($p['pricing'], 'list', 12, ['title' => 'What we ask about', 'columns' => '3', 'items' => [
            ['title' => 'The apps you need', 'text' => 'One app, or several sharing the same data.'],
            ['title' => 'Who uses them', 'text' => 'Your staff, your customers, or the public.'],
            ['title' => 'Where it runs', 'text' => 'The server and domains you have in mind.'],
        ]]);
        $this->place($p['pricing'], 'button', 12, ['label' => 'Contact us', 'url' => '/contact', 'style' => 'primary']);

        $p['contact'] = $this->page('site_wand_contact', 'Contact');
        $this->place($p['contact'], 'heading', 12, ['text' => 'Book a demo', 'subtitle' => 'Tell us what you want to build.', 'size' => 'large']);
        $this->place($p['contact'], 'webform', 12, [
            'meta_id' => DB::table('metas')->where('code', 'wand_demo_request_form')->value('id'),
            'title' => 'Your request',
            'subtitle' => 'We reply by email.',
            'submit_label' => 'Request a demo',
            'done_text' => 'Thank you. We have your request and will reply by email.',
        ]);

        return $p;
    }

    private function page(string $code, string $label): Meta
    {
        $page = Meta::updateOrCreate(['code' => $code], [
            'name' => "{$label} · Wand website",
            'label_name' => $label,
            'type_id' => $this->types['page'],
            'description' => 'A page of the Wand marketing website.',
            'is_active' => true,
        ]);
        MetaParam::where('main_meta_id', $page->id)->delete();
        Meta::where('ref_meta_id', $page->id)->where('type_id', $this->types['widget'])->delete();

        return $page;
    }

    private function place(Meta $page, string $widgetCode, int $w, array $config): void
    {
        $widget = Widget::where('code', $widgetCode)->firstOrFail();
        $code = "{$page->code}_{$widgetCode}_".++$this->order;
        $meta = Meta::create([
            'code' => $code,
            'name' => "{$widget->name} on {$page->label_name}",
            'type_id' => $this->types['widget'],
            'widget_id' => $widget->id,
            'ref_meta_id' => $page->id,
            'json_config' => array_merge($widget->defaults(), $config),
            'is_active' => true,
        ]);
        MetaParam::create([
            'main_meta_id' => $page->id,
            'order_number' => $this->order,
            'field_path' => $code,
            'param_name' => $code,
            'data_type' => 'widget',
            'ref_meta_id' => $meta->id,
            'is_show' => true,
            'json_config' => ['w' => $w],
        ]);
    }

    /**
     * Publishes every page without a release, linked pages before the pages linking to them
     * (sub pages, then contact, ... then home), so no page links to an unpublished one. Any
     * floor or reflex finding stops the migration.
     */
    private function publish(Meta $site): void
    {
        $admin = $this->admin();
        if (! $admin) {
            return;
        }
        $rank = ['contact' => 1, 'pricing' => 2, 'apps' => 3, 'features' => 4, 'home' => 5];
        $entries = Sites::entries($site)->sortBy(fn (MetaParam $e) => $e->parent_id ? 0 : ($rank[$e->field_path] ?? 0))->values();
        foreach ($entries as $entry) {
            if (SiteRelease::where('param_id', $entry->id)->exists()) {
                continue;
            }
            $issues = SiteChecks::page($site, $entry, Sites::snapshot($entry, $admin));
            $blocking = array_filter($issues, fn ($i) => $i['level'] !== 'finish');
            if ($blocking !== []) {
                throw new RuntimeException("Page {$entry->field_path} fails the site checks: ".json_encode(array_values($blocking)));
            }
            Sites::publish($site, $entry, $admin, null, null);
        }
    }

    private function admin(): ?User
    {
        return User::whereHas('roles', fn ($q) => $q->where('code', Role::ADMIN))->orderBy('id')->first();
    }
};
