<?php

namespace Database\Seeders;

use App\Models\Widget;
use Illuminate\Database\Seeder;

/**
 * The page builder's built-in widget library. Each entry names a renderer and the props it
 * takes; the builder draws a settings form from the props. Admins can add their own
 * entries (e.g. a "Sales chart" preset of the chart renderer) on the Widgets page.
 *
 * Safe to run again: entries are matched by code. Entries admins added are left alone.
 */
class WidgetSeeder extends Seeder
{
    public function run(): void
    {
        $align = ['key' => 'align', 'label' => 'Alignment', 'type' => 'select', 'options' => ['left', 'center', 'right'], 'default' => 'left'];
        $link = [
            ['key' => 'meta_id', 'label' => 'Opens screen', 'type' => 'meta', 'hint' => 'A grid, form, report… opened in a window.'],
            ['key' => 'url', 'label' => 'Or opens link', 'type' => 'url'],
        ];

        $widgets = [
            // Layout
            ['section', 'Section', 'Layout', 'section', 'layout', 'A box that holds other widgets, with an optional title and background.', [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text'],
                ['key' => 'background', 'label' => 'Background', 'type' => 'select', 'options' => ['none', 'card', 'subtle', 'brand'], 'default' => 'card'],
            ]],
            ['divider', 'Divider', 'Layout', 'divider', 'minus', 'A thin line between parts of the page.', [
                ['key' => 'label', 'label' => 'Text on the line', 'type' => 'text'],
            ]],
            ['spacer', 'Spacer', 'Layout', 'spacer', 'expand', 'Empty space.', [
                ['key' => 'size', 'label' => 'Height', 'type' => 'select', 'options' => ['small', 'medium', 'large'], 'default' => 'medium'],
            ]],

            // Content
            ['hero', 'Banner', 'Content', 'hero', 'star', 'A large title and message with a button, to open a page.', [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'default' => 'Welcome'],
                ['key' => 'subtitle', 'label' => 'Message', 'type' => 'textarea'],
                ['key' => 'button_label', 'label' => 'Button', 'type' => 'text'],
                ...$link,
                ['key' => 'image', 'label' => 'Image', 'type' => 'image'],
                ['key' => 'tone', 'label' => 'Colour', 'type' => 'select', 'options' => ['brand', 'dark', 'light'], 'default' => 'brand'],
            ]],
            ['heading', 'Heading', 'Content', 'heading', 'type', 'A title, with an optional line under it.', [
                ['key' => 'text', 'label' => 'Text', 'type' => 'text', 'default' => 'Heading'],
                ['key' => 'subtitle', 'label' => 'Line under it', 'type' => 'text'],
                ['key' => 'size', 'label' => 'Size', 'type' => 'select', 'options' => ['large', 'medium', 'small'], 'default' => 'medium'],
                $align,
            ]],
            ['text', 'Text', 'Content', 'text', 'align-left', 'Paragraphs of text. A blank line starts a new paragraph.', [
                ['key' => 'text', 'label' => 'Text', 'type' => 'textarea', 'default' => 'Write something here.'],
                ['key' => 'size', 'label' => 'Size', 'type' => 'select', 'options' => ['small', 'normal', 'large'], 'default' => 'normal'],
                $align,
            ]],
            ['image', 'Image', 'Content', 'image', 'image', 'A picture from a web address.', [
                ['key' => 'src', 'label' => 'Image address', 'type' => 'image'],
                ['key' => 'alt', 'label' => 'Description', 'type' => 'text', 'hint' => 'Read out to people who can’t see the image.'],
                ['key' => 'caption', 'label' => 'Caption', 'type' => 'text'],
                ['key' => 'height', 'label' => 'Height (px)', 'type' => 'number', 'default' => 240],
                ['key' => 'fit', 'label' => 'Fit', 'type' => 'select', 'options' => ['cover', 'contain'], 'default' => 'cover'],
            ]],
            ['photo', 'Wide photo', 'Content', 'photo', 'image', 'A photograph across the whole width, cropped to a wide frame.', [
                ['key' => 'src', 'label' => 'Image address', 'type' => 'image'],
                ['key' => 'alt', 'label' => 'Description', 'type' => 'text', 'hint' => 'Read out to people who can’t see the image.'],
                ['key' => 'caption', 'label' => 'Caption', 'type' => 'text'],
                ['key' => 'ratio', 'label' => 'Frame', 'type' => 'select', 'options' => ['21:9', '16:9', '3:2'], 'default' => '21:9'],
                ['key' => 'focus', 'label' => 'Keep in view', 'type' => 'select', 'options' => ['center', 'top', 'bottom', 'left', 'right'], 'default' => 'center', 'hint' => 'The part of the photo the crop keeps.'],
            ]],
            ['table', 'Table', 'Content', 'table', 'table', 'Rows and columns you type in: prices, opening hours, sizes.', [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'subtitle', 'label' => 'Line under it', 'type' => 'text'],
                ['key' => 'rows', 'label' => 'Rows', 'type' => 'textarea', 'default' => "Item | Price\nFirst row | 0", 'hint' => 'One row per line, cells split by |. The first line is the heading row.'],
                ['key' => 'note', 'label' => 'Note under the table', 'type' => 'text'],
            ]],
            ['carousel', 'Carousel', 'Content', 'carousel', 'slides', 'Pictures one at a time, with Previous, Next and a dot each. Nothing moves by itself.', [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'ratio', 'label' => 'Frame', 'type' => 'select', 'options' => ['16:9', '21:9', '3:2', '4:3', '1:1'], 'default' => '16:9'],
                ['key' => 'slides', 'label' => 'Slides', 'type' => 'list', 'default' => [], 'fields' => [
                    ['key' => 'src', 'label' => 'Image address', 'type' => 'image'],
                    ['key' => 'alt', 'label' => 'Description', 'type' => 'text', 'hint' => 'Read out to people who can’t see the image.'],
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                    ['key' => 'text', 'label' => 'Caption', 'type' => 'text'],
                ]],
            ]],
            ['gallery', 'Gallery', 'Content', 'gallery', 'gallery', 'Pictures on a grid; one opens large when clicked.', [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'columns', 'label' => 'Columns', 'type' => 'select', 'options' => ['2', '3', '4'], 'default' => '3'],
                ['key' => 'images', 'label' => 'Pictures', 'type' => 'list', 'default' => [], 'fields' => [
                    ['key' => 'src', 'label' => 'Image address', 'type' => 'image'],
                    ['key' => 'alt', 'label' => 'Description', 'type' => 'text', 'hint' => 'Read out to people who can’t see the image.'],
                    ['key' => 'caption', 'label' => 'Caption', 'type' => 'text'],
                ]],
            ]],
            ['accordion', 'Accordion', 'Content', 'accordion', 'accordion', 'Questions or topics that open one at a time: an FAQ, opening terms.', [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'items', 'label' => 'Rows', 'type' => 'list', 'default' => [], 'fields' => [
                    ['key' => 'title', 'label' => 'Question or topic', 'type' => 'text'],
                    ['key' => 'text', 'label' => 'Answer', 'type' => 'textarea'],
                ]],
                ['key' => 'first_open', 'label' => 'First row starts open', 'type' => 'boolean', 'default' => false],
                ['key' => 'many', 'label' => 'Several may be open at once', 'type' => 'boolean', 'default' => false],
            ]],
            ['collapse', 'Show more', 'Content', 'collapse', 'collapse', 'Text hidden under one line until it is opened: small print, the long version.', [
                ['key' => 'label', 'label' => 'Line that opens it', 'type' => 'text', 'default' => 'Show more'],
                ['key' => 'text', 'label' => 'Text', 'type' => 'textarea'],
                ['key' => 'open', 'label' => 'Starts open', 'type' => 'boolean', 'default' => false],
            ]],
            ['tabs', 'Tabs', 'Content', 'tabs', 'tabs', 'A row of tabs, each showing its own text: by season, by audience, by plan.', [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'items', 'label' => 'Tabs', 'type' => 'list', 'default' => [], 'fields' => [
                    ['key' => 'label', 'label' => 'Tab', 'type' => 'text'],
                    ['key' => 'text', 'label' => 'Text', 'type' => 'textarea'],
                ]],
            ]],
            ['quote', 'Quote', 'Content', 'quote', 'quote', 'Words someone really said, with their name.', [
                ['key' => 'text', 'label' => 'Quote', 'type' => 'textarea'],
                ['key' => 'name', 'label' => 'Who said it', 'type' => 'text'],
                ['key' => 'role', 'label' => 'Their role or place', 'type' => 'text'],
            ]],
            ['callout', 'Callout', 'Content', 'callout', 'info', 'A coloured note that stands out.', [
                ['key' => 'tone', 'label' => 'Kind', 'type' => 'select', 'options' => ['info', 'success', 'warning', 'error'], 'default' => 'info'],
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'text', 'label' => 'Text', 'type' => 'textarea', 'default' => 'Something worth knowing.'],
            ]],
            ['list', 'Feature list', 'Content', 'list', 'list', 'Short points, each with a title and a line of text.', [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'columns', 'label' => 'Columns', 'type' => 'select', 'options' => ['1', '2', '3'], 'default' => '2'],
                ['key' => 'items', 'label' => 'Points', 'type' => 'list', 'default' => [['title' => 'First point', 'text' => 'Say why it matters.']], 'fields' => [
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                    ['key' => 'text', 'label' => 'Text', 'type' => 'text'],
                ]],
            ]],

            // Navigation
            ['button', 'Button', 'Navigation', 'button', 'cursor', 'A button that opens a screen or a link.', [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'default' => 'Open'],
                ...$link,
                ['key' => 'style', 'label' => 'Style', 'type' => 'select', 'options' => ['primary', 'secondary', 'link'], 'default' => 'primary'],
                $align,
            ]],
            ['links', 'Quick links', 'Navigation', 'links', 'link', 'Tiles that open screens or links.', [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'items', 'label' => 'Links', 'type' => 'list', 'default' => [], 'fields' => [
                    ['key' => 'label', 'label' => 'Label', 'type' => 'text'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'text'],
                    ...$link,
                ]],
            ]],

            // Data
            ['stat', 'Figure', 'Data', 'stat', 'hash', 'One number written in by hand, with a label and a change.', [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'default' => 'Figure'],
                ['key' => 'value', 'label' => 'Value', 'type' => 'text', 'default' => '0'],
                ['key' => 'change', 'label' => 'Change', 'type' => 'text', 'hint' => 'e.g. +12%'],
                ['key' => 'trend', 'label' => 'Trend', 'type' => 'select', 'options' => ['up', 'down', 'flat'], 'default' => 'flat'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'text'],
            ]],
            ['chart', 'Chart', 'Data', 'chart', 'chart', 'A chart meta, drawn live.', [
                ['key' => 'meta_id', 'label' => 'Chart', 'type' => 'meta', 'meta_types' => ['chart']],
                ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'hint' => 'Leave blank to use the chart’s own.'],
                ['key' => 'height', 'label' => 'Height', 'type' => 'select', 'options' => ['small', 'medium', 'large'], 'default' => 'medium'],
            ]],
            ['records', 'Record list', 'Data', 'records', 'list', 'Records from a grid, each drawn by lines you write with {field} placeholders.', [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'meta_id', 'label' => 'Records from', 'type' => 'meta', 'meta_types' => ['datagrid', 'statement', 'calendar', 'gantt'], 'hint' => 'A grid; its filters and permissions apply.'],
                ['key' => 'limit', 'label' => 'How many', 'type' => 'number', 'default' => 5],
                ['key' => 'sort', 'label' => 'Order by field', 'type' => 'text', 'hint' => 'A field of the grid; blank keeps its usual order.'],
                ['key' => 'dir', 'label' => 'Order', 'type' => 'select', 'options' => ['desc', 'asc'], 'default' => 'desc'],
                ['key' => 'layout', 'label' => 'Look', 'type' => 'select', 'options' => ['list', 'cards', 'table'], 'default' => 'list'],
                ['key' => 'columns', 'label' => 'Cards per row', 'type' => 'select', 'options' => ['1', '2', '3'], 'default' => '2'],
                ['key' => 'lines', 'label' => 'Each record shows', 'type' => 'list', 'default' => [['template' => '{name}', 'style' => 'title']], 'fields' => [
                    ['key' => 'template', 'label' => 'Text', 'type' => 'text', 'hint' => 'e.g. {po_number} · {supplier_name}'],
                    ['key' => 'style', 'label' => 'Style', 'type' => 'select', 'options' => ['title', 'text', 'muted', 'value', 'badge'], 'default' => 'text'],
                    ['key' => 'label', 'label' => 'Column heading', 'type' => 'text', 'hint' => 'For the table look.'],
                ]],
                ['key' => 'open_meta_id', 'label' => 'A record opens', 'type' => 'meta'],
                ['key' => 'open_params', 'label' => 'Passing', 'type' => 'text', 'default' => 'id=id', 'hint' => 'parameter=field, comma separated.'],
                ['key' => 'empty_text', 'label' => 'When there are none', 'type' => 'text', 'default' => 'Nothing here yet.'],
            ]],
            ['schedule', 'Schedule', 'Data', 'schedule', 'calendar', 'Times from a grid, each with what is on, the spaces left and a Book button.', [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'meta_id', 'label' => 'Times from', 'type' => 'meta', 'meta_types' => ['datagrid'], 'hint' => 'A grid, one record per time; its filters and permissions apply.'],
                ['key' => 'time_field', 'label' => 'Starts (field)', 'type' => 'text', 'default' => 'starts_at'],
                ['key' => 'end_field', 'label' => 'Ends (field)', 'type' => 'text', 'default' => 'ends_at'],
                ['key' => 'name_field', 'label' => 'What is on (field)', 'type' => 'text', 'default' => 'name'],
                ['key' => 'detail_field', 'label' => 'Detail (field)', 'type' => 'text'],
                ['key' => 'spaces_field', 'label' => 'Spaces left (field)', 'type' => 'text', 'hint' => 'At 0 the time shows as full and can’t be booked.'],
                ['key' => 'limit', 'label' => 'How many', 'type' => 'number', 'default' => 8],
                ['key' => 'book_meta_id', 'label' => 'Book opens', 'type' => 'meta', 'meta_types' => ['form']],
                ['key' => 'book_params', 'label' => 'Passing', 'type' => 'text', 'default' => 'session_id=id', 'hint' => 'parameter=field, comma separated.'],
                ['key' => 'book_label', 'label' => 'Button', 'type' => 'text', 'default' => 'Book'],
                ['key' => 'empty_text', 'label' => 'When there are none', 'type' => 'text', 'default' => 'Nothing on today.'],
            ]],
            ['datagrid', 'Data grid', 'Data', 'meta', 'table', 'A grid of records, with its filters and buttons.', [
                ['key' => 'meta_id', 'label' => 'Grid', 'type' => 'meta', 'meta_types' => ['datagrid']],
            ]],
            ['form', 'Form', 'Data', 'meta', 'form', 'A form to add a record.', [
                ['key' => 'meta_id', 'label' => 'Form', 'type' => 'meta', 'meta_types' => ['form']],
            ]],
            ['webform', 'Web form', 'Data', 'webform', 'send', 'A form drawn in the website’s own look, sent as a new record. Pages in the app show the usual form.', [
                ['key' => 'meta_id', 'label' => 'Form', 'type' => 'meta', 'meta_types' => ['form'], 'hint' => 'On a website it must be public.'],
                ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'hint' => 'Leave blank to use the form’s own name.'],
                ['key' => 'subtitle', 'label' => 'Line under it', 'type' => 'text', 'hint' => 'Leave blank to use the form’s description.'],
                ['key' => 'submit_label', 'label' => 'Button', 'type' => 'text', 'default' => 'Send'],
                ['key' => 'done_text', 'label' => 'After sending', 'type' => 'text', 'default' => 'Thank you. We have it and will be in touch.'],
            ]],
            ['screen', 'Any screen', 'Data', 'meta', 'layers', 'Any meta: a calendar, statement, dashboard, tabs…', [
                ['key' => 'meta_id', 'label' => 'Screen', 'type' => 'meta'],
            ]],
            ['iframe', 'Web page', 'Data', 'iframe', 'globe', 'Another web page or video, shown in a frame.', [
                ['key' => 'url', 'label' => 'Address', 'type' => 'url'],
                ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'default' => 'Embedded page'],
                ['key' => 'height', 'label' => 'Height (px)', 'type' => 'number', 'default' => 400],
            ]],
        ];

        foreach ($widgets as $i => [$code, $name, $category, $renderer, $icon, $description, $props]) {
            Widget::updateOrCreate(['code' => $code], [
                'name' => $name,
                'category' => $category,
                'renderer' => $renderer,
                'icon' => $icon,
                'description' => $description,
                'props' => $props,
                'is_active' => true,
                'order_number' => $i + 1,
            ]);
        }
    }
}
