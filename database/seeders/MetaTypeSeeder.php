<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MetaTypeSeeder extends Seeder
{
    /**
     * The kinds of screen a meta can describe. The frontend draws a preview for each code.
     */
    public function run(): void
    {
        $types = [
            ['code' => 'app', 'name' => 'App', 'description' => 'An application with a menu that links to its screens.'],
            ['code' => 'datagrid', 'name' => 'Data grid', 'description' => 'A table of records with columns, sorting and filters.'],
            ['code' => 'form', 'name' => 'Form', 'description' => 'Fields for creating or editing one record.'],
            ['code' => 'calendar', 'name' => 'Calendar', 'description' => 'Records shown as events on a calendar.'],
            ['code' => 'gantt', 'name' => 'Gantt chart', 'description' => 'Records shown as bars on a timeline, like project tasks.'],
            ['code' => 'tabs', 'name' => 'Tabs', 'description' => 'Several screens grouped under tabs.'],
            ['code' => 'get', 'name' => 'Get', 'description' => 'A query that decides the values a form opens with.'],
            ['code' => 'workflow', 'name' => 'Workflow', 'description' => 'Statuses and the moves between them, for the records of a table.'],
            ['code' => 'dashboard', 'name' => 'Dashboard', 'description' => 'A layout of charts, each placed and sized on a grid.'],
            ['code' => 'chart', 'name' => 'Chart', 'description' => 'Numbers from a table or query, drawn as a chart or a single figure.'],
            ['code' => 'statement', 'name' => 'Statement', 'description' => 'Records grouped by columns, with subtotals and a grand total, to print or export.'],
            ['code' => 'report', 'name' => 'Report', 'description' => 'A printable document for one record, such as a purchase order.'],
            ['code' => 'excel_import', 'name' => 'Excel import', 'description' => 'Loads the rows of an Excel file into a table, each column mapped and checked by a row.'],
            ['code' => 'page', 'name' => 'Page', 'description' => 'A page built from widgets: headings, text, images, buttons, charts and grids.'],
            ['code' => 'widget', 'name' => 'Widget', 'description' => 'One widget from the library, with its settings, placed on pages.'],
            ['code' => 'notification', 'name' => 'Notification', 'description' => 'A message sent to people when records are added, changed or move to a status.'],
            ['code' => 'widget_template', 'name' => 'Widget template', 'description' => 'The parts of a composite widget, laid out like a page.'],
            ['code' => 'workspace', 'name' => 'Workspace', 'description' => 'One record and everything about it, such as a tender with its items, bidders and evaluation.'],
            ['code' => 'stage_query', 'name' => 'Stage query', 'description' => 'SQL that changes data (INSERT, UPDATE, DELETE) with named parameters, run by hand, after a form saves or on a schedule.'],
            ['code' => 'query', 'name' => 'Query', 'description' => 'A SELECT drawn by dragging tables and views, joining them and picking columns, filters, groups and order; grids and other queries read it.'],
            ['code' => 'theme', 'name' => 'Theme', 'description' => 'How the app looks: brand colour, greys, corners, font and size, in light and dark. Users pick one; one is the default, and an app can have its own.'],
            ['code' => 'matrix', 'name' => 'Matrix', 'description' => 'A value for each pair of records from two grids, such as each bidder’s price for each item.'],
            ['code' => 'website', 'name' => 'Website', 'description' => 'A public website: pages from the page builder with addresses, a menu, its own domains, publishing and visit counts.'],
        ];

        // meta_types has no timestamps, so upsert through the query builder. Safe to run again.
        DB::table('meta_types')->upsert(
            array_map(fn ($t) => $t + ['is_active' => true], $types),
            ['code'],
            ['name', 'description', 'is_active'],
        );
    }
}
