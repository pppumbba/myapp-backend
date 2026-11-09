<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\CustomerSchema;
use Illuminate\Http\JsonResponse;

/**
 * Phone apps: an app meta with json_config.mobile {enabled, name, package_id, color} is built into
 * its own Android app, which opens straight into that app and nothing else (no builder).
 */
class MobileController extends Controller
{
    /**
     * GET /api/mobile/{meta} — what the phone app shows before anyone signs in: its name, colour and
     * customer. Open to guests; only apps set up for phones answer.
     */
    public function show(Meta $meta): JsonResponse
    {
        $mobile = $meta->json_config['mobile'] ?? null;
        abort_unless(
            $meta->is_active && $meta->type?->code === 'app' && is_array($mobile) && ($mobile['enabled'] ?? false),
            404,
            __('This app is not set up for phones.'),
        );
        $customer = CustomerSchema::current();

        return response()->json([
            'id' => $meta->id,
            'name' => filled($mobile['name'] ?? null) ? $mobile['name'] : $meta->name,
            'description' => $meta->description,
            'package_id' => $mobile['package_id'] ?? null,
            'color' => $mobile['color'] ?? null,
            'customer' => $customer ? ['code' => $customer->code, 'name' => $customer->name] : null,
        ]);
    }
}
