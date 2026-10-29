<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\SiteDomain;

/**
 * What a website page is checked for before it is published, in three tiers:
 *  - floor: never goes out (an empty page, sample text or a builder placeholder left in, an
 *    image with no description, a screen visitors may not open, a link to no page, white
 *    text on a brand colour too light to read);
 *  - reflex: goes out only with a reason (no description for search engines, a title or
 *    description too long, a title another page has, shouting capitals, no or several main
 *    headings, a link to a page not published, the same form ending most pages);
 *  - finish: worth a look, never stops anything (no image for shared links, a short
 *    description, an http:// link, an image too tall for a phone).
 *
 * Each issue: {level, code, message, item?} where item is the widget's param_id on the page.
 */
class SiteChecks
{
    public const LEVELS = ['floor', 'reflex', 'finish'];

    /** Contrast white text needs on a coloured button (WCAG AA, normal text). */
    private const MIN_CONTRAST = 4.5;

    /** Renderers that show a meta, and the prop holding it. */
    private const META_PROPS = ['meta_id', 'open_meta_id', 'book_meta_id'];

    /**
     * @param  array{title: string, description: ?string, image: ?string, items: array, templates: array}  $snapshot
     * @return list<array{level: string, code: string, message: string, item?: int}>
     */
    public static function page(Meta $site, MetaParam $entry, array $snapshot): array
    {
        $entries = Sites::entries($site);
        $paths = Sites::paths($entries);
        $live = Sites::live($site);
        $issues = [];
        $add = function (string $level, string $code, string $message, ?int $item = null) use (&$issues) {
            $issues[] = ['level' => $level, 'code' => $code, 'message' => $message] + ($item ? ['item' => $item] : []);
        };

        $all = self::flatten($snapshot['items']);
        foreach ($snapshot['templates'] as $parts) {
            foreach (self::flatten($parts) as $part) {
                $all[] = $part;
            }
        }
        if ($snapshot['items'] === []) {
            $add('floor', 'empty', __('The page has nothing on it yet. Add widgets in the page builder.'));
        }

        $metas = Meta::with('type')->whereIn('id', self::metaIds($snapshot))->get()->keyBy('id');
        $brand = Sites::theme(Sites::settings($site)['theme_meta_id'])['brand'] ?? Themes::BASE['brand'];
        $brandButton = false;
        $mainHeadings = 0;

        foreach ($all as $item) {
            $w = $item['widget'];
            $v = self::values($item);
            $id = $item['param_id'] ?? null;
            $name = $item['label'] ?: $w['name'];

            // Sample text from the library, and placeholders the builder shows for missing settings.
            foreach ($w['props'] ?? [] as $prop) {
                $default = $prop['default'] ?? null;
                if (is_string($default) && $default !== '' && ($prop['type'] === 'textarea' || ($w['renderer'] === 'heading' && $prop['key'] === 'text')) && ($v[$prop['key']] ?? null) === $default) {
                    $add('floor', 'sample_text', __('":name" still says the sample text ":text".', ['name' => $name, 'text' => $default]), $id);
                }
                if ($prop['type'] === 'list' && is_array($default) && $default !== [] && ($v[$prop['key']] ?? null) === $default) {
                    $add('floor', 'sample_text', __('":name" still shows its sample points.', ['name' => $name]), $id);
                }
            }
            $placeholder = match ($w['renderer']) {
                'image', 'photo' => self::str($v['src'] ?? null) === '',
                'carousel' => self::pictures($v['slides'] ?? null) === [],
                'gallery' => self::pictures($v['images'] ?? null) === [],
                'accordion' => collect($v['items'] ?? [])->filter(fn ($i) => self::str($i['title'] ?? null) !== '')->isEmpty(),
                'tabs' => collect($v['items'] ?? [])->filter(fn ($i) => self::str($i['label'] ?? null) !== '')->isEmpty(),
                'collapse', 'quote' => trim(self::str($v['text'] ?? null)) === '',
                'table' => trim(self::str($v['rows'] ?? null)) === '',
                'chart', 'meta', 'records', 'schedule', 'webform' => ! ((int) ($v['meta_id'] ?? 0) > 0),
                'links' => collect($v['items'] ?? [])->filter(fn ($l) => self::str($l['label'] ?? null) !== '')->isEmpty(),
                'iframe' => ! preg_match('#^https://#i', self::str($v['url'] ?? null)),
                default => false,
            };
            if ($placeholder) {
                $add('floor', 'placeholder', __('":name" isn\'t set up; visitors would see the builder\'s placeholder.', ['name' => $name]), $id);
            }
            if (in_array($w['renderer'], ['image', 'photo'], true) && self::str($v['src'] ?? null) !== '' && self::str($v['alt'] ?? null) === '') {
                $add('floor', 'image_alt', __('The image ":name" has no description for people who can\'t see it.', ['name' => $name]), $id);
            }
            foreach (in_array($w['renderer'], ['carousel', 'gallery'], true) ? self::pictures($v[$w['renderer'] === 'carousel' ? 'slides' : 'images'] ?? null) : [] as $n => $picture) {
                if (self::str($picture['alt'] ?? null) === '') {
                    $add('floor', 'image_alt', __('Picture :n of ":name" has no description for people who can\'t see it.', ['n' => $n + 1, 'name' => $name]), $id);
                }
            }
            if ($w['renderer'] === 'quote' && trim(self::str($v['text'] ?? null)) !== '' && self::str($v['name'] ?? null) === '') {
                $add('reflex', 'quote_unnamed', __('The quote in ":name" doesn\'t say who said it. Name a real person, or leave the quote out.', ['name' => $name]), $id);
            }
            if ($w['renderer'] === 'image' && (int) ($v['height'] ?? 0) > 600) {
                $add('finish', 'tall_image', __('The image ":name" is :height px tall; on a phone it fills more than the screen.', ['name' => $name, 'height' => (int) $v['height']]), $id);
            }

            // Screens it shows or opens: visitors are guests, so they must be public.
            foreach (self::metaRefs($v) as $metaId) {
                $meta = $metas->get($metaId);
                if (! $meta) {
                    $add('floor', 'missing_meta', __('":name" points at a screen that no longer exists.', ['name' => $name]), $id);
                } elseif (! $meta->is_public) {
                    $add('floor', 'not_public', __('Visitors can\'t open ":meta" (on ":name"): it isn\'t public. Make it public on its meta page.', ['meta' => $meta->name, 'name' => $name]), $id);
                }
            }

            // Links within the site.
            foreach (self::urls($v) as $url) {
                if (str_starts_with($url, '/')) {
                    $target = Sites::cleanPath(preg_replace('/[?#].*$/', '', $url));
                    $paramId = array_search($target, $paths, true);
                    if ($paramId === false) {
                        $add('floor', 'broken_link', __('":name" links to :url, which isn\'t a page of this site.', ['name' => $name, 'url' => $url]), $id);
                    } elseif ($paramId !== $entry->id && ! isset($live[$paramId])) {
                        $add('reflex', 'unpublished_link', __('":name" links to :url, which isn\'t published yet.', ['name' => $name, 'url' => $url]), $id);
                    }
                } elseif (preg_match('#^http://#i', $url)) {
                    $add('finish', 'insecure_link', __('":name" links to :url without https.', ['name' => $name, 'url' => $url]), $id);
                }
            }

            // Primary buttons, a form's Send among them, are white text on the brand colour.
            if (($w['renderer'] === 'button' && ($v['style'] ?? 'primary') === 'primary')
                || (in_array($w['renderer'], ['meta', 'webform'], true) && (int) ($v['meta_id'] ?? 0) > 0)
                || ($w['renderer'] === 'hero' && ($v['tone'] ?? '') === 'light' && self::str($v['button_label'] ?? null) !== '')) {
                $brandButton = true;
            }

            // Shouting: titles in capitals read as tracked labels.
            foreach (['title', 'text', 'subtitle'] as $key) {
                $text = self::str($v[$key] ?? null);
                if (in_array($w['renderer'], ['heading', 'hero', 'section'], true) && self::shouting($text)) {
                    $add('reflex', 'shouting', __('":text" is all capitals; write it as a sentence.', ['text' => $text]), $id);
                }
            }
        }

        foreach (self::flatten($snapshot['items'], withDepth: true) as [$item, $depth]) {
            $v = self::values($item);
            if ($item['widget']['renderer'] === 'hero' || ($item['widget']['renderer'] === 'heading' && $depth === 0 && ($v['size'] ?? '') === 'large')) {
                $mainHeadings++;
            }
        }
        if ($snapshot['items'] !== [] && $mainHeadings === 0) {
            $add('reflex', 'no_main_heading', __('The page has no main heading: add a banner or a large heading at the top.'));
        } elseif ($mainHeadings > 1) {
            $add('reflex', 'main_headings', __('The page has :count main headings (banners and large headings); keep one.', ['count' => $mainHeadings]));
        }

        if ($brandButton && ($ratio = self::contrast($brand, '#ffffff')) < self::MIN_CONTRAST) {
            $add('floor', 'contrast', __('White text on the brand colour :brand reads at :ratio:1; it needs :min:1. Pick a darker brand in the site\'s theme.', ['brand' => $brand, 'ratio' => round($ratio, 1), 'min' => self::MIN_CONTRAST]));
        }

        // What search engines and shared links show.
        $title = $snapshot['title'];
        $description = (string) $snapshot['description'];
        if ($description === '') {
            $add('reflex', 'no_description', __('There\'s no description for search engines and shared links.'));
        } elseif (mb_strlen($description) > 160) {
            $add('reflex', 'long_description', __('The description is :count characters; search engines cut it at about 160.', ['count' => mb_strlen($description)]));
        } elseif (mb_strlen($description) < 50) {
            $add('finish', 'short_description', __('The description is short (:count characters); say a little more about the page.', ['count' => mb_strlen($description)]));
        }
        if (mb_strlen($title) > 60) {
            $add('reflex', 'long_title', __('The title is :count characters; search engines cut it at about 60.', ['count' => mb_strlen($title)]));
        }
        foreach ($entries as $other) {
            if ($other->id !== $entry->id && mb_strtolower(self::entryTitle($other)) === mb_strtolower($title)) {
                $add('reflex', 'duplicate_title', __('":path" has the same title, ":title".', ['path' => $paths[$other->id], 'title' => $title]));
            }
        }
        if (! $snapshot['image']) {
            $add('finish', 'no_image', __('There\'s no image for shared links.'));
        }

        // The same form at the end of most pages reads as a template, not a site.
        $ending = self::endingForm($snapshot['items'], $metas);
        if ($ending) {
            $alike = 0;
            foreach ($entries as $other) {
                if ($other->id !== $entry->id && isset($live[$other->id]) && self::endingForm($live[$other->id]->snapshot['items'] ?? [], $metas, true) === $ending) {
                    $alike++;
                }
            }
            if ($alike >= 2) {
                $add('reflex', 'same_ending', __('This page ends with the same form as :count other pages. Ask for it where it fits.', ['count' => $alike]));
            }
        }

        return $issues;
    }

    /**
     * What's wrong with the site as a whole: no home page, no domain, no title.
     *
     * @return list<array{level: string, code: string, message: string}>
     */
    public static function site(Meta $site): array
    {
        $issues = [];
        $paths = Sites::paths(Sites::entries($site));
        $live = Sites::live($site);
        $home = array_search('/', $paths, true);
        if ($home === false) {
            $issues[] = ['level' => 'floor', 'code' => 'no_home', 'message' => __('The site has no home page: give one page an empty address.')];
        } elseif (! isset($live[$home])) {
            $issues[] = ['level' => 'floor', 'code' => 'home_unpublished', 'message' => __('The home page isn\'t published; visitors to the site get "not found".')];
        }
        if (! SiteDomain::where('site_meta_id', $site->id)->exists()) {
            $issues[] = ['level' => 'reflex', 'code' => 'no_domain', 'message' => __('The site has no domain yet; it opens only at /site/:code.', ['code' => $site->code])];
        }
        if (! Sites::settings($site)['title']) {
            $issues[] = ['level' => 'finish', 'code' => 'no_site_title', 'message' => __('The site has no title of its own; the meta\'s name is used.')];
        }

        return $issues;
    }

    /**
     * Every meta a page shows or opens.
     *
     * @return list<int>
     */
    public static function metaIds(array $snapshot): array
    {
        $ids = [];
        $all = self::flatten($snapshot['items'] ?? []);
        foreach ($snapshot['templates'] ?? [] as $parts) {
            array_push($all, ...self::flatten($parts));
        }
        foreach ($all as $item) {
            array_push($ids, ...self::metaRefs(self::values($item)));
        }

        return array_values(array_unique($ids));
    }

    /** WCAG contrast ratio of two #rrggbb colours. */
    public static function contrast(string $a, string $b): float
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = array_map(fn ($c) => hexdec($c) / 255, str_split(strlen($hex) === 3 ? preg_replace('/(.)/', '$1$1', $hex) : substr($hex, 0, 6), 2));
        [$r, $g, $b] = array_map(fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** A widget's prop values: the library's defaults, then its own. */
    private static function values(array $item): array
    {
        $defaults = [];
        foreach ($item['widget']['props'] ?? [] as $prop) {
            if (isset($prop['key']) && array_key_exists('default', $prop)) {
                $defaults[$prop['key']] = $prop['default'];
            }
        }

        return array_merge($defaults, (array) ($item['meta']['json_config'] ?? []));
    }

    /** @return list<int> */
    private static function metaRefs(array $v): array
    {
        $ids = [];
        foreach ([$v, ...array_filter((array) ($v['items'] ?? []), 'is_array')] as $values) {
            foreach (self::META_PROPS as $key) {
                if ((int) ($values[$key] ?? 0) > 0) {
                    $ids[] = (int) $values[$key];
                }
            }
        }

        return $ids;
    }

    /** @return list<string> */
    private static function urls(array $v): array
    {
        $urls = [];
        foreach ([$v, ...array_filter((array) ($v['items'] ?? []), 'is_array')] as $values) {
            if (! ((int) ($values['meta_id'] ?? 0) > 0) && self::str($values['url'] ?? null) !== '') {
                $urls[] = self::str($values['url']);
            }
        }

        return $urls;
    }

    /** The form a page ends with, if its last widget is one. */
    private static function endingForm(array $items, $metas, bool $lookUp = false): ?int
    {
        $last = end($items);
        if (! $last || ! in_array($last['widget']['renderer'], ['meta', 'webform'], true)) {
            return null;
        }
        $id = (int) (self::values($last)['meta_id'] ?? 0);
        $meta = $metas->get($id) ?? ($lookUp && $id ? Meta::with('type')->find($id) : null);

        return $meta?->type?->code === 'form' ? $id : null;
    }

    /**
     * A list prop's rows that have a picture.
     *
     * @return list<array<string, mixed>>
     */
    private static function pictures(mixed $rows): array
    {
        return array_values(array_filter(is_array($rows) ? $rows : [], fn ($r) => is_array($r) && self::str($r['src'] ?? null) !== ''));
    }

    /** @return list<array>|list<array{0: array, 1: int}> */
    private static function flatten(array $items, int $depth = 0, bool $withDepth = false): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = $withDepth ? [$item, $depth] : $item;
            array_push($out, ...self::flatten($item['children'] ?? [], $depth + 1, $withDepth));
        }

        return $out;
    }

    private static function entryTitle(MetaParam $entry): string
    {
        return trim((string) ($entry->json_config['title'] ?? '')) ?: (string) $entry->label_name;
    }

    private static function shouting(string $text): bool
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $text);

        return mb_strlen($letters) >= 4 && $letters === mb_strtoupper($letters) && $letters !== mb_strtolower($letters);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
