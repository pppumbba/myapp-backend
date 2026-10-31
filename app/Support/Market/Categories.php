<?php

namespace App\Support\Market;

use App\Models\CategoryFollow;
use App\Models\MarketCategory;
use App\Models\MarketCategorySpec;
use App\Models\User;

/** Categories for tenders and listings, with the spec template each new item starts from. */
class Categories
{
    /**
     * Active categories with their spec templates, and whether this user follows each.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(User $user): array
    {
        $followed = CategoryFollow::where('user_id', $user->id)->pluck('category_id')->all();

        return MarketCategory::where('is_active', true)->with('specs')->orderBy('name')->get()
            ->map(fn (MarketCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'parent_id' => $c->parent_id,
                'followed' => in_array($c->id, $followed, true),
                'specs' => $c->specs->map(fn (MarketCategorySpec $s) => [
                    'label' => $s->label,
                    'kind' => $s->kind,
                    'operator' => $s->operator,
                    'value' => $s->value,
                    'unit' => $s->unit,
                    'must' => $s->must,
                ])->all(),
            ])->all();
    }

    /** Follows (or stops following) a category, to hear of new tenders in it. */
    public static function follow(User $user, MarketCategory $category, bool $on): void
    {
        if ($on) {
            CategoryFollow::firstOrCreate(['user_id' => $user->id, 'category_id' => $category->id]);
        } else {
            CategoryFollow::where('user_id', $user->id)->where('category_id', $category->id)->delete();
        }
    }
}
