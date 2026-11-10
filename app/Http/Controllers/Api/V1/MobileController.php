<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\Access;
use App\Support\CustomerSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phone apps: an app meta with json_config.mobile {enabled, name, package_id, color, icon} is built
 * into its own Android app, which opens straight into that app and nothing else (no builder).
 * The icon is an uploaded square picture: the phone's launcher icon and its sign-in mark.
 */
class MobileController extends Controller
{
    /** Where icons are kept on the local disk, a folder per customer (meta ids repeat across customers). */
    private const DIRECTORY = 'mobile-icons';

    /** A stored icon's file name, as json_config.mobile.icon keeps it: {meta id}-{time}.{ext}. */
    public const ICON_FILE = '/^\d+-\d+\.(png|jpg|webp)$/';

    /**
     * GET /api/mobile/{meta} — what the phone app shows before anyone signs in: its name, colour,
     * icon and customer. Open to guests; only apps set up for phones answer.
     */
    public function show(Meta $meta): JsonResponse
    {
        $mobile = $this->mobile($meta);
        $customer = CustomerSchema::current();

        return response()->json([
            'id' => $meta->id,
            'name' => filled($mobile['name'] ?? null) ? $mobile['name'] : $meta->name,
            'description' => $meta->description,
            'package_id' => $mobile['package_id'] ?? null,
            'color' => $mobile['color'] ?? null,
            // Changes with every new icon, so phones and builds fetch it again (GET /mobile/{meta}/icon).
            'icon' => self::iconFile($meta),
            'customer' => $customer ? ['code' => $customer->code, 'name' => $customer->name] : null,
        ]);
    }

    /** GET /api/mobile/{meta}/icon — the app's icon picture. Open to guests, like show. */
    public function icon(Meta $meta): Response
    {
        $this->mobile($meta);
        $file = self::iconFile($meta);
        abort_unless($file && Storage::disk('local')->exists(self::iconPath($file)), 404, __('This app has no icon.'));

        return Storage::disk('local')->response(self::iconPath($file), null, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * POST /api/metas/{meta}/mobile-icon {icon} — a square PNG, JPG or WebP, at least 192 px, up to 2 MB.
     * Replaces the one before. Answers the meta's json_config.mobile.
     */
    public function saveIcon(Request $request, Meta $meta): JsonResponse
    {
        Access::requireDesign($request->user(), $meta);
        abort_unless($meta->type?->code === 'app', 422, __('Only apps have a phone icon.'));
        $request->validate([
            'icon' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:min_width=192,min_height=192,ratio=1'],
        ], [
            'icon.dimensions' => __('The icon must be square and at least 192 × 192 pixels.'),
        ]);

        $upload = $request->file('icon');
        $ext = match (strtolower($upload->getClientOriginalExtension())) {
            'jpg', 'jpeg' => 'jpg',
            'webp' => 'webp',
            default => 'png',
        };
        $file = $meta->id.'-'.now()->getTimestamp().'.'.$ext;
        Storage::disk('local')->putFileAs(dirname(self::iconPath($file)), $upload, $file);

        return response()->json($this->setIcon($meta, $file, $request->user()->id));
    }

    /** DELETE /api/metas/{meta}/mobile-icon — back to the default mark. */
    public function deleteIcon(Request $request, Meta $meta): JsonResponse
    {
        Access::requireDesign($request->user(), $meta);

        return response()->json($this->setIcon($meta, null, $request->user()->id));
    }

    /** The meta's stored icon file name, or null. */
    public static function iconFile(Meta $meta): ?string
    {
        $file = $meta->json_config['mobile']['icon'] ?? null;

        return is_string($file) && preg_match(self::ICON_FILE, $file) ? $file : null;
    }

    private static function iconPath(string $file): string
    {
        return self::DIRECTORY.'/'.(CustomerSchema::current()?->code ?? 'main').'/'.$file;
    }

    /** Keeps [file] as the icon (null: none) and drops the old picture. */
    private function setIcon(Meta $meta, ?string $file, int $userId): array
    {
        $old = self::iconFile($meta);
        $config = $meta->json_config ?? [];
        $config['mobile'] = array_merge($config['mobile'] ?? [], ['icon' => $file]);
        $meta->update(['json_config' => $config, 'updated_by' => $userId]);
        if ($old && $old !== $file) {
            Storage::disk('local')->delete(self::iconPath($old));
        }

        return $config['mobile'];
    }

    /** The app's phone settings; 404 when it isn't set up for phones. */
    private function mobile(Meta $meta): array
    {
        $mobile = $meta->json_config['mobile'] ?? null;
        abort_unless(
            $meta->is_active && $meta->type?->code === 'app' && is_array($mobile) && ($mobile['enabled'] ?? false),
            404,
            __('This app is not set up for phones.'),
        );

        return $mobile;
    }
}
