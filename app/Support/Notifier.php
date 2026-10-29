<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\MetaDataController;
use App\Jobs\SendNotificationEmail;
use App\Models\Meta;
use App\Models\MetaNotificationMap;
use App\Models\MetaParam;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WfmStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Sends the notifications that notification metas describe.
 *
 * A notification meta's json_config says when it goes out and what it says:
 *  - event: created, updated or deleted (a record of source_meta_id's table, written through
 *    any form), status_changed (a record moved in workflow_meta_id, into status_id if set),
 *    or manual (only sent from the builder). The watched meta and status are kept in
 *    meta_notification_map, so a change reads only the rules watching its table.
 *  - changed_field: for updates, only when this field changed. conditions: [{field, op,
 *    value}], all of which the record must meet; op is =, !=, >, <, >=, <=, contains, empty
 *    or not_empty.
 *  - kind (info, success, alert, danger), title and body. {field} in them is the record's
 *    value (a lookup's name), {old.field} the value before an update, and {id}, {actor},
 *    {meta}, {status} and {today} what they say.
 *  - link_url (with the same placeholders), else link_meta_id, opened on the record when
 *    link_record is set.
 *  - exclude_actor (default true): whoever made the change isn't told about it.
 *  - email: also emailed to each recipient, by the queue (SendNotificationEmail).
 *
 * Its params are the recipients, each by data_type: role (field_path = the role's code),
 * user (an email), field (a column of the record holding a user's id, email or name),
 * actor, or everyone. People who may not open the source meta are left out.
 *
 * A notification never stops the change that caused it: a failure is reported and skipped.
 */
class Notifier
{
    public const EVENTS = ['created', 'updated', 'deleted', 'status_changed', 'manual'];

    public const RECIPIENTS = ['role', 'user', 'field', 'actor', 'everyone'];

    public const OPERATORS = ['=', '!=', '>', '<', '>=', '<=', 'contains', 'empty', 'not_empty'];

    /** A record was created, updated or deleted in $table. $before is the row before an update or delete. */
    public static function recordChanged(string $table, string $event, int $id, ?User $actor, ?object $before = null): void
    {
        self::safely(function () use ($table, $event, $id, $actor, $before) {
            // Only the rules watching a meta of this table are read (meta_notification_map).
            $watching = MetaNotificationMap::whereIn('meta_id', Meta::where('table_name', $table)->select('id'))->select('notification_id');
            $rules = self::rules($watching)->filter(fn (Meta $n) => ($n->json_config['event'] ?? null) === $event && self::sourceTable($n) === $table);
            if ($rules->isEmpty()) {
                return;
            }
            $record = $event === 'deleted' ? $before : DB::table($table)->where('id', $id)->first();
            foreach ($rules as $rule) {
                self::fire($rule, $record, $id, $actor, $before);
            }
        });
    }

    /** A record of a workflow's table moved into a status. */
    public static function statusChanged(Meta $workflow, string $table, int $id, int $statusId, ?User $actor): void
    {
        self::safely(function () use ($workflow, $table, $id, $statusId, $actor) {
            $watching = MetaNotificationMap::where('meta_id', $workflow->id)
                ->where(fn ($q) => $q->whereNull('status_id')->orWhere('status_id', $statusId))->select('notification_id');
            $rules = self::rules($watching)->filter(fn (Meta $n) => ($n->json_config['event'] ?? null) === 'status_changed');
            if ($rules->isEmpty()) {
                return;
            }
            $record = DB::table($table)->where('id', $id)->first();
            foreach ($rules as $rule) {
                self::fire($rule, $record, $id, $actor, null, $statusId);
            }
        });
    }

    /**
     * What a notification would say about a record, and to whom, without sending it.
     *
     * @return array{type: string, title: string, body: ?string, link: ?string, recipients: Collection<int, User>}
     */
    public static function compose(Meta $rule, ?object $record, ?int $id, ?User $actor, ?object $before = null, ?int $statusId = null): array
    {
        $config = $rule->json_config ?? [];
        $fill = self::filler($rule, $record, $id, $actor, $before, $statusId);
        $url = trim((string) ($config['link_url'] ?? ''));
        $linkMeta = (int) ($config['link_meta_id'] ?? 0);
        $link = match (true) {
            $url !== '' => $fill($url),
            $linkMeta > 0 => "/run/{$linkMeta}".(! empty($config['link_record']) && $id ? "?record={$id}" : ''),
            default => null,
        };
        $title = trim($fill((string) ($config['title'] ?? ''))) ?: ($rule->label_name ?: $rule->name);
        $body = trim($fill((string) ($config['body'] ?? '')));

        return [
            'type' => in_array($config['kind'] ?? null, UserNotification::TYPES, true) ? $config['kind'] : 'info',
            'title' => mb_substr($title, 0, 255),
            'body' => $body === '' ? null : $body,
            'link' => $link === null ? null : mb_substr($link, 0, 255),
            'recipients' => self::recipients($rule, $record, $actor),
        ];
    }

    /**
     * Sends the notification to its recipients (or only to $only), and returns how many got it.
     *
     * @param  Collection<int, User>|null  $only
     */
    public static function send(Meta $rule, ?object $record, ?int $id, ?User $actor, ?object $before = null, ?int $statusId = null, ?Collection $only = null): int
    {
        $message = self::compose($rule, $record, $id, $actor, $before, $statusId);
        $email = self::emails($rule);
        $now = now();
        $rows = ($only ?? $message['recipients'])->map(fn (User $u) => [
            'user_id' => $u->id,
            'notification_meta_id' => $rule->id,
            'type' => $message['type'],
            'title' => $message['title'],
            'body' => $message['body'],
            'link' => $message['link'],
            'email_status' => $email ? 'queued' : null,
            'created_at' => $now,
        ])->values();
        foreach ($rows->chunk(500) as $chunk) {
            UserNotification::insert($chunk->all());
        }

        if ($email && $rows->isNotEmpty()) {
            // The rows just written: queued for this rule at this moment, for these people.
            $ids = UserNotification::where('notification_meta_id', $rule->id)->where('email_status', 'queued')
                ->where('created_at', $now)->whereIn('user_id', $rows->pluck('user_id'))->pluck('id');
            foreach ($ids->chunk(50) as $chunk) {
                SendNotificationEmail::dispatch($chunk->values()->all())->afterCommit();
            }
        }

        return $rows->count();
    }

    /** Whether the rule also goes out by email (json_config.email). */
    public static function emails(Meta $rule): bool
    {
        return ($rule->json_config['email'] ?? false) === true;
    }

    /** Whether the record meets the rule's changed_field and conditions. */
    public static function matches(Meta $rule, ?object $record, ?object $before = null): bool
    {
        $config = $rule->json_config ?? [];
        $changed = $config['changed_field'] ?? null;
        if (is_string($changed) && $changed !== '' && ($config['event'] ?? null) === 'updated') {
            if (! $record || ! $before || (string) ($record->{$changed} ?? '') === (string) ($before->{$changed} ?? '')) {
                return false;
            }
        }

        return self::conditionsMet((array) ($config['conditions'] ?? []), $record);
    }

    /**
     * Whether the record meets every condition ({field, op, value}; op is =, !=, >, <, >=,
     * <=, contains, empty or not_empty). Process flows' transitions use the same ones.
     */
    public static function conditionsMet(array $conditions, ?object $record): bool
    {
        foreach ($conditions as $c) {
            if (! is_array($c) || ! is_string($c['field'] ?? null) || $c['field'] === '') {
                continue;
            }
            $value = $record?->{$c['field']} ?? null;
            $target = (string) ($c['value'] ?? '');
            $text = mb_strtolower(trim((string) $value));
            $numeric = is_numeric($value) && is_numeric($target);
            $ok = match ($c['op'] ?? '=') {
                '!=' => $text !== mb_strtolower(trim($target)),
                '>' => $numeric ? $value > $target : $text > mb_strtolower($target),
                '<' => $numeric ? $value < $target : $text < mb_strtolower($target),
                '>=' => $numeric ? $value >= $target : $text >= mb_strtolower($target),
                '<=' => $numeric ? $value <= $target : $text <= mb_strtolower($target),
                'contains' => $target !== '' && str_contains($text, mb_strtolower($target)),
                'empty' => $text === '',
                'not_empty' => $text !== '',
                default => $numeric ? $value == $target : $text === mb_strtolower(trim($target)),
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /** The table a rule watches: its source meta's table, or for status changes the workflow's. */
    public static function sourceTable(Meta $rule): ?string
    {
        $config = $rule->json_config ?? [];
        $metaId = ($config['event'] ?? null) === 'status_changed' ? ($config['workflow_meta_id'] ?? null) : ($config['source_meta_id'] ?? null);
        $table = $metaId ? Meta::whereKey((int) $metaId)->value('table_name') : null;
        $table = $table ? trim($table) : null;

        return $table && ! in_array(strtolower($table), MetaDataController::BLOCKED_TABLES, true) && Schema::hasTable($table) ? $table : null;
    }

    private static function fire(Meta $rule, ?object $record, int $id, ?User $actor, ?object $before, ?int $statusId = null): void
    {
        if (self::matches($rule, $record, $before)) {
            self::send($rule, $record, $id, $actor, $before, $statusId);
        }
    }

    /**
     * The active notification metas among these ids (a subquery), read afresh for each change
     * so edits apply at once.
     *
     * @return Collection<int, Meta>
     */
    private static function rules(mixed $ids): Collection
    {
        return Meta::whereIn('id', $ids)->whereHas('type', fn ($q) => $q->where('code', 'notification'))->where('is_active', true)
            ->with(['links', 'notificationLink'])->get();
    }

    /**
     * The users a rule goes to.
     *
     * @return Collection<int, User>
     */
    private static function recipients(Meta $rule, ?object $record, ?User $actor): Collection
    {
        $ids = collect();
        foreach (MetaParam::where('main_meta_id', $rule->id)->where('is_show', true)->orderBy('order_number')->get() as $p) {
            $value = trim((string) $p->field_path);
            $ids = $ids->merge(match ($p->data_type) {
                'role' => User::whereHas('roles', fn ($q) => $q->where('code', $value))->pluck('id'),
                'user' => User::where('email', $value)->pluck('id'),
                'field' => self::usersIn($record?->{$value} ?? null),
                'actor' => $actor ? [$actor->id] : [],
                'everyone' => User::pluck('id'),
                default => [],
            });
        }

        $config = $rule->json_config ?? [];
        if (($config['exclude_actor'] ?? true) && $actor) {
            $ids = $ids->reject(fn ($id) => (int) $id === $actor->id);
        }
        $users = User::whereIn('id', $ids->unique()->values())->orderBy('name')->get();

        // Only people who may open what it is about.
        $source = (int) ($config['source_meta_id'] ?? 0);

        return $source ? $users->filter(fn (User $u) => Access::can($u, $source, 'view'))->values() : $users;
    }

    /** A record's value naming people: user ids, emails or names, comma separated. */
    private static function usersIn(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $parts = array_filter(array_map('trim', explode(',', (string) $value)));

        return User::where(fn ($q) => $q->whereIn('email', $parts)->orWhereIn('name', $parts)
            ->orWhereIn('id', array_filter($parts, 'ctype_digit')))->pluck('id')->all();
    }

    /** Fills {field}, {old.field}, {id}, {actor}, {meta}, {status} and {today} in a template. */
    private static function filler(Meta $rule, ?object $record, ?int $id, ?User $actor, ?object $before, ?int $statusId): callable
    {
        $config = $rule->json_config ?? [];
        $source = (int) ($config['source_meta_id'] ?? 0);
        $sourceMeta = $source ? Meta::find($source) : null;
        $params = $sourceMeta ? MetaParam::where('main_meta_id', $sourceMeta->id)->get()->keyBy('field_path') : collect();
        $statusId ??= isset($record->status_id) ? (int) $record->status_id : null;

        $name = function (string $field, mixed $value) use ($params): string {
            if ($value === null) {
                return '';
            }
            $param = $params->get($field);
            if ($param && $param->is_select && $param->ref_meta_id && ($table = ParamRules::lookupTable($param))) {
                $label = $param->displayField();
                if (Schema::hasColumn($table, $label)) {
                    return (string) (DB::table($table)->where($param->valueField(), $value)->value($label) ?? $value);
                }
            }
            if (is_bool($value) || ($param?->data_type === 'boolean')) {
                return $value ? 'yes' : 'no';
            }

            return (string) $value;
        };

        return fn (string $template) => preg_replace_callback('/\{\s*(old\.)?(\w+)\s*\}/', function ($m) use ($record, $before, $id, $actor, $sourceMeta, $rule, $statusId, $name) {
            [$all, $old, $key] = $m;
            if ($old) {
                return $name($key, $before?->{$key} ?? null);
            }

            return match ($key) {
                'id' => (string) ($id ?? ''),
                'actor' => $actor?->name ?? 'Someone',
                'meta' => $sourceMeta ? ($sourceMeta->label_name ?: $sourceMeta->name) : ($rule->label_name ?: $rule->name),
                'status' => $statusId ? (string) (WfmStatus::whereKey($statusId)->value('label_name') ?: WfmStatus::whereKey($statusId)->value('name')) : '',
                'today' => now()->toDateString(),
                default => $record && property_exists($record, $key) ? $name($key, $record->{$key}) : $all,
            };
        }, $template);
    }

    private static function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
