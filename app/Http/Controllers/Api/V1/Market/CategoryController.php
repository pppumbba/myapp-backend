<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Models\MarketCategory;
use App\Support\Market\Categories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Categories with their spec templates (staff edit them through metas), and following them. */
class CategoryController extends Controller
{
    /** GET /api/market/categories. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(Categories::all($request->user()));
    }

    /** POST /api/market/categories/{category}/follow. */
    public function follow(Request $request, MarketCategory $category): JsonResponse
    {
        Categories::follow($request->user(), $category, true);

        return response()->json(['followed' => true]);
    }

    /** DELETE /api/market/categories/{category}/follow. */
    public function unfollow(Request $request, MarketCategory $category): JsonResponse
    {
        Categories::follow($request->user(), $category, false);

        return response()->json(['followed' => false]);
    }
}
