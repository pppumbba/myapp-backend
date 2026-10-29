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
 * A second demo website, "Khad Climbing", an indoor climbing wall in Ulaanbaatar, in
 * Hallmark's Refract look. It shows the booking widgets:
 *
 *   /          Home    banner, today's sessions with spaces left (Schedule), a wide photo,
 *                      what a session includes, when the wall is quiet (chart)
 *   /prices    Prices  the ticket and course tables
 *   /visit     Visit   how to get there, what to bring, opening hours (tabs), questions
 *                      (accordion), the waiver (show more), a wide photo
 *
 * Sessions are a daily timetable (climb_sessions); "Book" opens the public booking form for
 * today with the session filled in, and the spaces left count today's bookings. The photos
 * are Wikimedia Commons stand-ins, credited in the footer.
 */
class ClimbingDemoSeeder extends Seeder
{
    private const SESSIONS = [
        ['07:00', '08:30', 'Early wall', 'Lead and top rope', 16],
        ['12:00', '13:30', 'Lunch bouldering', 'Boulder room', 20],
        ['16:00', '17:30', 'Youth club', 'Top rope, ages 8 to 15', 12],
        ['18:00', '19:30', 'Evening wall', 'Lead and top rope', 24],
        ['19:45', '21:15', 'Late bouldering', 'Boulder room', 20],
    ];

    /** Climbers on the wall in a usual week, by hour. */
    private const BUSY = [
        'weekday' => ['07' => 9, '08' => 6, '09' => 3, '10' => 2, '11' => 4, '12' => 14, '13' => 8, '14' => 3, '15' => 5, '16' => 12, '17' => 18, '18' => 31, '19' => 34, '20' => 22, '21' => 11],
        'weekend' => ['07' => 4, '08' => 7, '09' => 15, '10' => 24, '11' => 28, '12' => 25, '13' => 21, '14' => 23, '15' => 26, '16' => 22, '17' => 17, '18' => 13, '19' => 10, '20' => 6, '21' => 3],
    ];

    private const PHOTOS = [
        'wall' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/42/Joshua_Tree_-_Jerry_Brown_49.jpg/1920px-Joshua_Tree_-_Jerry_Brown_49.jpg',
        'ice' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/7/72/Flo_dans_Juvs%C3%B8yla_%C3%A0_Rjukan%2C_Norv%C3%A8ge-rotated.jpg/1920px-Flo_dans_Juvs%C3%B8yla_%C3%A0_Rjukan%2C_Norv%C3%A8ge-rotated.jpg',
    ];

    private array $types = [];

    private int $order = 0;

    public function run(): void
    {
        $this->call([MetaTypeSeeder::class, WidgetSeeder::class, ThemeSeeder::class]);
        $this->types = MetaType::pluck('id', 'code')->all();

        DB::transaction(function () {
            $this->data();
            [$sessions, $form, $bookings] = $this->bookingMetas();
            $chart = $this->chart();

            $site = Meta::updateOrCreate(['code' => 'site_khad'], [
                'name' => 'Khad Climbing website',
                'type_id' => $this->types['website'],
                'description' => 'The demo climbing wall website: today\'s sessions to book, prices and how to get there.',
                'json_config' => ['site' => [
                    'title' => 'Khad Climbing',
                    'tagline' => 'Indoor climbing wall, Sukhbaatar district',
                    'description' => 'Book a ninety-minute session on the wall today. Shoes and chalk included, open daily 07:00 to 21:15.',
                    'look' => 'refract',
                    'brand_accent' => false,
                    'footer' => "Khad Climbing · Olympic Street 12, Ulaanbaatar\nOpen daily 07:00 to 21:15\nPhotographs: Jarek Tuszyński and Valentin Chapuis, Wikimedia Commons (CC BY-SA 3.0)",
                    'lang' => 'en',
                ] + Sites::BASE],
                'is_active' => true,
            ]);

            $home = $this->page('site_khad_home', 'Home');
            $this->place($home, 'hero', 12, [
                'title' => 'Climb tonight. Book your slot now.',
                'subtitle' => 'Ninety minutes on the wall, shoes and chalk included.',
                'button_label' => 'See prices',
                'url' => '/prices',
                'tone' => 'light',
            ]);
            $this->place($home, 'schedule', 12, [
                'title' => 'Today, session by session',
                'meta_id' => $sessions->id,
                'time_field' => 'starts_at',
                'end_field' => 'ends_at',
                'name_field' => 'name',
                'detail_field' => 'area',
                'spaces_field' => 'spaces',
                'limit' => 8,
                'book_meta_id' => $form->id,
                'book_params' => 'session_id=id',
                'book_label' => 'Book',
                'empty_text' => 'No sessions today.',
            ]);
            $this->place($home, 'photo', 12, ['src' => self::PHOTOS['wall'], 'alt' => 'A climber halfway up a sunlit red rock wall above a canyon', 'caption' => 'Saturday trips take the wall outdoors once the rock is dry.', 'ratio' => '21:9', 'focus' => 'right']);
            $this->place($home, 'list', 12, ['title' => 'What a session includes', 'columns' => '1', 'items' => [
                ['title' => 'Shoes and chalk', 'text' => 'Every size from children’s 28 to 47, at the desk.'],
                ['title' => 'Fifty routes', 'text' => 'Graded 4 to 7b, a fifth of them reset each month.'],
                ['title' => 'Someone on the floor', 'text' => 'An instructor checks knots and belays every session.'],
            ]]);
            $this->place($home, 'chart', 12, ['meta_id' => $chart->id, 'title' => 'When the wall is quiet', 'height' => 'medium']);

            $prices = $this->page('site_khad_prices', 'Prices');
            $this->place($prices, 'heading', 12, ['text' => 'One price, shoes included', 'subtitle' => 'Pay at the desk, by card or QPay.', 'size' => 'large']);
            $this->place($prices, 'table', 12, [
                'title' => 'Tickets',
                'rows' => "Ticket | Adult | Under 18\nOne session | 18,000₮ | 10,000₮\nTen sessions | 150,000₮ | 85,000₮\nOne month | 120,000₮ | 70,000₮",
                'note' => 'Ten-session cards last a year from the first climb.',
            ]);
            $this->place($prices, 'table', 12, [
                'title' => 'Courses',
                'rows' => "Course | Length | Price\nFirst climb | One evening | 35,000₮\nBelay course | Two evenings | 90,000₮\nLead course | Four evenings | 180,000₮",
                'note' => 'Courses start on the first Monday of the month.',
            ]);

            $visit = $this->page('site_khad_visit', 'Visit');
            $this->place($visit, 'heading', 12, ['text' => 'Finding the wall', 'subtitle' => 'Olympic Street 12, behind the Central Sports Palace.', 'size' => 'large']);
            $this->place($visit, 'list', 12, ['columns' => '2', 'items' => [
                ['title' => 'By bus', 'text' => 'Routes 7 and 21 stop at the Sports Palace, two minutes away.'],
                ['title' => 'By car', 'text' => 'Free parking in the yard after 18:00.'],
            ]]);
            $this->place($visit, 'list', 12, ['title' => 'Bring', 'columns' => '3', 'items' => [
                ['title' => 'Clothes you can stretch in', 'text' => 'Changing rooms and lockers are free.'],
                ['title' => 'A water bottle', 'text' => 'There’s a tap by the boulder room.'],
                ['title' => 'Your ID', 'text' => 'For the first visit, to sign the waiver.'],
            ]]);
            $this->place($visit, 'tabs', 12, ['title' => 'Opening hours', 'items' => [
                ['label' => 'Weekdays', 'text' => "07:00 to 21:15.\n\nThe morning wall is quiet until 10:00; evenings fill from 18:00."],
                ['label' => 'Weekends', 'text' => "09:00 to 21:15.\n\nSaturday mornings belong to the kids' club until noon."],
                ['label' => 'Holidays', 'text' => "Closed on New Year's Day and for the three days of Tsagaan Sar and Naadam.\n\nOther public holidays keep weekend hours."],
            ]]);
            $this->place($visit, 'accordion', 12, ['title' => 'Questions', 'first_open' => true, 'items' => [
                ['title' => 'Do I need to have climbed before?', 'text' => 'No. Book a First climb course, or come to any session and staff will show you the bouldering room, where no rope is needed.'],
                ['title' => 'Can I rent shoes and a harness?', 'text' => 'Yes, both are included in the ticket. Chalk is 2,000₮ a bag at the desk.'],
                ['title' => 'How old do children have to be?', 'text' => 'Six and up, with an adult who stays in the hall. From fourteen they may climb alone after a belay course.'],
                ['title' => 'Can I bring a friend who only watches?', 'text' => 'Yes, watching is free. The café upstairs looks onto the lead wall.'],
            ]]);
            $this->place($visit, 'collapse', 12, ['label' => 'Read the waiver before you come', 'text' => "Climbing carries a risk of injury. By signing you confirm that you will follow the staff's instructions, check your knots and your partner's before every climb, and keep the landing zones clear.\n\nClimbers under eighteen need a parent or guardian to sign for them."]);
            $this->place($visit, 'photo', 12, ['src' => self::PHOTOS['ice'], 'alt' => 'A climber in pink swinging ice axes into a frozen waterfall', 'caption' => 'In January the club climbs ice instead.', 'ratio' => '16:9', 'focus' => 'center']);

            Sites::save($site, [
                'domains' => [['host' => 'khad.test', 'is_primary' => true]],
                'pages' => [
                    ['label' => 'Today', 'slug' => '', 'page_meta_id' => $home->id, 'in_menu' => true, 'title' => 'Khad Climbing · Book a session today', 'description' => 'Today\'s sessions on the Khad climbing wall in Ulaanbaatar, with spaces left. Shoes and chalk included.'],
                    ['label' => 'Prices', 'slug' => 'prices', 'page_meta_id' => $prices->id, 'in_menu' => true, 'title' => 'Prices and courses at Khad Climbing', 'description' => 'Single sessions, ten-session cards, monthly passes and courses, for adults and under 18s.'],
                    ['label' => 'Visit', 'slug' => 'visit', 'page_meta_id' => $visit->id, 'in_menu' => true, 'title' => 'Finding Khad Climbing', 'description' => 'Olympic Street 12 in Ulaanbaatar, by bus or car, and what to bring on your first visit.'],
                ],
            ], $this->admin() ?? new User);

            $this->publish($site);
        });
    }

    /** The timetable and a usual week; bookings are left to visitors. */
    private function data(): void
    {
        if (! DB::table('climb_sessions')->exists()) {
            DB::table('climb_sessions')->insert(array_map(fn ($s) => array_combine(['starts_at', 'ends_at', 'name', 'area', 'capacity'], $s), self::SESSIONS));
        }
        DB::table('climb_busy_hours')->delete();
        foreach (self::BUSY as $kind => $hours) {
            foreach ($hours as $hour => $climbers) {
                DB::table('climb_busy_hours')->insert(['day_kind' => $kind, 'hour' => (string) $hour, 'climbers' => $climbers]);
            }
        }
    }

    /** @return array{0: Meta, 1: Meta, 2: Meta} the sessions grid (public), the booking form (public) and the bookings grid */
    private function bookingMetas(): array
    {
        $sessions = Meta::updateOrCreate(['code' => 'climb_sessions_today'], [
            'name' => 'Today\'s climbing sessions',
            'type_id' => $this->types['datagrid'],
            'table_name' => null,
            'query_string' => 'SELECT s.id, s.starts_at, s.ends_at, s.name, s.area, s.capacity, '
                .'s.capacity - COALESCE((SELECT SUM(b.people) FROM climb_bookings b WHERE b.session_id = s.id AND b.booking_date = CURRENT_DATE), 0) AS spaces '
                .'FROM climb_sessions s',
            'description' => 'The daily timetable with the spaces left today.',
            'is_active' => true,
            'is_public' => true,
        ]);
        $this->params($sessions, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['starts_at', 'Starts', 'string', []],
            ['ends_at', 'Ends', 'string', []],
            ['name', 'Session', 'string', ['is_criteria' => true]],
            ['area', 'Where', 'string', []],
            ['capacity', 'Places', 'integer', []],
            ['spaces', 'Spaces left', 'integer', []],
        ]);

        $form = Meta::updateOrCreate(['code' => 'climb_booking_form'], [
            'name' => 'Book a session',
            'type_id' => $this->types['form'],
            'table_name' => 'climb_bookings',
            'description' => 'Pay at the desk when you arrive. We email if a session is cancelled.',
            'is_active' => true,
            'is_public' => true,
        ]);
        $bookings = Meta::updateOrCreate(['code' => 'climb_bookings_grid'], [
            'name' => 'Climbing bookings',
            'type_id' => $this->types['datagrid'],
            'table_name' => 'climb_bookings',
            'description' => 'Sessions booked from the Khad Climbing website.',
            'is_active' => true,
        ]);
        $session = ['is_select' => true, 'ref_meta_id' => $sessions->id, 'render_type' => 'lookup', 'display_field' => 'name', 'is_required' => true];
        foreach ([$form, $bookings] as $meta) {
            $rows = [
                ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
                ['session_id', 'Session', 'integer', $session],
                ['booking_date', 'Day', 'date', ['is_required' => true, 'default_value' => '{today}']],
                ['name', 'Your name', 'string', ['is_required' => true, 'is_criteria' => true]],
                ['email', 'Email', 'string', ['is_required' => true, 'render_type' => 'email']],
                ['people', 'Climbers', 'integer', ['is_required' => true, 'min_value' => '1', 'max_value' => '6', 'default_value' => '1']],
            ];
            if ($meta === $bookings) {
                $rows[] = ['created_at', 'Booked', 'datetime', []];
            }
            $this->params($meta, $rows);
        }

        return [$sessions, $form, $bookings];
    }

    private function chart(): Meta
    {
        return Meta::updateOrCreate(['code' => 'climb_chart_busy_hours'], [
            'name' => 'Climbers on the wall, weekdays',
            'type_id' => $this->types['chart'],
            'table_name' => 'climb_busy_hours',
            'description' => 'How many climbers are usually on the wall each hour of a weekday.',
            'json_config' => [
                'type' => 'column',
                'x' => 'hour',
                'measures' => [['agg' => 'sum', 'column' => 'climbers', 'label' => 'Climbers']],
                'filters' => [['column' => 'day_kind', 'op' => '=', 'value' => 'weekday']],
                'sort' => 'label',
                'limit' => 24,
            ],
            'is_active' => true,
            'is_public' => true,
        ]);
    }

    /** Replaces a meta's params. Each row: [field_path, label, data_type, extra attributes]. */
    private function params(Meta $meta, array $rows): void
    {
        MetaParam::where('main_meta_id', $meta->id)->delete();
        foreach ($rows as $i => [$field, $label, $type, $extra]) {
            MetaParam::create(['main_meta_id' => $meta->id, 'order_number' => $i + 1, 'field_path' => $field, 'param_name' => $field, 'label_name' => $label, 'data_type' => $type] + $extra);
        }
    }

    private function page(string $code, string $label): Meta
    {
        $page = Meta::updateOrCreate(['code' => $code], [
            'name' => "{$label} · Khad Climbing website",
            'label_name' => $label,
            'type_id' => $this->types['page'],
            'description' => 'A page of the Khad Climbing demo website.',
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
            $this->command?->warn('No administrator yet: the Khad Climbing pages stay drafts. Publish them in the website manager.');

            return;
        }
        foreach (Sites::entries($site)->sortByDesc(fn (MetaParam $e) => ($e->json_config['slug'] ?? '') !== '') as $entry) {
            if (! SiteRelease::where('param_id', $entry->id)->exists()) {
                Sites::publish($site, $entry, $admin, null, null);
            }
        }
    }

    private function admin(): ?User
    {
        return User::whereHas('roles', fn ($q) => $q->where('code', Role::ADMIN))->orderBy('id')->first();
    }
}
