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
 * A demo website introducing a fictional school, "Maple Hill School", built only from metas
 * (tables: 2026_12_03_000017_create_school_site_tables):
 *
 *   /                          Home        banner (Apply, Visit us), a photo, what we care about, links
 *   /about                     About       a short welcome; lists its sub pages
 *   ├─ /about/our-story                    how the school grew
 *   ├─ /about/staff                        the roles families meet (no names)
 *   └─ /about/facilities                   rooms and grounds, with a photo
 *   /learning                  Learning    the three stages; lists its sub pages
 *   ├─ /learning/primary                   ages 5 to 11
 *   ├─ /learning/middle                    ages 11 to 14
 *   ├─ /learning/high                      ages 14 to 18
 *   └─ /learning/clubs                     programs and clubs, with a photo
 *   /admissions                Admissions  steps, key dates (table), questions (accordion), enquiry form
 *   /events                    Events      upcoming events from school_events (public query grid)
 *   /contact                   Contact     address, office hours (tabs), visit booking form
 *
 * Both forms are public and tell administrators about each new entry. Look Garden, header
 * pill, footer columns, no theme (brand_accent off), no social links (none given). No figures,
 * quotes or awards on purpose: the school is fictional. Photos are Wikimedia Commons stand-ins
 * by Douglas P Perkins (CC BY 3.0), credited in the footer.
 *
 * Pages are published as the first administrator, linked pages first; publishing stops on any
 * floor or reflex finding, so the migration fails rather than putting out a page the checks refuse.
 */
return new class extends Migration
{
    private const SITE = 'site_school';

    private const PHOTOS = [
        'library' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/e/e4/Hitane_Elementary_School_library_2.jpg/1920px-Hitane_Elementary_School_library_2.jpg',
        'science' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/6/65/Kawauchi_Elementary_School_science_classroom_3.jpg/1920px-Kawauchi_Elementary_School_science_classroom_3.jpg',
        'art' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/9/91/Kawauchi_ES_art_classroom_2.jpg/1920px-Kawauchi_ES_art_classroom_2.jpg',
    ];

    /** @var array<string, int> */
    private array $types = [];

    private int $order = 0;

    public function up(): void
    {
        if (! Schema::hasTable('school_enquiries')) {
            return;
        }
        $this->types = DB::table('meta_types')->pluck('id', 'code')->all();
        // A database without the meta types (a fresh test database) has nothing to build on.
        if (array_diff(['datagrid', 'form', 'notification', 'page', 'website', 'widget'], array_keys($this->types))) {
            return;
        }

        DB::transaction(function () {
            [$enquiry, $enquiries] = $this->enquiryMetas();
            [$visit, $visits] = $this->visitMetas();
            $events = $this->eventMetas();
            $this->notification('school_notify_enquiry', 'New admissions enquiry', $enquiry, $enquiries,
                'Admissions enquiry from {parent_name}', '{email}, {stage}, starting {start_year}: {message}');
            $this->notification('school_notify_visit', 'New visit request', $visit, $visits,
                'Visit request from {name}', '{email}, {visit_date}, {people} people: {message}');

            $site = Meta::updateOrCreate(['code' => self::SITE], [
                'name' => 'Maple Hill School website',
                'type_id' => $this->types['website'],
                'description' => 'The demo website of Maple Hill School, a fictional school: about, learning, admissions, events and contact.',
                'is_active' => true,
            ]);

            $pages = $this->pages($enquiry, $visit, $events);
            $admin = $this->admin() ?? new User;

            Sites::save($site, [
                'site' => [
                    'title' => 'Maple Hill School',
                    'tagline' => 'Primary to high school, ages 5 to 18',
                    'description' => 'Maple Hill School is a day school for ages five to eighteen, on one green campus on Orchard Lane.',
                    'theme_meta_id' => null,
                    'look' => 'garden',
                    'brand_accent' => false,
                    'header' => 'pill',
                    'footer_style' => 'columns',
                    'footer' => "Maple Hill School · 14 Orchard Lane, Maple Hill\nA demo website for a fictional school.\nPhotographs: Douglas P Perkins, Wikimedia Commons (CC BY 3.0)",
                    'lang' => 'en',
                    'social' => [],
                ],
                'domains' => [['host' => 'school.test', 'is_primary' => true]],
                'pages' => [
                    ['label' => 'Home', 'slug' => '', 'page_meta_id' => $pages['home']->id, 'in_menu' => false, 'in_footer' => true,
                        'title' => 'Maple Hill School · Ages 5 to 18',
                        'description' => 'Maple Hill School is a day school for ages five to eighteen. Find out how we teach and how to join.'],
                    ['label' => 'About', 'slug' => 'about', 'page_meta_id' => $pages['about']->id, 'in_menu' => true, 'in_footer' => true,
                        'title' => 'About Maple Hill School',
                        'description' => 'Who we are: the story of Maple Hill School, the people you will meet, and our rooms and grounds.',
                        'children' => [
                            ['label' => 'Our story', 'slug' => 'our-story', 'page_meta_id' => $pages['story']->id, 'in_menu' => true, 'in_footer' => true,
                                'title' => 'Our story · Maple Hill School',
                                'description' => 'How a village primary school in an old farmhouse grew into a school for ages five to eighteen.'],
                            ['label' => 'Our staff', 'slug' => 'staff', 'page_meta_id' => $pages['staff']->id, 'in_menu' => true, 'in_footer' => true,
                                'title' => 'Our staff · Maple Hill School',
                                'description' => 'The people your family will meet at Maple Hill School, from the head of school to the office team.'],
                            ['label' => 'Facilities', 'slug' => 'facilities', 'page_meta_id' => $pages['facilities']->id, 'in_menu' => true, 'in_footer' => true,
                                'title' => 'Facilities · Maple Hill School',
                                'description' => 'The library, science and art rooms, sports hall, playing field and garden at Maple Hill School.'],
                        ]],
                    ['label' => 'Learning', 'slug' => 'learning', 'page_meta_id' => $pages['learning']->id, 'in_menu' => true, 'in_footer' => true,
                        'title' => 'Learning at Maple Hill School',
                        'description' => 'Three stages, primary, middle and high, and the programs and clubs that run alongside lessons.',
                        'children' => [
                            ['label' => 'Primary', 'slug' => 'primary', 'page_meta_id' => $pages['primary']->id, 'in_menu' => true, 'in_footer' => true,
                                'title' => 'Primary school, ages 5 to 11 · Maple Hill',
                                'description' => 'Primary at Maple Hill: reading, writing and number first, with plenty of time outdoors, for ages five to eleven.'],
                            ['label' => 'Middle', 'slug' => 'middle', 'page_meta_id' => $pages['middle']->id, 'in_menu' => true, 'in_footer' => true,
                                'title' => 'Middle school, ages 11 to 14 · Maple Hill',
                                'description' => 'Middle school at Maple Hill: specialist teachers, a tutor for each group, and projects across subjects.'],
                            ['label' => 'High', 'slug' => 'high', 'page_meta_id' => $pages['high']->id, 'in_menu' => true, 'in_footer' => true,
                                'title' => 'High school, ages 14 to 18 · Maple Hill',
                                'description' => 'High school at Maple Hill: exam courses, guidance on next steps, and time to lead clubs and projects.'],
                            ['label' => 'Programs and clubs', 'slug' => 'clubs', 'page_meta_id' => $pages['clubs']->id, 'in_menu' => true, 'in_footer' => true,
                                'title' => 'Programs and clubs · Maple Hill School',
                                'description' => 'Music, art, coding, sport, chess and the garden club: what runs after lessons at Maple Hill School.'],
                        ]],
                    ['label' => 'Admissions', 'slug' => 'admissions', 'page_meta_id' => $pages['admissions']->id, 'in_menu' => true, 'in_footer' => true,
                        'title' => 'Admissions · Maple Hill School',
                        'description' => 'How to join Maple Hill School: the steps, key dates for September 2027 entry, common questions and an enquiry form.'],
                    ['label' => 'Events', 'slug' => 'events', 'page_meta_id' => $pages['events']->id, 'in_menu' => true, 'in_footer' => true,
                        'title' => 'Events and term dates · Maple Hill School',
                        'description' => 'Open mornings, concerts, meetings and term dates coming up at Maple Hill School.'],
                    ['label' => 'Contact', 'slug' => 'contact', 'page_meta_id' => $pages['contact']->id, 'in_menu' => true, 'in_footer' => true,
                        'title' => 'Contact and visits · Maple Hill School',
                        'description' => 'Find Maple Hill School on Orchard Lane, see the office hours, and book a visit to the school.'],
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
            $ids = DB::table('metas')->where('code', 'like', 'site\_school%')->orWhere('code', 'like', 'school\_%')->pluck('id');
            DB::table('meta_notification_map')->whereIn('notification_id', $ids)->orWhereIn('meta_id', $ids)->delete();
            DB::table('meta_meta_map')->whereIn('src_meta_id', $ids)->orWhereIn('trg_meta_id', $ids)->delete();
            DB::table('meta_params')->whereIn('main_meta_id', $ids)->delete();
            DB::table('meta_params')->whereIn('ref_meta_id', $ids)->update(['ref_meta_id' => null]);
            DB::table('metas')->whereIn('ref_meta_id', $ids)->update(['ref_meta_id' => null]);
            DB::table('metas')->whereIn('id', $ids)->delete();
        });
    }

    /** @return array{0: Meta, 1: Meta} the public admissions enquiry form and the admins' grid of enquiries */
    private function enquiryMetas(): array
    {
        $form = Meta::updateOrCreate(['code' => 'school_enquiry_form'], [
            'name' => 'Admissions enquiry',
            'type_id' => $this->types['form'],
            'table_name' => 'school_enquiries',
            'description' => 'Tell us a little about your child. The admissions team replies by email.',
            'is_active' => true,
            'is_public' => true,
        ]);
        $grid = Meta::updateOrCreate(['code' => 'school_enquiries_grid'], [
            'name' => 'Admissions enquiries',
            'type_id' => $this->types['datagrid'],
            'table_name' => 'school_enquiries',
            'description' => 'Enquiries sent from the Maple Hill School website.',
            'is_active' => true,
        ]);
        $stage = ['render_type' => 'select', 'is_required' => true, 'json_config' => ['options' => ['Primary, ages 5 to 11', 'Middle, ages 11 to 14', 'High, ages 14 to 18', 'Not sure yet']]];
        $year = ['render_type' => 'select', 'json_config' => ['options' => ['September 2027', 'During this school year', 'Later']]];
        foreach ([$form, $grid] as $meta) {
            $rows = [
                ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
                ['parent_name', 'Your name', 'string', ['is_required' => true, 'is_criteria' => true]],
                ['email', 'Email', 'string', ['is_required' => true, 'render_type' => 'email', 'is_criteria' => true]],
                ['phone', 'Phone', 'string', []],
                ['child_name', 'Child’s first name', 'string', []],
                ['stage', 'Stage', 'string', $stage + ['is_criteria' => $meta === $grid]],
                ['start_year', 'Starting', 'string', $year + ['is_criteria' => $meta === $grid]],
                ['message', 'Anything you would like us to know', 'string', ['render_type' => 'textarea']],
            ];
            if ($meta === $grid) {
                $rows[] = ['created_at', 'Sent', 'datetime', []];
            }
            $this->params($meta, $rows);
        }

        return [$form, $grid];
    }

    /** @return array{0: Meta, 1: Meta} the public visit booking form and the admins' grid of requests */
    private function visitMetas(): array
    {
        $form = Meta::updateOrCreate(['code' => 'school_visit_form'], [
            'name' => 'Book a visit',
            'type_id' => $this->types['form'],
            'table_name' => 'school_visit_requests',
            'description' => 'Pick a weekday that suits you. The office confirms the time by email.',
            'is_active' => true,
            'is_public' => true,
        ]);
        $grid = Meta::updateOrCreate(['code' => 'school_visit_requests_grid'], [
            'name' => 'Visit requests',
            'type_id' => $this->types['datagrid'],
            'table_name' => 'school_visit_requests',
            'description' => 'School visits asked for from the Maple Hill School website.',
            'is_active' => true,
        ]);
        foreach ([$form, $grid] as $meta) {
            $rows = [
                ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
                ['name', 'Your name', 'string', ['is_required' => true, 'is_criteria' => true]],
                ['email', 'Email', 'string', ['is_required' => true, 'render_type' => 'email', 'is_criteria' => true]],
                ['phone', 'Phone', 'string', []],
                ['visit_date', 'Preferred day', 'date', ['is_required' => true, 'is_criteria' => $meta === $grid]],
                ['people', 'People coming', 'integer', ['is_required' => true, 'min_value' => '1', 'max_value' => '6', 'default_value' => '1']],
                ['message', 'Questions for the visit', 'string', ['render_type' => 'textarea']],
            ];
            if ($meta === $grid) {
                $rows[] = ['created_at', 'Sent', 'datetime', []];
            }
            $this->params($meta, $rows);
        }

        return [$form, $grid];
    }

    /** The upcoming events visitors see (public, from today on), and the admins' grid to keep them. */
    private function eventMetas(): Meta
    {
        $upcoming = Meta::updateOrCreate(['code' => 'school_events_upcoming'], [
            'name' => 'Upcoming school events',
            'type_id' => $this->types['datagrid'],
            'table_name' => null,
            'query_string' => 'SELECT e.id, e.title, e.starts_on, e.time_text, e.place, e.audience FROM school_events e WHERE e.starts_on >= CURRENT_DATE',
            'description' => 'School events from today on, for the website.',
            'is_active' => true,
            'is_public' => true,
        ]);
        $this->params($upcoming, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['title', 'Event', 'string', ['is_criteria' => true]],
            ['starts_on', 'Date', 'date', []],
            ['time_text', 'Time', 'string', []],
            ['place', 'Where', 'string', []],
            ['audience', 'For', 'string', []],
        ]);

        $all = Meta::updateOrCreate(['code' => 'school_events_grid'], [
            'name' => 'School events',
            'type_id' => $this->types['datagrid'],
            'table_name' => 'school_events',
            'description' => 'Every event on the Maple Hill School calendar. The website shows those from today on.',
            'is_active' => true,
        ]);
        $this->params($all, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['title', 'Event', 'string', ['is_required' => true, 'is_criteria' => true]],
            ['starts_on', 'Date', 'date', ['is_required' => true, 'is_criteria' => true]],
            ['time_text', 'Time', 'string', []],
            ['place', 'Where', 'string', []],
            ['audience', 'For', 'string', []],
        ]);

        return $upcoming;
    }

    /** Tells administrators when a visitor sends one of the site's forms. */
    private function notification(string $code, string $name, Meta $form, Meta $grid, string $title, string $body): void
    {
        $meta = Meta::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type_id' => $this->types['notification'],
            'description' => "Tells administrators when a visitor sends \"{$form->name}\" on the Maple Hill School website.",
            'json_config' => [
                'event' => 'created',
                'source_meta_id' => $form->id,
                'kind' => 'info',
                'title' => $title,
                'body' => $body,
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
    private function pages(Meta $enquiry, Meta $visit, Meta $events): array
    {
        $p = [];

        $p['home'] = $this->page('site_school_home', 'Home');
        $this->place($p['home'], 'hero', 12, [
            'title' => 'A school where curious minds grow',
            'subtitle' => 'Primary to high school, on one green campus.',
            'button_label' => 'Apply',
            'url' => '/admissions',
            'tone' => 'light',
        ]);
        $this->place($p['home'], 'button', 12, ['label' => 'Visit us', 'url' => '/contact', 'style' => 'secondary', 'align' => 'left']);
        $this->place($p['home'], 'photo', 12, ['src' => self::PHOTOS['library'], 'alt' => 'A bright school library with rows of low wooden bookshelves full of children’s books', 'caption' => 'The library is open to every class, every day.', 'ratio' => '21:9', 'focus' => 'center']);
        $this->place($p['home'], 'list', 12, ['title' => 'What we care about', 'columns' => '3', 'items' => [
            ['title' => 'Knowing every child', 'text' => 'Each child has a teacher or tutor who knows them well.'],
            ['title' => 'Learning by doing', 'text' => 'Experiments, projects and trips, not only worksheets.'],
            ['title' => 'Time outdoors', 'text' => 'A garden and a playing field, used in all weathers.'],
        ]]);
        $this->place($p['home'], 'links', 12, ['title' => 'Find out more', 'items' => [
            ['label' => 'About us', 'description' => 'Our story, our staff and our rooms.', 'url' => '/about'],
            ['label' => 'Learning', 'description' => 'Primary, middle and high school, and clubs.', 'url' => '/learning'],
            ['label' => 'Admissions', 'description' => 'Steps, key dates and an enquiry form.', 'url' => '/admissions'],
            ['label' => 'Events', 'description' => 'Open mornings, concerts and term dates.', 'url' => '/events'],
        ]]);

        $p['about'] = $this->page('site_school_about', 'About');
        $this->place($p['about'], 'heading', 12, ['text' => 'About Maple Hill School', 'subtitle' => 'A day school for ages five to eighteen.', 'size' => 'large']);
        $this->place($p['about'], 'text', 12, ['text' => "Maple Hill keeps primary, middle and high school on one campus, so brothers and sisters share a school gate and children move up with friends.\n\nRead how the school began, meet the people who run it, and look around the rooms and grounds."]);

        $p['story'] = $this->page('site_school_story', 'Our story');
        $this->place($p['story'], 'heading', 12, ['text' => 'Our story', 'subtitle' => 'From a village primary to a school for all ages.', 'size' => 'large']);
        $this->place($p['story'], 'text', 12, ['text' => "Maple Hill began as a small primary school in an old farmhouse on Orchard Lane, with a vegetable garden out the back.\n\nAs its first children grew up, families asked it to grow with them. A middle school followed, then a high school, built around the same garden.\n\nThe idea has stayed the same: know every child by name, and help each one find what they love to learn."]);

        $p['staff'] = $this->page('site_school_staff', 'Our staff');
        $this->place($p['staff'], 'heading', 12, ['text' => 'Our staff', 'subtitle' => 'The people your family will meet.', 'size' => 'large']);
        $this->place($p['staff'], 'list', 12, ['columns' => '2', 'items' => [
            ['title' => 'Head of school', 'text' => 'Leads the school and meets every new family.'],
            ['title' => 'Heads of stage', 'text' => 'One each for primary, middle and high: your first contact about learning.'],
            ['title' => 'Class teachers and tutors', 'text' => 'See your child every day and write to you each term.'],
            ['title' => 'Learning support', 'text' => 'Help children who need extra support or more challenge.'],
            ['title' => 'School nurse', 'text' => 'Looks after health, medicines and first aid.'],
            ['title' => 'Office team', 'text' => 'Answers questions, arranges visits and handles admissions.'],
        ]]);

        $p['facilities'] = $this->page('site_school_facilities', 'Facilities');
        $this->place($p['facilities'], 'heading', 12, ['text' => 'Facilities', 'subtitle' => 'Rooms and grounds for learning and play.', 'size' => 'large']);
        $this->place($p['facilities'], 'photo', 12, ['src' => self::PHOTOS['science'], 'alt' => 'A science room with wide wooden work tables, a green chalkboard and a glass-fronted cupboard of equipment', 'caption' => 'Science rooms have tables built for experiments.', 'ratio' => '16:9', 'focus' => 'center']);
        $this->place($p['facilities'], 'list', 12, ['columns' => '2', 'items' => [
            ['title' => 'Library', 'text' => 'Picture books to reference shelves, with quiet corners to read.'],
            ['title' => 'Science rooms', 'text' => 'Work tables, sinks and equipment for hands-on lessons.'],
            ['title' => 'Art and music rooms', 'text' => 'Space to paint, build, sing and practise.'],
            ['title' => 'Sports hall and field', 'text' => 'For PE, team games and sports day.'],
            ['title' => 'Garden', 'text' => 'Beds each class plants, tends and harvests.'],
            ['title' => 'Dining hall', 'text' => 'Hot lunches cooked on site, with vegetarian choices.'],
        ]]);

        $p['learning'] = $this->page('site_school_learning', 'Learning');
        $this->place($p['learning'], 'heading', 12, ['text' => 'Learning at Maple Hill', 'subtitle' => 'Three stages, one way of teaching.', 'size' => 'large']);
        $this->place($p['learning'], 'list', 12, ['columns' => '3', 'items' => [
            ['title' => 'Primary, ages 5 to 11', 'text' => 'Reading, writing and number first, with lots of play and time outside.'],
            ['title' => 'Middle, ages 11 to 14', 'text' => 'Specialist teachers, a tutor for each group, and projects across subjects.'],
            ['title' => 'High, ages 14 to 18', 'text' => 'Exam courses, guidance on next steps, and room to lead.'],
        ]]);

        $p['primary'] = $this->page('site_school_primary', 'Primary');
        $this->place($p['primary'], 'heading', 12, ['text' => 'Primary, ages 5 to 11', 'subtitle' => 'Strong foundations, taught with care.', 'size' => 'large']);
        $this->place($p['primary'], 'list', 12, ['columns' => '2', 'items' => [
            ['title' => 'Reading and writing', 'text' => 'Daily phonics and stories, then writing for real readers.'],
            ['title' => 'Number', 'text' => 'Hands-on maths that builds confidence step by step.'],
            ['title' => 'Discovering the world', 'text' => 'Science, history and geography through topics and trips.'],
            ['title' => 'Making and moving', 'text' => 'Art, music, PE and a weekly session in the garden.'],
        ]]);

        $p['middle'] = $this->page('site_school_middle', 'Middle');
        $this->place($p['middle'], 'heading', 12, ['text' => 'Middle, ages 11 to 14', 'subtitle' => 'More subjects, the same care.', 'size' => 'large']);
        $this->place($p['middle'], 'list', 12, ['columns' => '2', 'items' => [
            ['title' => 'Specialist teachers', 'text' => 'Each subject taught by someone who loves it.'],
            ['title' => 'A tutor for each group', 'text' => 'Starts each day with the group and keeps in touch with home.'],
            ['title' => 'Projects', 'text' => 'Each term ends with a project that joins several subjects.'],
            ['title' => 'A second language', 'text' => 'Begun in middle school and kept up to the end of high school.'],
        ]]);

        $p['high'] = $this->page('site_school_high', 'High');
        $this->place($p['high'], 'heading', 12, ['text' => 'High, ages 14 to 18', 'subtitle' => 'Exam courses and next steps.', 'size' => 'large']);
        $this->place($p['high'], 'list', 12, ['columns' => '2', 'items' => [
            ['title' => 'Choosing courses', 'text' => 'Students pick their subjects with their tutor and family.'],
            ['title' => 'Study skills', 'text' => 'Planning, revision and independent study, taught on purpose.'],
            ['title' => 'Next steps', 'text' => 'One-to-one guidance on university, college, training and work.'],
            ['title' => 'Leading', 'text' => 'Older students run clubs, mentor younger ones and sit on the school council.'],
        ]]);

        $p['clubs'] = $this->page('site_school_clubs', 'Programs and clubs');
        $this->place($p['clubs'], 'heading', 12, ['text' => 'Programs and clubs', 'subtitle' => 'What happens after the last lesson.', 'size' => 'large']);
        $this->place($p['clubs'], 'photo', 12, ['src' => self::PHOTOS['art'], 'alt' => 'An art room with long white work tables, drawing posters on the walls and cubby shelves for materials', 'caption' => 'The art room stays open for the after-school studio.', 'ratio' => '16:9', 'focus' => 'center']);
        $this->place($p['clubs'], 'list', 12, ['columns' => '3', 'items' => [
            ['title' => 'Choir and band', 'text' => 'Rehearse weekly and play at the winter concert.'],
            ['title' => 'Art studio', 'text' => 'Painting, clay and printmaking.'],
            ['title' => 'Coding club', 'text' => 'Games, robots and small websites.'],
            ['title' => 'Football and athletics', 'text' => 'Training on the field, all year round.'],
            ['title' => 'Chess', 'text' => 'Beginners welcome, every lunchtime.'],
            ['title' => 'Garden club', 'text' => 'Grow, cook and share what the garden gives.'],
        ]]);
        $this->place($p['clubs'], 'text', 12, ['text' => 'Club times are shared at the start of each term.', 'size' => 'small']);

        $p['admissions'] = $this->page('site_school_admissions', 'Admissions');
        $this->place($p['admissions'], 'heading', 12, ['text' => 'Admissions', 'subtitle' => 'How to join Maple Hill, step by step.', 'size' => 'large']);
        $this->place($p['admissions'], 'list', 12, ['title' => 'Steps', 'columns' => '2', 'items' => [
            ['title' => '1. Visit us', 'text' => 'Come to an open morning or book a visit.'],
            ['title' => '2. Send an enquiry', 'text' => 'Use the form below; we reply by email.'],
            ['title' => '3. Meet us', 'text' => 'Your child spends a taster morning with their class.'],
            ['title' => '4. Get your offer', 'text' => 'We write with an offer and the next steps.'],
        ]]);
        $this->place($p['admissions'], 'table', 12, [
            'title' => 'Key dates for September 2027',
            'rows' => "Date | What\nSaturday 14 November 2026 | Open morning\nSaturday 23 January 2027 | Open morning\nFriday 26 February 2027 | Applications close\nFriday 26 March 2027 | Offers sent\nWednesday 1 September 2027 | First day of school",
            'note' => 'Places during the school year are offered when they come up.',
        ]);
        $this->place($p['admissions'], 'accordion', 12, ['title' => 'Common questions', 'first_open' => true, 'items' => [
            ['title' => 'Is there an entrance test?', 'text' => 'No. Children spend a taster morning with their class so we can get to know them.'],
            ['title' => 'Can my child join during the year?', 'text' => 'Yes, when a place is free. Send an enquiry and we will tell you what is open.'],
            ['title' => 'Do you support children with additional needs?', 'text' => 'Yes. Tell us in your enquiry, and our learning support team will meet you.'],
            ['title' => 'Is lunch provided?', 'text' => 'Yes, a hot lunch is cooked on site each day. Children may also bring their own.'],
        ]]);
        $this->place($p['admissions'], 'webform', 12, [
            'meta_id' => $enquiry->id,
            'title' => 'Send an enquiry',
            'subtitle' => 'The admissions team replies by email.',
            'submit_label' => 'Send enquiry',
            'done_text' => 'Thank you. We have your enquiry and will reply by email.',
        ]);

        $p['events'] = $this->page('site_school_events', 'Events');
        $this->place($p['events'], 'heading', 12, ['text' => 'Events and term dates', 'subtitle' => 'What is coming up at Maple Hill.', 'size' => 'large']);
        $this->place($p['events'], 'records', 12, [
            'title' => 'Coming up',
            'meta_id' => $events->id,
            'limit' => 12,
            'sort' => 'starts_on',
            'dir' => 'asc',
            'layout' => 'list',
            'lines' => [
                ['template' => '{title}', 'style' => 'title'],
                ['template' => '{starts_on} · {time_text}', 'style' => 'text'],
                ['template' => '{place} · {audience}', 'style' => 'muted'],
            ],
            'open_params' => '',
            'empty_text' => 'No events are planned yet. Check back soon.',
        ]);
        $this->place($p['events'], 'text', 12, ['text' => 'Open mornings need no booking. To see the school on another day, book a visit.']);
        $this->place($p['events'], 'button', 12, ['label' => 'Book a visit', 'url' => '/contact', 'style' => 'secondary', 'align' => 'left']);

        $p['contact'] = $this->page('site_school_contact', 'Contact');
        $this->place($p['contact'], 'heading', 12, ['text' => 'Contact and visits', 'subtitle' => 'Call, write or come and see us.', 'size' => 'large']);
        $this->place($p['contact'], 'list', 12, ['columns' => '2', 'items' => [
            ['title' => 'Address', 'text' => 'Maple Hill School, 14 Orchard Lane, Maple Hill'],
            ['title' => 'Phone and email', 'text' => '+1 555 0100 · office@school.test'],
        ]]);
        $this->place($p['contact'], 'tabs', 12, ['title' => 'Office hours', 'items' => [
            ['label' => 'Term time', 'text' => "Monday to Friday, 08:00 to 16:30.\n\nThe gates open at 08:15 for students."],
            ['label' => 'Holidays', 'text' => "Monday to Thursday, 09:00 to 13:00.\n\nThe office is closed on public holidays."],
            ['label' => 'Visits', 'text' => "Weekday visits start at 09:30 or 13:30 and take about an hour.\n\nA member of staff shows you round while lessons run."],
        ]]);
        $this->place($p['contact'], 'webform', 12, [
            'meta_id' => $visit->id,
            'title' => 'Book a visit',
            'subtitle' => 'We confirm the time by email.',
            'submit_label' => 'Ask for a visit',
            'done_text' => 'Thank you. We will email you to confirm the time.',
        ]);

        return $p;
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
            'name' => "{$label} · Maple Hill School website",
            'label_name' => $label,
            'type_id' => $this->types['page'],
            'description' => 'A page of the Maple Hill School demo website.',
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
     * (sub pages, then contact, events, admissions, learning, about, home). Any floor or reflex
     * finding stops the migration.
     */
    private function publish(Meta $site): void
    {
        $admin = $this->admin();
        if (! $admin) {
            return;
        }
        $rank = ['contact' => 1, 'events' => 2, 'admissions' => 3, 'learning' => 4, 'about' => 5, 'home' => 6];
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
