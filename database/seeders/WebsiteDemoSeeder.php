<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\Role;
use App\Models\SiteRelease;
use App\Models\User;
use App\Models\Widget;
use App\Support\Sites;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A demo website, "Northwind Supply", built from page metas (see Sites):
 *
 *   /          Home     banner, feature list, links to the other pages
 *   /about     About    large heading, text, three figures
 *   /contact   Contact  large heading, text, the public contact form
 *
 * The contact form saves to web_contact_messages and tells administrators (a notification
 * meta). The site answers on northwind.test and at /site/site_northwind. Its pages are
 * published when there is an administrator to publish them as.
 */
class WebsiteDemoSeeder extends Seeder
{
    private array $types = [];

    private int $order = 0;

    public function run(): void
    {
        $this->call([MetaTypeSeeder::class, WidgetSeeder::class, ThemeSeeder::class]);
        $this->types = MetaType::pluck('id', 'code')->all();

        DB::transaction(function () {
            [$form, $grid] = $this->contactForm();
            $site = Meta::updateOrCreate(['code' => 'site_northwind'], [
                'name' => 'Northwind Supply website',
                'type_id' => $this->types['website'],
                'description' => 'The demo company website: home, about and contact pages, with a contact form.',
                'json_config' => ['site' => [
                    'title' => 'Northwind Supply',
                    'tagline' => 'Office supplies for Ulaanbaatar teams',
                    'description' => 'Paper, ink, furniture and cleaning supplies, delivered to your office within two working days.',
                    'theme_meta_id' => Meta::where('code', 'theme_forest')->value('id'),
                    'footer' => "Northwind Supply LLC · Peace Avenue 17, Ulaanbaatar\nOpen Monday to Friday, 9:00–18:00",
                    'lang' => 'en',
                ] + Sites::BASE],
                'is_active' => true,
            ]);

            $home = $this->page('site_northwind_home', 'Home');
            $this->place($home, 'hero', 12, [
                'title' => 'Office supplies, delivered within two working days',
                'subtitle' => "Order paper, ink, chairs and cleaning supplies in one place.\n\nWe deliver across Ulaanbaatar and invoice monthly.",
                'button_label' => 'Ask for a quote',
                'url' => '/contact',
                'tone' => 'brand',
            ]);
            $this->place($home, 'list', 12, ['title' => 'Why offices buy from us', 'columns' => '3', 'items' => [
                ['title' => 'One monthly invoice', 'text' => 'Every order in a month on one bill, with your purchase order numbers.'],
                ['title' => 'Stock we hold ourselves', 'text' => 'Most items ship from our own warehouse in Khan-Uul.'],
                ['title' => 'A named account manager', 'text' => 'One person who knows your office and answers the phone.'],
            ]]);
            $this->place($home, 'links', 12, ['title' => 'Find out more', 'items' => [
                ['label' => 'About us', 'description' => 'Who we are and how we work.', 'url' => '/about'],
                ['label' => 'Contact the sales desk', 'description' => 'Ask for a quote or an account.', 'url' => '/contact'],
            ]]);

            $about = $this->page('site_northwind_about', 'About');
            $this->place($about, 'heading', 12, ['text' => 'A supplier that answers the phone', 'subtitle' => 'Supplying offices in Ulaanbaatar since 2014.', 'size' => 'large']);
            $this->place($about, 'text', 12, ['text' => "Northwind Supply started with one van and a handful of schools. Today we deliver to offices, clinics and schools across the city.\n\nWe keep our range small enough to hold in stock, so what you order arrives when we say it will."]);
            $this->place($about, 'stat', 4, ['label' => 'Customers', 'value' => '320', 'note' => 'Offices, clinics and schools']);
            $this->place($about, 'stat', 4, ['label' => 'Items in stock', 'value' => '4,800']);
            $this->place($about, 'stat', 4, ['label' => 'Usual delivery', 'value' => '2 days', 'note' => 'Working days, within the city']);

            $contact = $this->page('site_northwind_contact', 'Contact');
            $this->place($contact, 'heading', 12, ['text' => 'Talk to our sales desk', 'subtitle' => 'We answer within one working day.', 'size' => 'large']);
            $this->place($contact, 'text', 8, ['text' => "Tell us what your office needs and roughly how often. We'll send prices and set up an account.\n\nPrefer the phone? Call 7011-0000, Monday to Friday, 9:00–18:00."]);
            $this->place($contact, 'webform', 12, ['meta_id' => $form->id, 'title' => 'Write to us', 'subtitle' => 'We reply by email within one working day.', 'submit_label' => 'Send to the sales desk', 'done_text' => 'Thank you. The sales desk will answer within one working day.']);

            Sites::save($site, [
                'domains' => [['host' => 'northwind.test', 'is_primary' => true]],
                'pages' => [
                    ['label' => 'Home', 'slug' => '', 'page_meta_id' => $home->id, 'in_menu' => true, 'title' => 'Northwind Supply · Office supplies in Ulaanbaatar', 'description' => 'Paper, ink, furniture and cleaning supplies for offices, delivered across Ulaanbaatar within two working days.'],
                    ['label' => 'About', 'slug' => 'about', 'page_meta_id' => $about->id, 'in_menu' => true, 'title' => 'About Northwind Supply', 'description' => 'How a one-van supplier became the office supply partner of 320 offices, clinics and schools.'],
                    ['label' => 'Contact', 'slug' => 'contact', 'page_meta_id' => $contact->id, 'in_menu' => true, 'title' => 'Contact the Northwind sales desk', 'description' => 'Ask for prices or an account. Our sales desk answers within one working day.'],
                ],
            ], $this->admin() ?? new User);

            $this->publish($site);
            $this->notification($form, $grid);
        });
    }

    /** @return array{0: Meta, 1: Meta} */
    private function contactForm(): array
    {
        $form = Meta::updateOrCreate(['code' => 'web_contact_form'], [
            'name' => 'Contact us',
            'type_id' => $this->types['form'],
            'table_name' => 'web_contact_messages',
            'description' => 'Every field marked * is needed. We reply by email.',
            'is_active' => true,
            'is_public' => true,
        ]);
        $grid = Meta::updateOrCreate(['code' => 'web_contact_messages_grid'], [
            'name' => 'Website messages',
            'type_id' => $this->types['datagrid'],
            'table_name' => 'web_contact_messages',
            'description' => 'Messages sent from the Northwind website.',
            'is_active' => true,
        ]);
        foreach ([$form, $grid] as $meta) {
            MetaParam::where('main_meta_id', $meta->id)->delete();
            $rows = [
                ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
                ['name', 'Your name', 'string', ['is_required' => true, 'is_criteria' => true]],
                ['email', 'Email', 'string', ['is_required' => true, 'render_type' => 'email', 'is_criteria' => true]],
                ['company', 'Company', 'string', []],
                ['message', 'Message', 'string', ['is_required' => true, 'render_type' => 'textarea']],
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

    private function page(string $code, string $label): Meta
    {
        $page = Meta::updateOrCreate(['code' => $code], [
            'name' => "{$label} · Northwind Supply website",
            'label_name' => $label,
            'type_id' => $this->types['page'],
            'description' => 'A page of the Northwind Supply demo website.',
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

    /** Publishes every page that has no release yet, as the first administrator. */
    private function publish(Meta $site): void
    {
        $admin = $this->admin();
        if (! $admin) {
            $this->command?->warn('No administrator yet: the Northwind pages stay drafts. Publish them in the website manager.');

            return;
        }
        // Linked pages first, so the home page's links point at published pages.
        foreach (Sites::entries($site)->sortByDesc(fn (MetaParam $e) => ($e->json_config['slug'] ?? '') !== '') as $entry) {
            if (! SiteRelease::where('param_id', $entry->id)->exists()) {
                Sites::publish($site, $entry, $admin, null, null);
            }
        }
    }

    private function notification(Meta $form, Meta $grid): void
    {
        $meta = Meta::updateOrCreate(['code' => 'web_notify_contact'], [
            'name' => 'New website message',
            'type_id' => $this->types['notification'],
            'description' => 'Tells administrators when a visitor sends the Northwind contact form.',
            'json_config' => [
                'event' => 'created',
                'source_meta_id' => $form->id,
                'kind' => 'info',
                'title' => 'Website message from {name}',
                'body' => '{email} ({company}) wrote: {message}',
                'link_meta_id' => $grid->id,
                'link_record' => false,
                'exclude_actor' => true,
            ],
            'is_active' => true,
        ]);
        MetaParam::where('main_meta_id', $meta->id)->delete();
        MetaParam::create(['main_meta_id' => $meta->id, 'order_number' => 1, 'field_path' => 'admin', 'param_name' => 'admin', 'data_type' => 'role', 'is_show' => true]);
    }

    private function admin(): ?User
    {
        return User::whereHas('roles', fn ($q) => $q->where('code', Role::ADMIN))->orderBy('id')->first();
    }
}
