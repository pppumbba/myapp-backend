<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\MetaVersion;
use App\Models\Patch;
use App\Models\PatchInstall;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Patches: metas shipped from one installation to another, e.g. from test to live.
 *
 * Here, a patch is a named set of metas. Releasing it writes its file (a MetaTransfer
 * export with a "patch" header) and keeps it as it was then. There, installing the file
 * imports its metas and logs what it did to each: added, replaced (with the version saved
 * just before) or skipped. An install can be rolled back: the metas it added are deleted
 * and the ones it replaced are restored to those versions. Newer installs that touched the
 * same metas must be rolled back first.
 *
 * The file also carries the database its metas need (PatchSchema), which installing adds
 * first, so a patch needs no deploy. What can't travel in a file is the app's own code.
 */
class Patches
{
    /** Freezes the patch: its file is written now and stays as it is. */
    public static function release(Patch $patch, ?User $user): Patch
    {
        abort_if($patch->isReleased(), 422, __('This patch is already released.'));
        $ids = $patch->metas()->pluck('metas.id')->all();
        abort_if($ids === [], 422, __('Add metas to the patch first.'));

        $package = self::export($patch);
        $package['schema'] = PatchSchema::capture($package, $patch->data_tables ?? []);
        $package['patch'] = [
            'code' => $patch->code,
            'name' => $patch->name,
            'description' => $patch->description,
            'released_at' => now()->toIso8601String(),
            'released_by' => $user?->name,
        ];
        $patch->update([
            'status' => 'released',
            'package' => json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'hash' => self::hash($package),
            'released_at' => now(),
            'released_by' => $user?->id,
        ]);

        return $patch;
    }

    /**
     * The tables a selection of metas needs, for picking which ones' rows to ship.
     *
     * @param  list<int>  $ids
     * @return list<array{table: string, rows: int, triggers: int, can_ship_rows: bool}>
     */
    public static function tables(array $ids, bool $withUsed): array
    {
        return PatchSchema::overview(MetaTransfer::export($ids, $withUsed));
    }

    private static function export(Patch $patch): array
    {
        return MetaTransfer::export($patch->metas()->pluck('metas.id')->all(), $patch->with_used);
    }

    /** What a patch file defines: equal hashes, same metas and database. */
    public static function hash(array $package): string
    {
        $defines = [$package['metas'] ?? [], $package['refs'] ?? []];
        if (! empty($package['schema'])) {
            $defines[] = $package['schema'];
        }

        return hash('sha256', json_encode($defines));
    }

    /**
     * Metas changed since a time, by default since the newest patch was released: what a
     * new patch would likely ship.
     *
     * @return Collection<int, Meta>
     */
    public static function changedSince(?CarbonInterface $since): Collection
    {
        $since ??= Patch::where('status', 'released')->max('released_at');

        return Meta::with('type:id,code,name')
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->whereNotIn('type_id', DB::table('meta_types')->where('code', 'widget')->select('id'))
            ->orderByDesc('updated_at')
            ->get(['id', 'code', 'name', 'type_id', 'updated_at']);
    }

    /**
     * Installs a patch file, or with $dryRun says what it would do. A plain metas export
     * installs too, named after the file. The database comes first, as the metas need it. Its
     * changes can't be part of a transaction, so a failure after them leaves them in place;
     * they only add, and installing again picks up from there.
     *
     * @param  'replace'|'skip'|'copy'  $existing
     * @return array{metas: list<array<string, mixed>>, warnings: list<string>, schema?: array<string, mixed>, install: ?PatchInstall, installed_before: list<array{id: int, installed_at: ?string}>}
     */
    public static function install(array $package, string $existing, ?User $user, bool $dryRun = false, ?string $fileName = null): array
    {
        $patch = is_array($package['patch'] ?? null) ? $package['patch'] : [];
        $name = $patch['name'] ?? ($fileName ? preg_replace('/(\.metas)?\.json$/', '', $fileName) : 'Metas file');
        $hash = self::hash($package);
        $wanted = is_array($package['schema'] ?? null) ? $package['schema'] : [];
        $schema = null;
        if ($wanted !== []) {
            $schema = $dryRun ? (PatchSchema::supported() ? PatchSchema::plan($wanted) : null) : PatchSchema::apply($wanted);
        }
        $result = MetaTransfer::import($package, $existing, $user, $dryRun, "Before installing the patch {$name}");
        if ($schema !== null) {
            $result['schema'] = $schema;
            // What the import can't see yet: on a dry run the database changes haven't happened.
            $result['warnings'] = array_values(array_filter($result['warnings'], fn ($w) => ! array_filter($schema['tables'], fn ($t) => str_contains($w, "the table {$t},"))
                && ! ($dryRun && $schema['roles'] && str_contains($w, 'access for a role is left out'))));
            array_push($result['warnings'], ...($schema['warnings'] ?? []));
        }
        $before = PatchInstall::where('hash', $hash)->whereNull('rolled_back_at')->orderByDesc('id')->get(['id', 'installed_at'])
            ->map(fn ($i) => ['id' => $i->id, 'installed_at' => $i->installed_at?->toIso8601String()])->all();

        $install = $dryRun ? null : PatchInstall::create([
            'patch_code' => $patch['code'] ?? null,
            'patch_name' => $name,
            'description' => $patch['description'] ?? null,
            'hash' => $hash,
            'source' => $package['source'] ?? null,
            'released_at' => $patch['released_at'] ?? null,
            'existing' => $existing,
            'result' => $result,
            'package' => json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'installed_by' => $user?->id,
            'installed_at' => now(),
        ]);

        return [...$result, 'install' => $install, 'installed_before' => $before];
    }

    /**
     * Undoes an install: deletes the metas it added and restores the ones it replaced to the
     * versions saved just before. Each meta's state now is kept as a version first.
     *
     * @return list<string> what could not be put back as it was
     */
    public static function rollback(PatchInstall $install, ?User $user): array
    {
        abort_if($install->rolled_back_at !== null, 422, __('This install is already rolled back.'));
        $ids = self::touched($install);
        $newer = PatchInstall::where('id', '>', $install->id)->whereNull('rolled_back_at')->orderBy('id')->get()
            ->filter(fn (PatchInstall $other) => array_intersect(self::touched($other), $ids) !== []);
        abort_if($newer->isNotEmpty(), 422, __('Roll back :names first: it changed the same metas later.', ['names' => $newer->pluck('patch_name')->implode(', ')]));

        $note = "Before rolling back the patch {$install->patch_name}";
        $entries = $install->result['metas'] ?? [];
        $warnings = [];

        DB::transaction(function () use ($install, $user, $entries, $note, &$warnings) {
            // Added metas go first, newest first (a page's widgets before the page).
            foreach (array_reverse($entries) as $entry) {
                if (in_array($entry['action'], ['create', 'copy'], true) && $entry['id'] && ($meta = Meta::find($entry['id']))) {
                    MetaVersions::saveIfChanged($meta->id, $user, $note);
                    $meta->delete();
                }
            }
            foreach ($entries as $entry) {
                if ($entry['action'] !== 'replace') {
                    continue;
                }
                $version = isset($entry['version_id']) ? MetaVersion::find($entry['version_id']) : null;
                if (! $version) {
                    $warnings[] = "{$entry['code']} can't be put back: the version from before the install is gone.";

                    continue;
                }
                array_push($warnings, ...MetaVersions::restore($version, $user)['warnings']);
            }
            $install->update(['rolled_back_at' => now(), 'rolled_back_by' => $user?->id]);
        });

        // After the metas: nothing may be left reading what goes here.
        if (isset($install->result['schema'])) {
            array_push($warnings, ...PatchSchema::undo($install->result['schema']));
        }

        return array_values(array_unique($warnings));
    }

    /** @return list<int> the metas an install added or replaced */
    private static function touched(PatchInstall $install): array
    {
        return collect($install->result['metas'] ?? [])
            ->filter(fn ($entry) => in_array($entry['action'], ['create', 'copy', 'replace'], true) && $entry['id'])
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
