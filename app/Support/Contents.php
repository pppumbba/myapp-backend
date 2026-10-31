<?php

namespace App\Support;

use App\Models\Content;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * File attachments on records. A form's singlefile or multifile param takes uploaded files:
 * each upload is a contents row (the file on the local disk), and saving the form maps it to
 * the record in content_map (workflow_meta_id = the form, param_id = the field).
 *
 * A record's files are found by its table and the field's field_path, so every form and grid
 * on the same table shows the same files. In a form's values a multifile field is a list of
 * files and a singlefile field one file or null; each file is {id, file_name, file_extension,
 * file_size, description, is_public, type_id}. Saving sends the same, or just ids.
 *
 * The param's json_config: accept (extensions, e.g. ["pdf", "jpg"]), max_size (KB),
 * max_files (multifile), public (files anyone may download) and type_id (stored on each file).
 *
 * A document param (its ref_meta_id a report meta) is filled in on the form's page and keeps
 * the PDF its last save printed, made by the server (see keep), never uploaded: it reads like
 * a singlefile field.
 */
class Contents
{
    public const TYPES = ['singlefile', 'multifile'];

    public const DOCUMENT = 'document';

    public const DIRECTORY = 'contents';

    /** Never taken, whatever a field accepts: they could run on a server or in a browser. */
    private const BLOCKED = ['php', 'phtml', 'phar', 'exe', 'dll', 'bat', 'cmd', 'com', 'sh', 'ps1', 'js', 'mjs', 'html', 'htm', 'xhtml', 'svg', 'jar', 'msi', 'vbs', 'hta', 'scr'];

    private const MAX_SIZE = 20480;

    private const MAX_FILES = 20;

    /**
     * The meta's fields that take uploads; with $documents, its document fields too (they show a file).
     *
     * @return Collection<int, MetaParam>
     */
    public static function params(Meta|int $meta, bool $documents = false): Collection
    {
        $types = $documents ? [...self::TYPES, self::DOCUMENT] : self::TYPES;

        return MetaParam::where('main_meta_id', $meta instanceof Meta ? $meta->id : $meta)->whereIn('data_type', $types)->orderBy('order_number')->get();
    }

    /**
     * Keeps a PDF the server made as the record's file in a document field, in place of the one
     * there before (which goes when nothing else holds it).
     */
    public static function keep(Meta $meta, MetaParam $param, string $table, int $recordId, string $bytes, string $name, ?User $user): Content
    {
        $path = self::DIRECTORY.'/'.now()->format('Y/m').'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, $bytes);

        return DB::transaction(function () use ($meta, $param, $table, $recordId, $bytes, $name, $user, $path) {
            $content = Content::create([
                'physical_path' => $path,
                'file_name' => mb_substr($name, 0, 255),
                'file_extension' => 'pdf',
                'file_size' => strlen($bytes),
                'type_id' => is_numeric($param->json_config['type_id'] ?? null) ? (int) $param->json_config['type_id'] : null,
                'is_public' => (bool) ($param->json_config['public'] ?? false),
                'created_by' => $user?->id,
            ]);
            $old = self::mapped($table, $param->field_path, [$recordId]);
            DB::table('content_map')->whereIn('id', $old->pluck('id'))->delete();
            DB::table('content_map')->insert(['content_id' => $content->id, 'record_id' => $recordId, 'workflow_meta_id' => $meta->id, 'param_id' => $param->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(fn () => self::prune($old->pluck('content_id')->all()));

            return $content;
        });
    }

    /** An upload for a field, kept unattached until a form saves it to a record. */
    public static function upload(MetaParam $param, UploadedFile $file, ?User $user): Content
    {
        $config = $param->json_config ?? [];
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->extension());
        $accept = array_map(fn ($e) => strtolower(ltrim((string) $e, '.')), (array) ($config['accept'] ?? []));
        $max = (int) ($config['max_size'] ?? self::MAX_SIZE);

        $fail = fn (string $message) => throw ValidationException::withMessages(['file' => $message]);
        if (in_array($extension, self::BLOCKED, true) || ($accept && ! in_array($extension, $accept, true))) {
            $fail($accept ? __('This field takes :types files.', ['types' => implode(', ', $accept)]) : __(':extension files cannot be attached.', ['extension' => $extension]));
        }
        if ($file->getSize() > $max * 1024) {
            $fail(__('The file is larger than :size.', ['size' => self::size($max * 1024)]));
        }

        $path = $file->storeAs(self::DIRECTORY.'/'.now()->format('Y/m'), Str::uuid().($extension !== '' ? ".{$extension}" : ''), 'local');

        return Content::create([
            'physical_path' => $path,
            'file_name' => mb_substr($file->getClientOriginalName() ?: 'file', 0, 255),
            'file_extension' => $extension ?: null,
            'file_size' => $file->getSize(),
            'type_id' => is_numeric($config['type_id'] ?? null) ? (int) $config['type_id'] : null,
            'is_public' => (bool) ($config['public'] ?? false),
            'created_by' => $user?->id,
        ]);
    }

    /**
     * The files a save puts in each file field it sends, checked: they must be this user's
     * own new uploads or already on this record, within the field's count, and a required
     * field must have one. Fields not sent stay as they are (on update).
     *
     * @return array<int, list<array{id: int, description: ?string}>> by param id
     */
    public static function validated(Meta $meta, string $table, array $input, ?User $user, ?int $recordId): array
    {
        $out = [];
        $errors = [];
        foreach (self::params($meta) as $param) {
            if (! array_key_exists($param->field_path, $input)) {
                if ($recordId === null && $param->is_required) {
                    $errors[$param->field_path] = __(':field needs a file.', ['field' => $param->label()]);
                }

                continue;
            }
            $value = $input[$param->field_path];
            $items = $value === null || $value === '' ? [] : ($param->data_type === 'singlefile' || ! is_array($value) || ! array_is_list($value) ? [$value] : $value);
            $files = [];
            foreach ($items as $item) {
                $id = is_array($item) ? ($item['id'] ?? null) : $item;
                if (! is_numeric($id)) {
                    $errors[$param->field_path] = __(':field has a file that is not an upload.', ['field' => $param->label()]);

                    continue 2;
                }
                $description = is_array($item) && array_key_exists('description', $item) ? (is_scalar($item['description']) ? mb_substr(trim((string) $item['description']), 0, 2000) : null) : false;
                $files[(int) $id] = ['id' => (int) $id, 'description' => $description === '' ? null : $description];
            }
            $limit = $param->data_type === 'singlefile' ? 1 : (int) ($param->json_config['max_files'] ?? self::MAX_FILES);
            if (count($files) > $limit) {
                $errors[$param->field_path] = $limit === 1 ? __(':field takes one file.', ['field' => $param->label()]) : __(':field takes at most :count files.', ['field' => $param->label(), 'count' => $limit]);

                continue;
            }
            if (! $files && $param->is_required) {
                $errors[$param->field_path] = __(':field needs a file.', ['field' => $param->label()]);

                continue;
            }
            $ids = array_keys($files);
            $allowed = $ids ? Content::whereIn('id', $ids)
                ->where(fn ($q) => $q
                    // Someone's new upload is theirs alone until a save attaches it…
                    ->where(fn ($q) => $q->where('created_by', $user?->id)->whereNotExists(fn ($q) => $q->from('content_map')->whereColumn('content_map.content_id', 'contents.id')))
                    // …and a file already on this record may stay on it.
                    ->orWhereIn('id', $recordId === null ? [] : self::mapped($table, $param->field_path, [$recordId])->pluck('content_id')))
                ->pluck('id')->all() : [];
            if (array_diff($ids, $allowed)) {
                $errors[$param->field_path] = __(':field has a file that is not yours to attach.', ['field' => $param->label()]);

                continue;
            }
            $out[$param->id] = array_values($files);
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /** Puts each field's files on the record, takes off the ones left out, and saves descriptions. */
    public static function sync(Meta $meta, string $table, int $recordId, array $files): void
    {
        $params = self::params($meta)->keyBy('id');
        foreach ($files as $paramId => $list) {
            $param = $params->get($paramId);
            if (! $param) {
                continue;
            }
            $ids = array_column($list, 'id');
            $current = self::mapped($table, $param->field_path, [$recordId]);
            $gone = $current->whereNotIn('content_id', $ids);
            DB::table('content_map')->whereIn('id', $gone->pluck('id'))->delete();
            $have = $current->pluck('content_id')->all();
            $now = now();
            $new = array_values(array_diff($ids, $have));
            DB::table('content_map')->insert(array_map(fn ($id) => ['content_id' => $id, 'record_id' => $recordId, 'workflow_meta_id' => $meta->id, 'param_id' => $param->id, 'created_at' => $now, 'updated_at' => $now], $new));
            foreach ($list as $file) {
                if ($file['description'] !== false) {
                    Content::whereKey($file['id'])->update(['description' => $file['description']]);
                }
            }
            DB::afterCommit(fn () => self::prune($gone->pluck('content_id')->all()));
        }
    }

    /** A record is gone: its files come off it, and go when nothing else holds them. */
    public static function forget(string $table, int $recordId): void
    {
        $maps = self::mapped($table, null, [$recordId]);
        DB::table('content_map')->whereIn('id', $maps->pluck('id'))->delete();
        DB::afterCommit(fn () => self::prune($maps->pluck('content_id')->all()));
    }

    /**
     * Each row's files in its file fields: a list for multifile, one file or null for
     * singlefile and document. Rows are objects or arrays; returns them the same.
     *
     * @param  Collection<int, MetaParam>  $params  the file params to fill
     */
    public static function attach(?string $table, Collection $params, iterable $rows, string $key = 'id'): array
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows);
        if (! $table || $params->isEmpty() || ! $rows) {
            return $rows;
        }
        $get = fn ($row, $field) => is_array($row) ? ($row[$field] ?? null) : ($row->{$field} ?? null);
        $ids = array_values(array_filter(array_map(fn ($r) => $get($r, $key), $rows), 'is_numeric'));
        $files = self::mapped($table, null, $ids, true)->groupBy(fn ($m) => $m->record_id.'|'.$m->field_path);

        foreach ($rows as $i => $row) {
            foreach ($params as $param) {
                $list = ($files->get($get($row, $key).'|'.$param->field_path) ?? collect())->map(fn ($m) => self::info($m))->unique('id')->values()->all();
                $value = $param->data_type === 'multifile' ? $list : ($list[0] ?? null);
                if (is_array($row)) {
                    $rows[$i][$param->field_path] = $value;
                } else {
                    $row->{$param->field_path} = $value;
                }
            }
        }

        return $rows;
    }

    /**
     * content_map rows of records in a table: those of any form on it, for one field or all.
     * With $withFiles, joined with the contents row.
     */
    private static function mapped(string $table, ?string $field, array $recordIds, bool $withFiles = false): Collection
    {
        if (! $recordIds) {
            return collect();
        }
        $query = DB::table('content_map as cm')
            ->join('meta_params as p', 'p.id', '=', 'cm.param_id')
            ->join('metas as m', 'm.id', '=', 'cm.workflow_meta_id')
            ->where('m.table_name', $table)
            ->whereIn('cm.record_id', $recordIds)
            ->when($field !== null, fn ($q) => $q->where('p.field_path', $field))
            ->orderBy('cm.id')
            ->select(['cm.id', 'cm.content_id', 'cm.record_id', 'p.field_path']);
        if ($withFiles) {
            $query->join('contents as c', 'c.id', '=', 'cm.content_id')
                ->addSelect(['c.file_name', 'c.file_extension', 'c.file_size', 'c.description', 'c.is_public', 'c.type_id']);
        }

        return $query->get();
    }

    /** A file as forms and grids get it. */
    public static function info(object $file): array
    {
        return [
            'id' => (int) ($file->content_id ?? $file->id),
            'file_name' => $file->file_name,
            'file_extension' => $file->file_extension,
            'file_size' => (int) $file->file_size,
            'description' => $file->description,
            'is_public' => (bool) $file->is_public,
            'type_id' => $file->type_id === null ? null : (int) $file->type_id,
        ];
    }

    /** Deletes these files (row and disk) where nothing holds them any more. */
    public static function prune(array $contentIds): int
    {
        if (! $contentIds) {
            return 0;
        }
        $orphans = Content::whereIn('id', $contentIds)->whereNotExists(fn ($q) => $q->from('content_map')->whereColumn('content_map.content_id', 'contents.id'))->get();
        foreach ($orphans as $content) {
            Storage::disk('local')->delete($content->physical_path);
            $content->delete();
        }

        return $orphans->count();
    }

    /** Uploads never saved to a record, older than a day: a form was left without saving. */
    public static function pruneStale(): int
    {
        return self::prune(Content::where('created_at', '<', now()->subDay())
            ->whereNotExists(fn ($q) => $q->from('content_map')->whereColumn('content_map.content_id', 'contents.id'))
            // Powers of attorney are held by their market request, not by a form's record.
            ->whereNotExists(fn ($q) => $q->from('representation_requests')->whereColumn('representation_requests.content_id', 'contents.id'))
            // Market files (tender documents, datasheets, prequalification papers) are saved with
            // the row that holds them, under market/, never through content_map.
            ->where('physical_path', 'not like', 'market/%')
            ->pluck('id')->all());
    }

    /**
     * Whether this user may download the file: anyone when it is public, its uploader while
     * it is not on a record yet, else whoever may open a form it was saved through.
     */
    public static function canRead(Content $content, ?User $user): bool
    {
        if ($content->is_public) {
            return true;
        }
        if (! $user) {
            return false;
        }
        $metas = DB::table('content_map')->where('content_id', $content->id)->distinct()->pluck('workflow_meta_id');
        if ($metas->isEmpty()) {
            return $content->created_by === $user->id;
        }

        // As its records: not through a public form, or anyone signed in could read what visitors sent.
        return $metas->contains(fn ($id) => Access::canReadRecords($user, (int) $id));
    }

    public static function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => "{$bytes} B",
        };
    }
}
