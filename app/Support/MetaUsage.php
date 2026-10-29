<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Where each meta is used: the reverse of MetaTransfer::uses, with the place named. A meta
 * is used when another one points at it — a field looks it up or holds it as a page, a
 * button opens it, a process runs it as a step, a link or notification targets it, a
 * setting in json_config names it (any *meta_id key) — or when a schedule, the widget
 * library or users' theme pick does. A page's widget metas count as the page using what
 * they show; the page a widget sits on is not something the widget uses.
 */
class MetaUsage
{
    /**
     * Every use, per used meta id. meta_id is the meta that uses it (null for a schedule,
     * the widget library or users), where says how.
     *
     * @return array<int, list<array{meta_id: ?int, where: string}>>
     */
    public static function index(): array
    {
        $types = DB::table('meta_types')->pluck('code', 'id');
        $metas = DB::table('metas')->get(['id', 'name', 'type_id', 'ref_meta_id', 'json_config'])->keyBy('id');
        $typeOf = fn (?int $id) => $id !== null && isset($metas[$id]) ? ($types[$metas[$id]->type_id] ?? null) : null;
        $nameOf = fn (?int $id) => $id !== null && isset($metas[$id]) ? $metas[$id]->name : '?';
        $uses = [];
        $add = function (mixed $used, ?int $by, string $where) use (&$uses, $metas) {
            if (! is_numeric($used) || ! isset($metas[(int) $used]) || (int) $used === $by) {
                return;
            }
            $use = ['meta_id' => $by, 'where' => $where];
            if (! in_array($use, $uses[(int) $used] ?? [], true)) {
                $uses[(int) $used][] = $use;
            }
        };

        foreach ($metas as $meta) {
            $isWidget = $typeOf($meta->id) === 'widget';
            // A widget is the page's: what it shows, the page uses.
            $by = $isWidget && $meta->ref_meta_id ? (int) $meta->ref_meta_id : $meta->id;
            $prefix = $isWidget ? "Widget “{$meta->name}”" : 'Setting';
            if (! $isWidget) {
                $add($meta->ref_meta_id, $by, 'Built on it');
            }
            foreach (self::jsonMetaIds($meta->json_config) as [$key, $id]) {
                $add($id, $by, $isWidget && $key === 'meta_id' ? $prefix : "{$prefix}: {$key}");
            }
        }

        foreach (DB::table('meta_params')->get(['main_meta_id', 'ref_meta_id', 'json_config', 'label_name', 'param_name', 'field_path']) as $param) {
            $label = $param->label_name ?: ($param->param_name ?: $param->field_path);
            $what = $typeOf((int) $param->main_meta_id) === 'website' ? "Page “{$label}”" : "Field “{$label}”";
            $add($param->ref_meta_id, (int) $param->main_meta_id, $what);
            foreach (self::jsonMetaIds($param->json_config) as [$key, $id]) {
                $add($id, (int) $param->main_meta_id, "{$what}: {$key}");
            }
        }

        foreach (DB::table('meta_actions')->get(['grid_meta_id', 'form_meta_id', 'get_meta_id', 'label', 'action', 'json_config']) as $action) {
            $what = 'Button “'.($action->label ?: $action->action).'”';
            $add($action->form_meta_id, (int) $action->grid_meta_id, "{$what} opens it");
            $add($action->get_meta_id, (int) $action->grid_meta_id, "{$what} reads from it");
            foreach (self::jsonMetaIds($action->json_config) as [$key, $id]) {
                $add($id, (int) $action->grid_meta_id, "{$what}: {$key}");
            }
        }

        foreach (DB::table('meta_process_workflow')->get(['main_process_id', 'process_id', 'order_number']) as $step) {
            $add($step->process_id, (int) $step->main_process_id, 'Process step'.($step->order_number !== null ? " {$step->order_number}" : ''));
        }
        foreach (DB::table('wfm_status')->whereNotNull('meta_id')->get(['workflow_meta_id', 'meta_id', 'name']) as $status) {
            $add($status->meta_id, (int) $status->workflow_meta_id, "Status “{$status->name}”");
        }
        foreach (DB::table('wfm_transition')->whereNotNull('source_id')->get(['workflow_meta_id', 'source_id']) as $transition) {
            $add($transition->source_id, (int) $transition->workflow_meta_id, 'Workflow transition');
        }
        foreach (DB::table('meta_meta_map')->get(['src_meta_id', 'trg_meta_id', 'type']) as $link) {
            $add($link->trg_meta_id, (int) $link->src_meta_id, 'Link'.($link->type ? " ({$link->type})" : ''));
        }
        foreach (DB::table('meta_notification_map')->whereNotNull('meta_id')->get(['notification_id', 'meta_id']) as $watch) {
            $add($watch->meta_id, (int) $watch->notification_id, 'Notification watches it');
        }

        $schedules = DB::table('meta_schedules')->join('schedules', 'schedules.id', '=', 'meta_schedules.schedule_id')
            ->get(['schedules.name', 'meta_schedules.process_id', 'meta_schedules.view_id']);
        foreach ($schedules as $run) {
            $add($run->process_id, null, "Schedule “{$run->name}”, once per row of {$nameOf((int) $run->view_id)}");
            $add($run->view_id, null, "Schedule “{$run->name}” runs {$nameOf((int) $run->process_id)} on its rows");
        }
        foreach (DB::table('widgets')->whereNotNull('template_meta_id')->get(['name', 'template_meta_id']) as $widget) {
            $add($widget->template_meta_id, null, "Widget library “{$widget->name}”");
        }
        foreach (DB::table('users')->whereNotNull('theme_meta_id')->groupBy('theme_meta_id')->selectRaw('theme_meta_id, COUNT(*) AS people')->get() as $pick) {
            $add($pick->theme_meta_id, null, (int) $pick->people === 1 ? 'Theme of 1 user' : "Theme of {$pick->people} users");
        }

        return $uses;
    }

    /**
     * Every meta id in a json_config, with the key it is under (meta_id or *_meta_id, as in MetaTransfer).
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function jsonMetaIds(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        $found = [];
        $walk = function (mixed $value) use (&$walk, &$found) {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $walk($item);
                } elseif (is_string($key) && ($key === 'meta_id' || str_ends_with($key, '_meta_id')) && is_numeric($item)) {
                    $found[] = [$key, (int) $item];
                }
            }
        };
        $walk($decoded);

        return $found;
    }
}
