<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

/**
 * A grid or form whose records live in an external REST service instead of a table. Its
 * meta's service_* columns say how to reach it:
 * - service_url: the collection, e.g. https://api.example.com/v1/suppliers. Listing is
 *   GET {url}, one record GET {url}/{id}, adding POST {url}, changing PUT {url}/{id}
 *   (service_update_method PATCH to patch), removing DELETE {url}/{id}.
 * - service_headers: a JSON object, e.g. {"Authorization": "Bearer {env:SERVICE_SUPPLIER_TOKEN}"}.
 * - service_query: a JSON object of the list's query string, e.g.
 *   {"page": "{page}", "limit": "{per_page}", "q": "{search}", "status": "{filter.status}"}.
 *   Without one it sends page, per_page, search, sort, dir and each filter by its name.
 * - service_list_path / service_total_path / service_record_path: where the records, the
 *   total count and a single record sit in the answers ("data.items"); empty is the answer itself.
 *   Records keyed "0", "1", ... are read as a list.
 * - service_list_body: a JSON object, for a service whose list is one POST with a body (an RPC
 *   endpoint such as runJson with a command) instead of GET {url}?query. Such a service only
 *   lists: a record opens from the list, and adding, changing and removing are refused.
 *
 * A list template (query or body) without {page}, {per_page} or {offset} means the service
 * answers with every record; they are then searched, sorted and paged here, unless the
 * template sends {search} or {sort} itself.
 *
 * Any of these may use {placeholders}: {env:SERVICE_*} (only SERVICE_ names, so other secrets
 * stay put), the user's session values ({sessionemployeeid}), {user_id} and {today}; the list
 * query also {page}, {per_page}, {offset}, {search}, {sort}, {dir} and {filter.name}. Params read and
 * write fields by field_path, which may be a dot path into the record ("address.city").
 * Records are checked with the params' rules first, as a table form's are.
 */
class ExternalService
{
    public const COLUMNS = [
        'service_url', 'service_headers', 'service_query', 'service_list_path',
        'service_total_path', 'service_record_path', 'service_update_method', 'service_list_body',
    ];

    /** Seconds to wait for the service. */
    private const TIMEOUT = 15;

    /** Seconds a list-only service's last list is kept for opening its records. */
    private const LISTED_FOR = 1800;

    public static function uses(Meta $meta): bool
    {
        return trim((string) $meta->service_url) !== '';
    }

    /** A service listed with a POST body has no address per record, so it can only be read. */
    public static function listsOnly(Meta $meta): bool
    {
        return trim((string) $meta->service_list_body) !== '';
    }

    /**
     * A page of records, shaped like a table grid's page.
     *
     * @param  array<string, string>  $filters  filter[] and scope[] values
     */
    public static function list(Meta $meta, ?User $user, array $filters, string $search, ?string $sort, string $dir, int $page, int $perPage): array
    {
        $params = self::params($meta);
        $empty = ['data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0];
        $missing = $params->filter(fn (MetaParam $p) => $p->is_mandatory_criteria && ($filters[$p->field_path] ?? '') === '')
            ->map(fn (MetaParam $p) => $p->label_name ?: $p->field_path)->values();
        if ($missing->isNotEmpty()) {
            return $empty + ['missing_filters' => $missing];
        }

        $vars = ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage, 'search' => $search, 'sort' => $sort ?? '', 'dir' => $dir]
            + collect($filters)->mapWithKeys(fn ($v, $k) => ["filter.{$k}" => $v])->all();
        $template = self::listsOnly($meta) ? self::json($meta->service_list_body, 'service_list_body') : self::json($meta->service_query, 'service_query');
        $sends = fn (string $name) => str_contains((string) json_encode($template), '{'.$name.'}');
        [$rows, $body] = self::fetch($meta, $user, $vars, $filters);

        if ($template !== [] && ! $sends('page') && ! $sends('per_page') && ! $sends('offset')) {
            $records = array_map(fn ($row) => self::record($row, $params), $rows);
            if (self::listsOnly($meta)) {
                // Its records open from the list the user just saw; listing again needs its filters.
                Cache::put(self::listedKey($meta, $user), collect($records)->keyBy(self::key($params))->all(), self::LISTED_FOR);
            }

            return self::paged($records, $params, $sends('search') ? '' : $search, $sends('sort') ? null : $sort, $dir, $page, $perPage);
        }

        $count = count($rows);
        $total = $meta->service_total_path ? self::at($body, $meta->service_total_path) : null;
        // Without a total, a full page means there may be another.
        $total = is_numeric($total) ? (int) $total : ($page - 1) * $perPage + $count + ($count >= $perPage ? 1 : 0);

        return [
            'data' => array_map(fn ($row) => self::record($row, $params), $rows),
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage,
            'total' => $total,
        ];
    }

    /**
     * Calls the list and returns its records and the whole answer.
     *
     * @return array{0: list<mixed>, 1: mixed}
     */
    private static function fetch(Meta $meta, ?User $user, array $vars, array $filters): array
    {
        if (self::listsOnly($meta)) {
            $payload = self::fillAll(self::json($meta->service_list_body, 'service_list_body'), $user, $vars);
            $answer = self::send(fn (PendingRequest $http) => $http->post(self::url($meta, $user), $payload), $meta, $user);
        } else {
            $template = self::json($meta->service_query, 'service_query');
            $query = $template === []
                ? array_merge(['page' => $vars['page'], 'per_page' => $vars['per_page'], 'search' => $vars['search'], 'sort' => $vars['sort'], 'dir' => $vars['sort'] !== '' ? $vars['dir'] : null], $filters)
                : array_map(fn ($v) => self::fill((string) $v, $user, $vars), $template);
            $query = array_filter($query, fn ($v) => $v !== null && $v !== '');
            $answer = self::send(fn (PendingRequest $http) => $http->get(self::url($meta, $user), $query), $meta, $user);
        }

        $body = $answer->json();
        $rows = self::at($body, $meta->service_list_path);
        // Some services key their records "0", "1", ... instead of answering with a list.
        if (is_array($rows) && ! array_is_list($rows) && collect(array_keys($rows))->every(fn ($k) => is_int($k))) {
            $rows = array_values($rows);
        }
        if (! is_array($rows) || ! array_is_list($rows)) {
            // Some services explain with a 200: {"response": {"status": "error", "text": "..."}}.
            $message = self::message($body, $meta->service_list_path);
            abort(502, $message !== null
                ? "The service answered: {$message}"
                : 'The service did not answer with a list of records'.($meta->service_list_path ? " at \"{$meta->service_list_path}\"" : '').'.');
        }

        return [$rows, $body];
    }

    /**
     * Searches, sorts and pages records the service sent all at once.
     *
     * @param  list<array<string, mixed>>  $records
     */
    private static function paged(array $records, Collection $params, string $search, ?string $sort, string $dir, int $page, int $perPage): array
    {
        if ($search !== '') {
            $records = array_values(array_filter($records, fn (array $record) => collect($record)->contains(
                fn ($value) => is_scalar($value) && mb_stripos(strip_tags((string) $value), $search) !== false,
            )));
        }
        if ($sort !== null && $sort !== '' && $params->contains('field_path', $sort)) {
            usort($records, function (array $a, array $b) use ($sort, $dir) {
                [$x, $y] = [$a[$sort] ?? null, $b[$sort] ?? null];
                $order = is_numeric($x) && is_numeric($y) ? $x <=> $y : strnatcasecmp((string) $x, (string) $y);

                return $dir === 'desc' ? -$order : $order;
            });
        }
        $total = count($records);

        return [
            'data' => array_slice($records, ($page - 1) * $perPage, $perPage),
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage,
            'total' => $total,
        ];
    }

    public static function find(Meta $meta, ?User $user, string $id): array
    {
        if (self::listsOnly($meta)) {
            // No address per record: it is found in the list, the one last shown if it holds it.
            $listed = Cache::get(self::listedKey($meta, $user), []);
            if (array_key_exists($id, $listed)) {
                return $listed[$id];
            }
            $params = self::params($meta);
            $key = self::key($params);
            [$rows] = self::fetch($meta, $user, ['page' => 1, 'per_page' => 0, 'offset' => 0, 'search' => '', 'sort' => '', 'dir' => 'asc'], []);
            $row = collect($rows)->first(fn ($r) => is_array($r) && (string) data_get($r, $key) === $id);
            abort_if($row === null, 404, 'Record not found.');

            return self::record($row, $params);
        }
        $answer = self::send(fn (PendingRequest $http) => $http->get(self::url($meta, $user, $id)), $meta, $user);

        return self::record(self::at($answer->json(), $meta->service_record_path), self::params($meta));
    }

    /** Checks the values with the params' rules, then adds the record; returns it as the service has it. */
    public static function create(Meta $meta, ?User $user, array $values): array
    {
        self::refuseChanges($meta);
        $body = self::body($meta, $values, false);
        $answer = self::send(fn (PendingRequest $http) => $http->post(self::url($meta, $user), $body), $meta, $user);

        return self::saved($meta, $answer, $body);
    }

    public static function update(Meta $meta, ?User $user, string $id, array $values): array
    {
        self::refuseChanges($meta);
        $body = self::body($meta, $values, true);
        $method = strtolower((string) $meta->service_update_method) === 'patch' ? 'patch' : 'put';
        $answer = self::send(fn (PendingRequest $http) => $http->{$method}(self::url($meta, $user, $id), $body), $meta, $user);

        return self::saved($meta, $answer, $body, $id);
    }

    public static function delete(Meta $meta, ?User $user, string $id): void
    {
        self::refuseChanges($meta);
        self::send(fn (PendingRequest $http) => $http->delete(self::url($meta, $user, $id)), $meta, $user);
    }

    private static function listedKey(Meta $meta, ?User $user): string
    {
        return "service-listed:{$meta->id}:".($user?->id ?? 'guest');
    }

    private static function refuseChanges(Meta $meta): void
    {
        abort_if(self::listsOnly($meta), 405, __('":name" only lists the service\'s records; they can\'t be changed here.', ['name' => $meta->name]));
    }

    /** The params that are fields: not details, subgrids or tabs, which a service record does not have. */
    private static function params(Meta $meta): Collection
    {
        return MetaParam::where('main_meta_id', $meta->id)->orderBy('order_number')->get()
            ->reject(fn (MetaParam $p) => in_array($p->data_type, ['detail', 'subgrid', 'tabs', 'singlefile', 'multifile', 'document'], true))
            ->values();
    }

    private static function key(Collection $params): string
    {
        return $params->firstWhere('is_primary', true)?->field_path ?? 'id';
    }

    /** A service record as the screens read it: one value per param, by field_path. */
    private static function record(mixed $row, Collection $params): array
    {
        abort_unless(is_array($row), 502, 'The service did not answer with a record.');
        $out = [];
        foreach ($params->pluck('field_path')->push(self::key($params))->unique() as $field) {
            $out[$field] = data_get($row, $field);
        }

        return $out;
    }

    /**
     * The JSON sent to add or change a record: the values the params' rules accept, nested by
     * dot paths. Unique can't be checked here (the records aren't in a table); the service does.
     */
    private static function body(Meta $meta, array $values, bool $updating): array
    {
        $params = self::params($meta);
        $key = self::key($params);
        // Changing a record checks only the fields sent, as a table form's update does.
        $rules = collect(ParamRules::for($params, 'service', $key))
            ->map(fn (array $list) => array_values(array_filter(
                $updating ? ['sometimes', ...$list] : $list,
                fn ($r) => ! $r instanceof Unique,
            )))
            ->all();
        // Screens send dot-path fields flat ("address.city"); the rules read them nested.
        $valid = validator(Arr::undot($values), $rules)->validate();

        $body = [];
        foreach ($params as $p) {
            if ($p->field_path !== $key && Arr::has($valid, $p->field_path)) {
                data_set($body, $p->field_path, data_get($valid, $p->field_path));
            }
        }

        return $body;
    }

    /** The saved record from the answer; a service that answers with nothing gets back what was sent. */
    private static function saved(Meta $meta, Response $answer, array $body, ?string $id = null): array
    {
        $params = self::params($meta);
        $found = self::at($answer->json() ?? [], $meta->service_record_path);
        $row = is_array($found) && $found !== [] ? $found : $body + ($id !== null ? [self::key($params) => $id] : []);

        return self::record($row, $params);
    }

    /** Calls the service, turning its refusals into answers the screens understand. */
    private static function send(callable $call, Meta $meta, ?User $user): Response
    {
        $headers = array_map(fn ($v) => self::fill((string) $v, $user), self::json($meta->service_headers, 'service_headers'));
        $http = Http::acceptJson()->asJson()->timeout(self::TIMEOUT)->withHeaders($headers);

        try {
            $answer = $call($http);
        } catch (ConnectionException) {
            abort(502, __('The service of ":name" could not be reached.', ['name' => $meta->name]));
        }

        if ($answer->successful()) {
            return $answer;
        }
        $message = $answer->json('message') ?? $answer->json('error') ?? null;
        $message = is_string($message) ? $message : null;
        if ($answer->status() === 422 && is_array($answer->json('errors'))) {
            throw ValidationException::withMessages($answer->json('errors'));
        }
        abort_if($answer->status() === 404, 404, 'Record not found.');
        abort(in_array($answer->status(), [400, 409, 422], true) ? 422 : 502,
            "The service answered {$answer->status()}".($message ? ": {$message}" : '.'));
    }

    /** The collection's (or one record's) address, its placeholders filled, checked before use. */
    private static function url(Meta $meta, ?User $user, ?string $id = null): string
    {
        $url = rtrim(self::fill(trim((string) $meta->service_url), $user), '/');
        if ($id !== null) {
            $url .= '/'.rawurlencode($id);
        }

        $parts = parse_url($url);
        abort_unless(is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && ! empty($parts['host']), 422, 'The service address must be an http or https URL.');
        // Cloud metadata addresses hold the server's own credentials.
        $ip = gethostbyname($parts['host']);
        abort_if(str_starts_with($ip, '169.254.') || $ip === '0.0.0.0' || strtolower($parts['host']) === 'metadata.google.internal', 422, 'This service address is not allowed.');

        return $url;
    }

    /**
     * Fills {placeholders}: {env:SERVICE_*}, $vars (e.g. {page}, {filter.status}), then the
     * user's session values, {user_id} and {today}.
     */
    public static function fill(string $text, ?User $user, array $vars = []): string
    {
        $text = preg_replace_callback('/\{env:(SERVICE_\w+)\}/', fn ($m) => (string) env($m[1], ''), $text);
        $text = preg_replace_callback('/\{([\w.]+)\}/', fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $text);

        return (string) SessionValues::fill($text, $user);
    }

    /** fill() on every text in a JSON template, however deep. */
    private static function fillAll(array $template, ?User $user, array $vars): array
    {
        return array_map(fn ($v) => match (true) {
            is_array($v) => self::fillAll($v, $user, $vars),
            is_string($v) => self::fill($v, $user, $vars),
            default => $v,
        }, $template);
    }

    /** The service's own explanation: text, message or error beside the list, else at the top. */
    private static function message(mixed $body, ?string $path): ?string
    {
        $path = trim((string) $path);
        $parent = str_contains($path, '.') ? substr($path, 0, strrpos($path, '.')) : null;
        foreach (array_unique([$parent, null]) as $at) {
            foreach (['text', 'message', 'error'] as $field) {
                $text = data_get(self::at($body, $at), $field);
                if (is_string($text) && trim($text) !== '') {
                    return trim($text);
                }
            }
        }

        return null;
    }

    /** A JSON object column as an array; anything else stops with a message naming it. */
    private static function json(?string $text, string $column): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }
        $value = json_decode($text, true);
        abort_unless(is_array($value) && ! array_is_list($value), 422, __(':column must be a JSON object.', ['column' => $column]));

        return $value;
    }

    /** The part of an answer at a dot path; an empty path is the whole answer. */
    private static function at(mixed $body, ?string $path): mixed
    {
        return $path === null || trim($path) === '' ? $body : data_get($body, trim($path));
    }
}
