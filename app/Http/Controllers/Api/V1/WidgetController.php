<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaType;
use App\Models\Widget;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The page builder's widget library (see Widget). Everyone reads it; admins change it. A
 * composite entry gets its template meta (its parts, laid out like a page) when it is made.
 */
class WidgetController extends Controller
{
    /** GET /widgets — admins also see inactive entries, with how many widget metas use each. */
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user()?->isAdmin();

        return response()->json(
            Widget::query()
                ->when(! $admin, fn ($q) => $q->where('is_active', true))
                ->when($admin, fn ($q) => $q->withCount('metas'))
                ->orderBy('order_number')->orderBy('name')
                ->get()
        );
    }

    /** POST /widgets */
    public function store(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $this->validated($request);
        $data['order_number'] ??= (int) Widget::max('order_number') + 1;

        $widget = DB::transaction(fn () => $this->withTemplate(Widget::create($data), $request));

        return response()->json($widget, 201);
    }

    /** PUT /widgets/{widget} */
    public function update(Request $request, Widget $widget): JsonResponse
    {
        Access::requireAdmin($request->user());
        $widget->update($this->validated($request, $widget));

        return response()->json($this->withTemplate($widget->fresh(), $request));
    }

    /** DELETE /widgets/{widget} — refused while widget metas still use it. */
    public function destroy(Request $request, Widget $widget): JsonResponse
    {
        Access::requireAdmin($request->user());
        $used = $widget->metas()->count();
        abort_if($used > 0, 422, trans_choice('{1} :count placed widget uses this. Remove it from its page, or make this inactive instead.|[2,*] :count placed widgets use this. Remove them from their pages, or make this inactive instead.', $used));
        DB::transaction(function () use ($widget) {
            // A composite's parts go with it: the template's own widget metas, then the template.
            if ($template = $widget->template) {
                Meta::where('ref_meta_id', $template->id)->where('type_id', MetaType::where('code', 'widget')->value('id'))->delete();
                $widget->delete();
                $template->delete();
            } else {
                $widget->delete();
            }
        });

        return response()->json(null, 204);
    }

    /** A composite with no template yet gets an empty one, ready for its parts. */
    private function withTemplate(Widget $widget, Request $request): Widget
    {
        if ($widget->renderer !== 'composite' || $widget->template_meta_id) {
            return $widget;
        }
        $type = MetaType::where('code', 'widget_template')->value('id');
        abort_unless($type, 422, 'The widget_template meta type is missing. Run the MetaTypeSeeder.');

        $code = 'widget_'.$widget->code.'_parts';
        for ($n = 2; Meta::where('code', $code)->exists(); $n++) {
            $code = 'widget_'.$widget->code."_parts_{$n}";
        }
        $template = Meta::create([
            'code' => $code,
            'name' => "{$widget->name} (parts)",
            'type_id' => $type,
            'description' => "The widgets the {$widget->name} widget is made of.",
            'is_active' => true,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        $widget->update(['template_meta_id' => $template->id]);

        return $widget;
    }

    private function validated(Request $request, ?Widget $current = null): array
    {
        $req = $current ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$req, 'string', 'max:100', 'regex:/^[a-z0-9_]+$/', Rule::unique('widgets', 'code')->ignore($current?->id)],
            'name' => [$req, 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:100'],
            'renderer' => [$req, Rule::in(Widget::RENDERERS)],
            'icon' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'props' => ['nullable', 'array'],
            'props.*.key' => ['required', 'string', 'max:100', 'distinct'],
            'props.*.label' => ['required', 'string', 'max:255'],
            'props.*.type' => ['required', Rule::in(Widget::PROP_TYPES)],
            'props.*.options' => ['sometimes', 'array'],
            'props.*.meta_types' => ['sometimes', 'array'],
            'props.*.fields' => ['sometimes', 'array'],
            'props.*.hint' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'order_number' => ['sometimes', 'integer'],
        ]);
    }
}
