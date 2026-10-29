<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\MetaDataController;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Documents (invoices, orders, contracts, acts) printed from a report meta's
 * json_config.layout as PDF. A layout is a page in millimetres with a header and footer on
 * every page, and sections down the page between them:
 *
 *  - free: a band of the given height; its elements sit where they were dragged (x, y, w, h in mm)
 *  - table: a detail's lines, one row each, with chosen columns and totals; it runs on to the
 *    next page and repeats its heading there
 *  - text: flowing text, e.g. a contract's clauses, broken over pages as needed
 *
 * Elements: text (with {placeholders}), field (one value), image (an uploaded logo or
 * stamp), line, box, signature (who signs, a line, a name, an optional signature image)
 * and page (page numbers). Placeholders: {field}, {field|format}, {lines.count},
 * {lines.sum.amount}, {today}, {page} and {pages}; formats: text, number, integer, money,
 * date, datetime, words (the amount in words).
 */
class Documents
{
    public const SIZES = ['A4' => [210, 297], 'A5' => [148, 210], 'Letter' => [215.9, 279.4], 'Legal' => [215.9, 355.6]];

    public const FONTS = ['sans' => 'DejaVu Sans', 'serif' => 'DejaVu Serif', 'mono' => 'DejaVu Sans Mono'];

    public const ELEMENTS = ['text', 'field', 'image', 'line', 'box', 'signature', 'page'];

    public const FORMATS = ['text', 'number', 'integer', 'money', 'date', 'datetime', 'words'];

    public const DATE_FORMATS = ['Y-m-d', 'Y.m.d', 'd.m.Y', 'd/m/Y', 'm/d/Y'];

    /** Uploaded images: {uuid}.{ext} under this folder, per report meta. */
    public const ASSETS = 'document-assets';

    private const ASSET_NAME = '/^[0-9a-f-]{36}\.(png|jpe?g|gif)$/';

    private const MAX_RECORDS = 100;

    // ---------------------------------------------------------------- layout

    /** A layout checked and filled in: unknown values give way to defaults, sizes are kept in range. */
    public static function normalize(array $layout): array
    {
        $page = (array) ($layout['page'] ?? []);
        $margin = (array) ($page['margin'] ?? []);

        return [
            'page' => [
                'size' => array_key_exists($page['size'] ?? '', self::SIZES) ? $page['size'] : 'A4',
                'orientation' => ($page['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait',
                'margin' => array_map(fn ($side) => self::num($margin[$side] ?? 15, 0, 60), ['top' => 'top', 'right' => 'right', 'bottom' => 'bottom', 'left' => 'left']),
                'font' => array_key_exists($page['font'] ?? '', self::FONTS) ? $page['font'] : 'sans',
                'font_size' => self::num($page['font_size'] ?? 9, 5, 24),
                'date_format' => in_array($page['date_format'] ?? '', self::DATE_FORMATS, true) ? $page['date_format'] : 'Y-m-d',
                'decimals' => (int) self::num($page['decimals'] ?? 2, 0, 4),
                'thousands' => in_array($page['thousands'] ?? ',', [',', ' ', '.', "'", ''], true) ? ($page['thousands'] ?? ',') : ',',
                'decimal_point' => ($page['decimal_point'] ?? '.') === ',' ? ',' : '.',
                'currency' => self::str($page['currency'] ?? '₮', 10),
                'words_language' => ($page['words_language'] ?? 'mn') === 'en' ? 'en' : 'mn',
                'currency_word' => self::str($page['currency_word'] ?? 'төгрөг', 30),
                'subunit_word' => self::str($page['subunit_word'] ?? 'мөнгө', 30),
            ],
            'header' => self::band($layout['header'] ?? [], 0, 120),
            'footer' => self::band($layout['footer'] ?? [], 0, 120),
            'sections' => array_values(array_filter(array_map(self::section(...), array_slice((array) ($layout['sections'] ?? []), 0, 40)))),
        ];
    }

    private static function band(mixed $band, float $min, float $max): array
    {
        $band = (array) $band;

        $background = (array) ($band['background'] ?? []);
        $src = (string) ($background['src'] ?? '');

        return [
            'height' => self::num($band['height'] ?? 0, $min, $max),
            'elements' => self::elements($band['elements'] ?? []),
            // A picture behind the band: an imported PDF page, a letterhead or a printed form.
            // Without print it only guides placing things in the designer.
            'background' => preg_match(self::ASSET_NAME, $src) ? ['src' => $src, 'print' => (bool) ($background['print'] ?? false)] : null,
        ];
    }

    private static function section(mixed $s): ?array
    {
        $s = (array) $s;
        $id = self::id($s['id'] ?? null);

        return match ($s['kind'] ?? null) {
            'free' => ['id' => $id, 'kind' => 'free'] + self::band($s, 1, 600),
            'table' => [
                'id' => $id,
                'kind' => 'table',
                'detail' => self::str($s['detail'] ?? '', 64),
                'numbered' => (bool) ($s['numbered'] ?? true),
                'border' => (bool) ($s['border'] ?? true),
                'font_size' => self::num($s['font_size'] ?? 0, 0, 24),
                'header_bg' => self::color($s['header_bg'] ?? '#f2f4f7'),
                'totals_label' => self::str($s['totals_label'] ?? 'Total', 60),
                'columns' => array_values(array_map(fn ($c) => [
                    'id' => self::id($c['id'] ?? null),
                    'field' => self::str($c['field'] ?? '', 64),
                    'label' => self::str($c['label'] ?? '', 120),
                    'width' => self::num($c['width'] ?? 1, 0.1, 100),
                    'align' => self::align($c['align'] ?? 'left'),
                    'format' => self::format($c['format'] ?? 'text'),
                    'total' => (bool) ($c['total'] ?? false),
                ], array_map(fn ($c) => (array) $c, array_slice((array) ($s['columns'] ?? []), 0, 24)))),
            ],
            'text' => [
                'id' => $id,
                'kind' => 'text',
                'text' => self::str($s['text'] ?? '', 50000),
                'font_size' => self::num($s['font_size'] ?? 0, 0, 24),
                'align' => in_array($s['align'] ?? '', ['left', 'center', 'right', 'justify'], true) ? $s['align'] : 'justify',
                'space_before' => self::num($s['space_before'] ?? 2, 0, 50),
            ],
            default => null,
        };
    }

    private static function elements(mixed $elements): array
    {
        $out = [];
        foreach (array_slice((array) $elements, 0, 600) as $e) {
            $e = (array) $e;
            if (! in_array($e['type'] ?? null, self::ELEMENTS, true)) {
                continue;
            }
            $style = (array) ($e['style'] ?? []);
            $src = (string) ($e['src'] ?? '');
            $out[] = [
                'id' => self::id($e['id'] ?? null),
                'type' => $e['type'],
                'x' => self::num($e['x'] ?? 0, -50, 1000),
                'y' => self::num($e['y'] ?? 0, -50, 1000),
                'w' => self::num($e['w'] ?? 40, 0, 1000),
                'h' => self::num($e['h'] ?? 6, 0, 1000),
                'text' => self::str($e['text'] ?? '', 5000),
                'field' => self::str($e['field'] ?? '', 64),
                'label' => self::str($e['label'] ?? '', 120),
                'format' => self::format($e['format'] ?? 'text'),
                'src' => preg_match(self::ASSET_NAME, $src) ? $src : '',
                'style' => [
                    'font_size' => self::num($style['font_size'] ?? 0, 0, 72),
                    'bold' => (bool) ($style['bold'] ?? false),
                    'italic' => (bool) ($style['italic'] ?? false),
                    'underline' => (bool) ($style['underline'] ?? false),
                    'align' => self::align($style['align'] ?? 'left'),
                    'color' => self::color($style['color'] ?? '') ?: '#101828',
                    'bg' => self::color($style['bg'] ?? ''),
                    'border' => self::num($style['border'] ?? ($e['type'] === 'line' || $e['type'] === 'box' ? 0.5 : 0), 0, 5),
                    'border_color' => self::color($style['border_color'] ?? '') ?: '#344054',
                    'padding' => self::num($style['padding'] ?? 0, 0, 20),
                ],
            ];
        }

        return $out;
    }

    private static function num(mixed $v, float $min, float $max): float
    {
        return round(max($min, min($max, is_numeric($v) ? (float) $v : $min)), 2);
    }

    private static function str(mixed $v, int $max): string
    {
        return is_scalar($v) ? mb_substr((string) $v, 0, $max) : '';
    }

    private static function id(mixed $v): string
    {
        return is_string($v) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $v) ? $v : Str::random(8);
    }

    private static function color(mixed $v): string
    {
        return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : '';
    }

    private static function align(mixed $v): string
    {
        return in_array($v, ['left', 'center', 'right'], true) ? $v : 'left';
    }

    private static function format(mixed $v): string
    {
        return in_array($v, self::FORMATS, true) ? $v : 'text';
    }

    /**
     * A first layout for a report meta, from its params and its older settings (title,
     * number_field, issuer, note): heading, fields in two columns, each detail's lines,
     * totals, signatures and the note, on A4.
     */
    public static function starter(Meta $meta): array
    {
        $config = $meta->json_config ?? [];
        $params = MetaParam::where('main_meta_id', $meta->id)->where('is_show', true)->orderBy('order_number')->get();
        $number = $config['number_field'] ?? null;
        $width = 180;
        $el = fn (string $type, float $x, float $y, float $w, float $h, array $more = []) => ['id' => Str::random(8), 'type' => $type, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h] + $more;

        $heading = [];
        if (! empty($config['issuer'])) {
            $heading[] = $el('text', 0, 0, $width, 5, ['text' => $config['issuer'], 'style' => ['color' => '#667085']]);
        }
        $heading[] = $el('text', 0, 6, 120, 10, ['text' => $config['title'] ?? $meta->name, 'style' => ['font_size' => 18, 'bold' => true]]);
        if ($number) {
            $heading[] = $el('field', 120, 8, 60, 8, ['field' => $number, 'style' => ['font_size' => 12, 'bold' => true, 'align' => 'right']]);
        }
        $heading[] = $el('line', 0, 19, $width, 0);

        $fields = $params->filter(fn ($p) => ! in_array($p->data_type, ['detail', 'signature'], true) && $p->field_path !== $number && empty($p->json_config['total']))->values();
        $boxes = [];
        foreach ($fields as $i => $p) {
            $boxes[] = $el('field', ($i % 2) * 92, intdiv($i, 2) * 7, 88, 6, [
                'field' => $p->field_path,
                'label' => ($p->label_name ?: $p->field_path).':',
                'format' => match ($p->data_type) {
                    'date' => 'date',
                    'datetime' => 'datetime',
                    'decimal' => 'number',
                    default => 'text',
                },
            ]);
        }
        $sections = [['id' => Str::random(8), 'kind' => 'free', 'height' => 24, 'elements' => $heading]];
        if ($boxes) {
            $sections[] = ['id' => Str::random(8), 'kind' => 'free', 'height' => ceil(count($boxes) / 2) * 7 + 4, 'elements' => $boxes];
        }
        foreach ($params->where('data_type', 'detail') as $detail) {
            $lineParams = $detail->ref_meta_id
                ? MetaParam::where('main_meta_id', $detail->ref_meta_id)->where('is_show', true)->where('data_type', '!=', 'detail')->orderBy('order_number')->get()
                : collect();
            $numeric = fn ($p) => in_array($p->data_type, ['integer', 'decimal'], true) && ! $p->is_select;
            $columns = $lineParams->map(fn ($p) => [
                'id' => Str::random(8),
                'field' => $p->field_path,
                'label' => $p->label_name ?: $p->field_path,
                'width' => $numeric($p) ? 1 : 3,
                'align' => $numeric($p) ? 'right' : 'left',
                'format' => $p->data_type === 'decimal' ? 'number' : ($p->data_type === 'date' ? 'date' : 'text'),
                'total' => false,
            ])->values()->all();
            if (! empty($detail->json_config['amount'])) {
                $columns[] = ['id' => Str::random(8), 'field' => 'amount', 'label' => 'Amount', 'width' => 1.5, 'align' => 'right', 'format' => 'number', 'total' => true];
            }
            $sections[] = ['id' => Str::random(8), 'kind' => 'table', 'detail' => $detail->field_path, 'columns' => $columns];
        }
        $totals = $params->filter(fn ($p) => ! empty($p->json_config['total']))->values();
        if ($totals->isNotEmpty()) {
            $sections[] = ['id' => Str::random(8), 'kind' => 'free', 'height' => $totals->count() * 7 + 4, 'elements' => $totals->map(fn ($p, $i) => $el('field', 100, 3 + $i * 7, 80, 6, [
                'field' => $p->field_path,
                'label' => ($p->label_name ?: $p->field_path).':',
                'format' => 'number',
                'style' => ['bold' => true, 'align' => 'right'],
            ]))->all()];
        }
        $signatures = $params->where('data_type', 'signature')->values();
        if ($signatures->isNotEmpty()) {
            $sections[] = ['id' => Str::random(8), 'kind' => 'free', 'height' => ceil($signatures->count() / 2) * 18 + 6, 'elements' => $signatures->map(fn ($p, $i) => $el('signature', ($i % 2) * 92, 6 + intdiv($i, 2) * 18, 88, 14, [
                'label' => $p->label_name ?: $p->field_path,
            ]))->all()];
        }
        if (! empty($config['note'])) {
            $sections[] = ['id' => Str::random(8), 'kind' => 'text', 'text' => $config['note'], 'align' => 'left', 'space_before' => 6];
        }

        return self::normalize([
            'page' => ['size' => 'A4'],
            'footer' => ['height' => 8, 'elements' => [$el('page', 0, 3, $width, 5, ['text' => 'Page {page} of {pages}', 'style' => ['align' => 'right', 'color' => '#667085', 'font_size' => 7]])]],
            'sections' => $sections,
        ]);
    }

    /** The meta's layout, or a starter one while none is saved. */
    public static function layoutOf(Meta $meta): array
    {
        $layout = $meta->json_config['layout'] ?? null;

        return is_array($layout) ? self::normalize($layout) : self::starter($meta);
    }

    // ---------------------------------------------------------------- data

    /**
     * A record of the report meta as the document reads it: its values, the names its lookups
     * show, and each detail's lines with theirs (and each line's amount, where the detail has one).
     *
     * @return array{values: array, names: array, lines: array<string, list<array{values: array, names: array}>>}
     */
    public static function record(Meta $meta, string|int $id, ?User $user): array
    {
        return self::recordFrom(MetaParam::where('main_meta_id', $meta->id)->get(), MetaDataController::recordAs($user, $meta, $id));
    }

    /**
     * A row as the document reads it, named through these params (their lookups and details):
     * a saved record, or a form's values not saved yet.
     *
     * @param  Collection<int, MetaParam>  $params
     * @return array{values: array, names: array, lines: array<string, list<array{values: array, names: array}>>}
     */
    public static function recordFrom(Collection $params, array $row): array
    {
        $names = self::names($params, collect([$row]))[0] ?? [];

        $lines = [];
        foreach ($params->where('data_type', 'detail') as $detail) {
            $rows = $row[$detail->field_path] ?? [];
            $rows = is_array($rows) && ! array_is_list($rows) ? [$rows] : (array) $rows;
            $lineParams = $detail->ref_meta_id ? MetaParam::where('main_meta_id', $detail->ref_meta_id)->get() : collect();
            $lineNames = self::names($lineParams, collect($rows));
            $amount = $detail->json_config['amount'] ?? null;
            $lines[$detail->field_path] = array_map(function ($line, $i) use ($lineNames, $amount) {
                if (is_array($amount) && $amount && ! array_key_exists('amount', $line)) {
                    $line['amount'] = array_product(array_map(fn ($c) => (float) ($line[$c] ?? 0), $amount));
                }

                return ['values' => $line, 'names' => $lineNames[$i] ?? []];
            }, array_values($rows), array_keys(array_values($rows)));
        }

        return ['values' => $row, 'names' => $names, 'lines' => $lines];
    }

    /**
     * The names lookups show for each row: [row index => [field => name]].
     *
     * @param  Collection<int, MetaParam>  $params
     */
    private static function names(Collection $params, Collection $rows): array
    {
        $out = [];
        foreach ($params->filter(fn (MetaParam $p) => $p->is_select && $p->ref_meta_id) as $p) {
            $map = MetaDataController::lookupNames($p, $rows->map(fn ($r) => (object) $r));
            foreach ($rows->values() as $i => $r) {
                $v = $r[$p->field_path] ?? null;
                if ($v !== null && isset($map[$v])) {
                    $out[$i][$p->field_path] = $map[$v];
                }
            }
        }

        return $out;
    }

    /** A stand-in record for previewing a layout before any record exists: each field shows its name. */
    public static function sample(Meta $meta): array
    {
        $params = MetaParam::where('main_meta_id', $meta->id)->get();
        $values = $params->where('data_type', '!=', 'detail')->mapWithKeys(fn ($p) => [$p->field_path => '['.($p->label_name ?: $p->field_path).']'])->all();
        $lines = [];
        foreach ($params->where('data_type', 'detail') as $detail) {
            $lineParams = $detail->ref_meta_id ? MetaParam::where('main_meta_id', $detail->ref_meta_id)->get() : collect();
            $line = $lineParams->mapWithKeys(fn ($p) => [$p->field_path => in_array($p->data_type, ['integer', 'decimal', 'number'], true) ? 1 : '['.($p->label_name ?: $p->field_path).']'])->all();
            $lines[$detail->field_path] = array_fill(0, 3, ['values' => $line + ['amount' => 1], 'names' => []]);
        }

        return ['values' => $values, 'names' => [], 'lines' => $lines];
    }

    // ---------------------------------------------------------------- output

    /** One PDF of these records, each starting on a new page. */
    public static function pdf(Meta $meta, array $layout, array $records): string
    {
        abort_if(count($records) > self::MAX_RECORDS, 422, 'At most '.self::MAX_RECORDS.' records print at once.');
        $page = $layout['page'];
        [$w, $h] = self::SIZES[$page['size']];

        return Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isFontSubsettingEnabled' => true, 'defaultFont' => self::FONTS[$page['font']]])
            ->setPaper([0, 0, $w * 72 / 25.4, $h * 72 / 25.4], $page['orientation'])
            ->loadHTML(self::html($meta, $layout, $records))
            ->output();
    }

    /** The HTML the PDF is made from. */
    public static function html(Meta $meta, array $layout, array $records): string
    {
        $page = $layout['page'];
        $m = $page['margin'];
        $font = self::FONTS[$page['font']];
        $header = $layout['header'];
        $footer = $layout['footer'];
        $body = [];
        foreach (array_values($records) as $i => $record) {
            $body[] = '<div class="record"'.($i > 0 ? ' style="page-break-before: always"' : '').'>'.self::sections($meta, $layout, $record).'</div>';
        }
        // The header and footer show the first record's values; each record starts a page.
        $first = $records[0] ?? ['values' => [], 'names' => [], 'lines' => []];

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'@page { margin: '.($m['top'] + $header['height']).'mm '.$m['right'].'mm '.($m['bottom'] + $footer['height']).'mm '.$m['left'].'mm; }'
            ."body { font-family: '{$font}'; font-size: {$page['font_size']}pt; color: #101828; margin: 0; line-height: 1.3; }"
            .'.band { position: relative; width: 100%; }'
            .'.el { position: absolute; overflow: hidden; }'
            .'#header { position: fixed; left: 0; right: 0; top: -'.$header['height'].'mm; height: '.$header['height'].'mm; }'
            .'#footer { position: fixed; left: 0; right: 0; bottom: -'.$footer['height'].'mm; height: '.$footer['height'].'mm; }'
            .'.pg:after { content: counter(page); } .pgs:after { content: counter(pages); }'
            .'table.lines { width: 100%; border-collapse: collapse; } table.lines th { font-weight: bold; text-align: left; }'
            .'table.lines td, table.lines th { padding: 1.2mm 1.5mm; vertical-align: top; } table.lines tr { page-break-inside: avoid; }'
            .'thead { display: table-header-group; } .text-section p { margin: 0 0 1.5mm 0; }'
            .'</style></head><body>'
            .($header['height'] > 0 ? '<div id="header">'.self::backgroundHtml($meta, $header, $page).self::elementsHtml($meta, $header['elements'], $first, $page).'</div>' : '')
            .($footer['height'] > 0 ? '<div id="footer">'.self::backgroundHtml($meta, $footer, $page).self::elementsHtml($meta, $footer['elements'], $first, $page).'</div>' : '')
            .implode('', $body)
            .'</body></html>';
    }

    /** A band's background picture, when it is set to print, across the band. */
    private static function backgroundHtml(Meta $meta, array $band, array $page): string
    {
        $background = $band['background'] ?? null;
        if (! $background || ! $background['print']) {
            return '';
        }
        $width = self::contentWidth($page);

        return '<div class="el" style="left: 0; top: 0; width: '.$width.'mm; height: '.$band['height'].'mm">'.self::image($meta, $background['src'], $width, $band['height']).'</div>';
    }

    /** The width between the page's side margins, in mm. */
    public static function contentWidth(array $page): float
    {
        [$w, $h] = self::SIZES[$page['size']];

        return round(($page['orientation'] === 'landscape' ? $h : $w) - $page['margin']['left'] - $page['margin']['right'], 2);
    }

    private static function sections(Meta $meta, array $layout, array $record): string
    {
        $page = $layout['page'];
        $html = '';
        foreach ($layout['sections'] as $s) {
            $html .= match ($s['kind']) {
                'free' => '<div class="band" style="height: '.$s['height'].'mm; page-break-inside: avoid">'.self::backgroundHtml($meta, $s, $page).self::elementsHtml($meta, $s['elements'], $record, $page).'</div>',
                'table' => self::table($s, $record, $page),
                'text' => self::textSection($s, $record, $page),
            };
        }

        return $html;
    }

    private static function elementsHtml(Meta $meta, array $elements, array $record, array $page): string
    {
        $html = '';
        foreach ($elements as $e) {
            $st = $e['style'];
            $css = "left: {$e['x']}mm; top: {$e['y']}mm; width: {$e['w']}mm; height: {$e['h']}mm; text-align: {$st['align']}; color: {$st['color']};"
                .($st['font_size'] ? " font-size: {$st['font_size']}pt;" : '')
                .($st['bold'] ? ' font-weight: bold;' : '')
                .($st['italic'] ? ' font-style: italic;' : '')
                .($st['underline'] ? ' text-decoration: underline;' : '')
                .($st['padding'] ? " padding: {$st['padding']}mm;" : '');
            $border = $st['border'] ? "{$st['border']}pt solid {$st['border_color']}" : null;

            $html .= match ($e['type']) {
                'text' => '<div class="el" style="'.$css.self::boxCss($st, $border).'">'.self::lines(self::fill($e['text'], $record, $page)).'</div>',
                'field' => '<div class="el" style="'.$css.self::boxCss($st, $border).'">'
                    .($e['label'] !== '' ? '<span style="color: #667085; font-weight: normal">'.e($e['label']).' </span>' : '')
                    .self::lines(self::value($record, $e['field'], $e['format'], $page)).'</div>',
                'line' => '<div class="el" style="left: '.$e['x'].'mm; top: '.$e['y'].'mm; '.($e['h'] > $e['w']
                    ? 'height: '.$e['h'].'mm; width: 0; border-left: '.($border ?? "0.5pt solid {$st['border_color']}").';'
                    : 'width: '.$e['w'].'mm; height: 0; border-top: '.($border ?? "0.5pt solid {$st['border_color']}").';').'"></div>',
                'box' => '<div class="el" style="'.$css.self::boxCss($st, $border).'"></div>',
                'image' => '<div class="el" style="'.$css.'">'.self::image($meta, $e['src'], $e['w'], $e['h']).'</div>',
                'signature' => self::signature($meta, $e, $css, $record, $page),
                'page' => '<div class="el" style="'.$css.'">'.self::pageText($e['text'] ?: 'Page {page} of {pages}').'</div>',
            };
        }

        return $html;
    }

    private static function boxCss(array $st, ?string $border): string
    {
        return ($st['bg'] ? " background-color: {$st['bg']};" : '').($border ? " border: {$border};" : '');
    }

    /** A signature: who signs on the left, a line with the signature image over it, and the name under. */
    private static function signature(Meta $meta, array $e, string $css, array $record, array $page): string
    {
        $role = self::lines(self::fill($e['label'], $record, $page));
        $name = self::lines(self::fill($e['text'], $record, $page));
        $image = $e['src'] ? '<div style="position: absolute; left: 45%; top: 0; width: 30%; height: 70%">'.self::image($meta, $e['src'], $e['w'] * 0.3, $e['h'] * 0.7).'</div>' : '';

        return '<div class="el" style="'.$css.'">'
            .'<div style="position: absolute; left: 0; top: 0; width: 40%; bottom: 0; padding-top: '.max(0, $e['h'] * 0.35).'mm">'.$role.'</div>'
            .$image
            .'<div style="position: absolute; left: 42%; right: 0; top: '.($e['h'] * 0.62).'mm; border-top: 0.6pt solid #344054"></div>'
            .'<div style="position: absolute; left: 42%; right: 0; top: '.($e['h'] * 0.66).'mm; font-size: 0.85em; color: #475467; text-align: center">'.$name.'</div>'
            .'</div>';
    }

    private static function table(array $s, array $record, array $page): string
    {
        $lines = $record['lines'][$s['detail']] ?? [];
        $columns = $s['columns'];
        if (! $columns) {
            return '';
        }
        $weights = array_sum(array_column($columns, 'width')) ?: 1;
        $border = $s['border'] ? 'border: 0.5pt solid #98a2b3;' : 'border-bottom: 0.5pt solid #d0d5dd;';
        $size = $s['font_size'] ? "font-size: {$s['font_size']}pt;" : '';
        $html = '<table class="lines" style="'.$size.'"><thead><tr style="background-color: '.($s['header_bg'] ?: '#ffffff').'">';
        if ($s['numbered']) {
            $html .= '<th style="'.$border.' width: 7mm; text-align: center">№</th>';
        }
        foreach ($columns as $c) {
            $html .= '<th style="'.$border.' width: '.round($c['width'] / $weights * 100, 2).'%; text-align: '.$c['align'].'">'.e($c['label']).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        $sums = [];
        foreach ($lines as $n => $line) {
            $html .= '<tr>'.($s['numbered'] ? '<td style="'.$border.' text-align: center">'.($n + 1).'</td>' : '');
            foreach ($columns as $c) {
                $raw = $line['values'][$c['field']] ?? null;
                if ($c['total'] && is_numeric($raw)) {
                    $sums[$c['id']] = ($sums[$c['id']] ?? 0) + (float) $raw;
                }
                $html .= '<td style="'.$border.' text-align: '.$c['align'].'">'.self::lines(self::formatted($line['names'][$c['field']] ?? $raw, $c['format'], $page)).'</td>';
            }
            $html .= '</tr>';
        }
        if (array_filter(array_column($columns, 'total'))) {
            $html .= '<tr style="font-weight: bold">';
            $label = true;
            foreach (array_merge($s['numbered'] ? [null] : [], $columns) as $c) {
                $text = $c && $c['total'] ? self::formatted($sums[$c['id']] ?? 0, $c['format'] === 'text' ? 'number' : $c['format'], $page) : ($label ? $s['totals_label'] : '');
                if ($c === null || ! $c['total']) {
                    $label = false;
                }
                $html .= '<td style="'.$border.' text-align: '.($c['align'] ?? 'left').'">'.e($text).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    private static function textSection(array $s, array $record, array $page): string
    {
        $paragraphs = preg_split('/\n\s*\n/', self::fill($s['text'], $record, $page));

        return '<div class="text-section" style="margin-top: '.$s['space_before'].'mm; text-align: '.$s['align'].';'.($s['font_size'] ? " font-size: {$s['font_size']}pt;" : '').'">'
            .implode('', array_map(fn ($p) => '<p>'.self::lines($p).'</p>', $paragraphs)).'</div>';
    }

    private static function image(Meta $meta, string $src, float $w, float $h): string
    {
        if ($src === '' || ! Storage::disk('local')->exists(self::ASSETS."/{$meta->id}/{$src}")) {
            return '';
        }
        // dompdf reads PNG and GIF through GD; without it they are left out rather than failing the PDF.
        if (! extension_loaded('gd') && ! preg_match('/\.jpe?g$/', $src)) {
            return '';
        }
        $type = match (strtolower(pathinfo($src, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'gif' => 'image/gif', default => 'image/jpeg',
        };
        $data = base64_encode(Storage::disk('local')->get(self::ASSETS."/{$meta->id}/{$src}"));
        $size = @getimagesizefromstring(base64_decode($data));
        // Fits the box, keeping its shape.
        [$iw, $ih] = $size ? [$size[0], $size[1]] : [1, 1];
        $scale = min($w / max($iw, 1), $h / max($ih, 1));

        return '<img src="data:'.$type.';base64,'.$data.'" style="width: '.round($iw * $scale, 2).'mm; height: '.round($ih * $scale, 2).'mm">';
    }

    /** Page numbers: {page} and {pages} become counters the PDF fills in. */
    private static function pageText(string $text): string
    {
        return str_replace(['{page}', '{pages}'], ['<span class="pg"></span>', '<span class="pgs"></span>'], e($text));
    }

    /** Escaped text with its line breaks kept. */
    private static function lines(string $text): string
    {
        return nl2br(e($text), false);
    }

    /** Text with its {placeholders} filled from the record. */
    public static function fill(string $text, array $record, array $page): string
    {
        return preg_replace_callback('/\{([A-Za-z0-9_.]+)(?:\|([a-z]+))?\}/', function ($m) use ($record, $page) {
            [$all, $path] = $m;
            $format = $m[2] ?? null;
            if ($path === 'page' || $path === 'pages') {
                return $all;
            }
            if ($path === 'today' || $path === 'now') {
                return self::formatted(now()->format('Y-m-d H:i:s'), $format ?? ($path === 'today' ? 'date' : 'datetime'), $page);
            }
            // {lines.count}, {lines.sum.amount}
            if (preg_match('/^([A-Za-z0-9_]+)\.(count|sum)(?:\.([A-Za-z0-9_]+))?$/', $path, $agg) && isset($record['lines'][$agg[1]])) {
                $rows = $record['lines'][$agg[1]];
                $value = $agg[2] === 'count' ? count($rows) : array_sum(array_map(fn ($l) => (float) ($l['values'][$agg[3] ?? ''] ?? 0), $rows));

                return self::formatted($value, $format ?? ($agg[2] === 'count' ? 'integer' : 'number'), $page);
            }

            return self::value($record, $path, $format ?? 'text', $page);
        }, $text);
    }

    /** A field of the record as shown: its lookup's name, or its value, formatted. */
    private static function value(array $record, string $field, string $format, array $page): string
    {
        $raw = $record['names'][$field] ?? ($record['values'][$field] ?? null);

        return self::formatted(is_array($raw) ? null : $raw, $format, $page);
    }

    public static function formatted(mixed $v, string $format, array $page): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $number = fn (int $d) => number_format((float) $v, $d, $page['decimal_point'], $page['thousands']);

        return match ($format) {
            'number' => is_numeric($v) ? $number($page['decimals']) : (string) $v,
            'integer' => is_numeric($v) ? $number(0) : (string) $v,
            'money' => is_numeric($v) ? trim($number($page['decimals']).' '.$page['currency']) : (string) $v,
            'words' => is_numeric($v) ? NumberWords::amount($v, $page['words_language'], $page['currency_word'] ?: null, $page['subunit_word'] ?: null) : (string) $v,
            'date', 'datetime' => ($t = strtotime((string) $v)) ? date($page['date_format'].($format === 'datetime' ? ' H:i' : ''), $t) : (string) $v,
            default => is_bool($v) ? ($v ? '✓' : '') : (string) $v,
        };
    }

    /**
     * What each part of the layout shows for this record, as the PDF prints it, for a form's
     * document editor to draw the page with: by element id, a field's value or a text's filled
     * text (a signature: [who signs, name]); by section id, a text section's text or a lines
     * table's rows of cells and its totals row (null without totals). {page} and {pages} stay.
     *
     * @return array<string, string|array>
     */
    public static function resolved(array $layout, array $record): array
    {
        $page = $layout['page'];
        $out = [];
        $bands = array_merge([$layout['header'], $layout['footer']], array_filter($layout['sections'], fn ($s) => $s['kind'] === 'free'));
        foreach ($bands as $band) {
            foreach ($band['elements'] as $e) {
                $out[$e['id']] = match ($e['type']) {
                    'text' => self::fill($e['text'], $record, $page),
                    'field' => self::value($record, $e['field'], $e['format'], $page),
                    'signature' => [self::fill($e['label'], $record, $page), self::fill($e['text'], $record, $page)],
                    default => null,
                };
            }
        }
        foreach ($layout['sections'] as $s) {
            if ($s['kind'] === 'text') {
                $out[$s['id']] = self::fill($s['text'], $record, $page);
            } elseif ($s['kind'] === 'table') {
                $rows = [];
                $sums = [];
                foreach ($record['lines'][$s['detail']] ?? [] as $line) {
                    $rows[] = array_map(function ($c) use ($line, $page, &$sums) {
                        $raw = $line['values'][$c['field']] ?? null;
                        if ($c['total'] && is_numeric($raw)) {
                            $sums[$c['id']] = ($sums[$c['id']] ?? 0) + (float) $raw;
                        }

                        return self::formatted($line['names'][$c['field']] ?? (is_array($raw) ? null : $raw), $c['format'], $page);
                    }, $s['columns']);
                }
                $totals = array_filter(array_column($s['columns'], 'total'))
                    ? array_map(fn ($c) => $c['total'] ? self::formatted($sums[$c['id']] ?? 0, $c['format'] === 'text' ? 'number' : $c['format'], $page) : null, $s['columns'])
                    : null;
                $out[$s['id']] = ['rows' => $rows, 'totals' => $totals];
            }
        }

        return array_filter($out, fn ($v) => $v !== null);
    }

    /**
     * A record was saved through a form: each of the form's document fields keeps the PDF its
     * report prints now (json_config.keep_pdf false: not). A PDF that cannot be made leaves
     * the field as it was and does not undo the save.
     */
    public static function keepFor(Meta $form, string $table, int $recordId, ?User $user): void
    {
        foreach (MetaParam::where('main_meta_id', $form->id)->where('data_type', Contents::DOCUMENT)->whereNotNull('ref_meta_id')->get() as $param) {
            if (($param->json_config['keep_pdf'] ?? true) === false) {
                continue;
            }
            try {
                $report = Meta::find($param->ref_meta_id);
                if ($report?->type?->code !== 'report') {
                    continue;
                }
                $record = self::record($report, $recordId, $user);
                Contents::keep($form, $param, $table, $recordId, self::pdf($report, self::layoutOf($report), [$record]), self::fileName($report, $record), $user);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** The PDF's file name: "Purchase order PO-261021001.pdf". */
    public static function fileName(Meta $meta, array $record): string
    {
        $number = $meta->json_config['number_field'] ?? null;
        $value = $number ? ($record['values'][$number] ?? null) : ($record['values']['id'] ?? null);
        $name = trim(($meta->json_config['title'] ?? $meta->name).' '.(is_scalar($value) ? $value : ''));

        return preg_replace('/[\\\\\/:*?"<>|]+/', '-', $name).'.pdf';
    }
}
