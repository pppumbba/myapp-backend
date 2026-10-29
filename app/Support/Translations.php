<?php

namespace App\Support;

use App\Models\Language;
use App\Models\Meta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Translations of what admins build: meta names, field labels, button labels and status
 * names, per language. The originals are in the source language and are what the builder
 * edits; screens ask for their definitions translated (?translated=1) and get each text in
 * the request's language, or the original where there is none.
 *
 * Each translation belongs to the meta owning its row (translations.meta_id), so it is
 * versioned, copied and deleted with it, and is cached per meta and language.
 */
class Translations
{
    /**
     * What can be translated: per table, its texts and the column naming the meta that owns a
     * row (null: the row is the meta).
     *
     * @var array<string, array{owner: ?string, fields: list<string>}>
     */
    public const TEXTS = [
        'metas' => ['owner' => null, 'fields' => ['name', 'label_name', 'description']],
        'meta_params' => ['owner' => 'main_meta_id', 'fields' => ['label_name']],
        'meta_actions' => ['owner' => 'grid_meta_id', 'fields' => ['label']],
        'wfm_status' => ['owner' => 'workflow_meta_id', 'fields' => ['name', 'label_name']],
    ];

    /** Params that are a query's design rather than something people see. */
    private const DESIGN_TYPES = ['table', 'union', 'where', 'having', 'group', 'condition', 'subquery'];

    /**
     * The languages, in order, with which is the source and which the default.
     *
     * @return list<array{code: string, name: string, is_source: bool, is_default: bool, is_active: bool}>
     */
    public static function languages(): array
    {
        // Before the migration there is nothing to remember, and nothing is kept.
        if (! Cache::has('languages') && ! Schema::hasTable('languages')) {
            return [];
        }

        return Cache::rememberForever('languages', fn () => Language::orderBy('order_number')->orderBy('id')
            ->get(['id', 'code', 'name', 'is_source', 'is_default', 'is_active', 'order_number'])->toArray());
    }

    public static function forgetLanguages(): void
    {
        Cache::forget('languages');
    }

    /** The language labels are written in. */
    public static function source(): string
    {
        return collect(self::languages())->firstWhere('is_source', true)['code'] ?? config('app.locale');
    }

    /** The language for users who haven't picked one and whose browser asks for none we speak. */
    public static function fallback(): string
    {
        return collect(self::languages())->firstWhere('is_default', true)['code'] ?? self::source();
    }

    /** @return list<string> */
    public static function activeCodes(): array
    {
        return collect(self::languages())->where('is_active', true)->pluck('code')->values()->all();
    }

    /**
     * Each translation of a meta's rows in one language: [table][row id][field] => text.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    public static function of(int $metaId, string $locale): array
    {
        return Cache::rememberForever("translations.{$locale}.{$metaId}", function () use ($metaId, $locale) {
            $map = [];
            foreach (DB::table('translations')->where('meta_id', $metaId)->where('locale', $locale)->get(['table_name', 'row_id', 'field', 'value']) as $t) {
                $map[$t->table_name][$t->row_id][$t->field] = $t->value;
            }

            return $map;
        });
    }

    /** After a meta's translations change, or the meta is restored, copied over or replaced. */
    public static function forget(int $metaId): void
    {
        foreach (array_unique([...array_column(self::languages(), 'code'), config('app.locale')]) as $code) {
            Cache::forget("translations.{$code}.{$metaId}");
        }
    }

    /**
     * Puts the request's language on models from one table, where a translation exists. In the
     * source language nothing changes and nothing is read.
     *
     * @param  iterable<Model>  $models
     */
    public static function apply(iterable $models, string $table, ?string $locale = null): void
    {
        $locale ??= App::getLocale();
        if ($locale === self::source() || ! isset(self::TEXTS[$table])) {
            return;
        }
        $owner = self::TEXTS[$table]['owner'];
        foreach ($models as $model) {
            $metaId = (int) ($owner ? $model->getAttribute($owner) : $model->getKey());
            $texts = $metaId ? (self::of($metaId, $locale)[$table][$model->getKey()] ?? []) : [];
            foreach ($texts as $field => $value) {
                $model->setAttribute($field, $value);
            }
        }
    }

    /**
     * Every text of a meta that can be translated, with its original and its translations,
     * for the builder and the Excel file.
     *
     * @return list<array{key: string, table: string, row_id: int, field: string, where: string, original: string, values: array<string, string>}>
     */
    public static function texts(Meta $meta): array
    {
        $meta->loadMissing('type');
        $saved = [];
        foreach (DB::table('translations')->where('meta_id', $meta->id)->get() as $t) {
            $saved["{$t->table_name}:{$t->row_id}:{$t->field}"][$t->locale] = $t->value;
        }
        $texts = [];
        $add = function (string $table, int $id, string $field, string $where, ?string $original, bool $always = false) use (&$texts, $saved) {
            if (! $always && ($original === null || trim($original) === '')) {
                return;
            }
            $key = "{$table}:{$id}:{$field}";
            $texts[] = ['key' => $key, 'table' => $table, 'row_id' => $id, 'field' => $field, 'where' => $where, 'original' => (string) $original, 'values' => $saved[$key] ?? []];
        };

        $add('metas', $meta->id, 'name', 'Name', $meta->name);
        $add('metas', $meta->id, 'label_name', 'Title', $meta->label_name);
        $add('metas', $meta->id, 'description', 'Description', $meta->description);
        if ($meta->type?->code !== 'query') {
            $params = DB::table('meta_params')->where('main_meta_id', $meta->id)->orderBy('order_number')->orderBy('id')->get();
            foreach ($params->reject(fn ($p) => in_array($p->data_type, self::DESIGN_TYPES, true)) as $p) {
                $add('meta_params', $p->id, 'label_name', 'Field '.($p->field_path ?: $p->param_name), $p->label_name ?: ($p->param_name ?: $p->field_path), true);
            }
        }
        foreach (DB::table('meta_actions')->where('grid_meta_id', $meta->id)->orderBy('order_number')->orderBy('id')->get() as $a) {
            $add('meta_actions', $a->id, 'label', 'Button '.$a->action, $a->label);
        }
        foreach (DB::table('wfm_status')->where('workflow_meta_id', $meta->id)->orderBy('id')->get() as $s) {
            $add('wfm_status', $s->id, 'name', 'Status '.$s->code, $s->name);
            $add('wfm_status', $s->id, 'label_name', 'Status '.$s->code.' label', $s->label_name);
        }

        return $texts;
    }

    /**
     * Saves translations of a meta's texts: [{key, locale, value}], an empty value removing one.
     * Texts that aren't the meta's, the source language and unknown languages are refused.
     * Translations of rows that no longer exist go too.
     *
     * @param  list<array{key: string, locale: string, value: ?string}>  $entries
     * @return array{saved: int, removed: int}
     */
    public static function save(Meta $meta, array $entries): array
    {
        $texts = collect(self::texts($meta))->keyBy('key');
        $codes = array_diff(self::activeCodes(), [self::source()]);
        $saved = $removed = 0;

        DB::transaction(function () use ($meta, $entries, $texts, $codes, &$saved, &$removed) {
            foreach ($entries as $entry) {
                $text = $texts[$entry['key']] ?? null;
                abort_unless($text, 422, __('":key" isn\'t a text of :name.', ['key' => $entry['key'], 'name' => $meta->name]));
                abort_unless(in_array($entry['locale'], $codes, true), 422, __('":locale" isn\'t a language to translate into.', ['locale' => $entry['locale']]));
                $where = ['table_name' => $text['table'], 'row_id' => $text['row_id'], 'field' => $text['field'], 'locale' => $entry['locale']];
                $value = trim((string) ($entry['value'] ?? ''));
                if ($value === '') {
                    $removed += DB::table('translations')->where($where)->delete();
                } else {
                    DB::table('translations')->updateOrInsert($where, ['meta_id' => $meta->id, 'value' => $value, 'updated_at' => now()]
                        + (DB::table('translations')->where($where)->exists() ? [] : ['created_at' => now()]));
                    $saved++;
                }
            }
            $keys = $texts->keys()->all();
            $removed += DB::table('translations')->where('meta_id', $meta->id)->get(['id', 'table_name', 'row_id', 'field'])
                ->reject(fn ($t) => in_array("{$t->table_name}:{$t->row_id}:{$t->field}", $keys, true))
                ->pipe(fn ($gone) => $gone->isEmpty() ? 0 : DB::table('translations')->whereIn('id', $gone->pluck('id'))->delete());
        });
        self::forget($meta->id);

        return ['saved' => $saved, 'removed' => $removed];
    }
}
