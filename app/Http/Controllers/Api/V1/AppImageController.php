<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\Access;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * An app's own pictures, instead of the Wand mark: its icon (its brand: the logo inside the app
 * and on its tile, metas.image_path) and its card cover (metas.cover_path). Kept on the
 * app-images disk (storage/app/app-images) under random names; each column holds where, as a
 * path under storage/app (app-images/{file}).
 */
class AppImageController extends Controller
{
    /** {meta id}-{random}.{ext}: a new name per upload, so browsers never show an old one. */
    public const FILE = '/^\d+-[A-Za-z0-9]{24}\.(png|jpg|webp)$/';

    /** The folder under storage/app that the paths start with. */
    private const FOLDER = 'app-images/';

    /** Which picture a request is about => the column holding it. */
    private const KINDS = ['icon' => 'image_path', 'cover' => 'cover_path'];

    /**
     * GET /api/app-images/{file} — open to anyone with the name (an <img> sends no token); the
     * name is random and changes with every upload, so it can be cached for long.
     */
    public function show(string $file): Response
    {
        abort_unless(preg_match(self::FILE, $file) && self::disk()->exists($file), 404);

        return self::disk()->response($file, null, ['Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    /**
     * POST /api/metas/{meta}/app-image {image, kind: icon|cover (icon)} — a PNG, JPG or WebP up to
     * 5 MB; replaces the one before. Answers {image_path, cover_path}.
     */
    public function store(Request $request, Meta $meta): JsonResponse
    {
        Access::requireDesign($request->user(), $meta);
        abort_unless($meta->type?->code === 'app', 422, __('Only apps have a picture.'));
        $request->validate([
            'image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120', 'dimensions:min_width=64,min_height=64,max_width=8000,max_height=8000'],
            'kind' => ['sometimes', 'in:icon,cover'],
        ], [
            'image.dimensions' => __('The picture must be between 64 and 8000 pixels on each side.'),
        ]);

        $upload = $request->file('image');
        $ext = match ($upload->guessExtension()) {
            'jpg', 'jpeg' => 'jpg',
            'webp' => 'webp',
            default => 'png',
        };
        $file = $meta->id.'-'.Str::random(24).'.'.$ext;
        self::disk()->putFileAs('', $upload, $file);

        return response()->json($this->setImage($meta, self::KINDS[$request->input('kind', 'icon')], $file, $request->user()->id));
    }

    /** DELETE /api/metas/{meta}/app-image?kind=icon|cover (icon) — back to the Wand mark. */
    public function destroy(Request $request, Meta $meta): JsonResponse
    {
        Access::requireDesign($request->user(), $meta);
        $request->validate(['kind' => ['sometimes', 'in:icon,cover']]);

        return response()->json($this->setImage($meta, self::KINDS[$request->query('kind', 'icon')], null, $request->user()->id));
    }

    /** The file name on the disk of the meta's picture in [column] (image_path by default), or null. */
    public static function imageFile(Meta $meta, string $column = 'image_path'): ?string
    {
        $path = $meta->{$column};
        if (! is_string($path) || ! str_starts_with($path, self::FOLDER)) {
            return null;
        }
        $file = substr($path, strlen(self::FOLDER));

        return preg_match(self::FILE, $file) ? $file : null;
    }

    /**
     * Keeps [file] as the picture in [column] (null: none) and drops the old one, unless a copy
     * of the app (or its other picture) still uses it.
     *
     * @return array{image_path: ?string, cover_path: ?string}
     */
    private function setImage(Meta $meta, string $column, ?string $file, int $userId): array
    {
        $old = self::imageFile($meta, $column);
        $meta->update([$column => $file ? self::FOLDER.$file : null, 'updated_by' => $userId]);
        $oldPath = self::FOLDER.$old;
        if ($old && $old !== $file && ! Meta::where('image_path', $oldPath)->orWhere('cover_path', $oldPath)->exists()) {
            self::disk()->delete($old);
        }

        return ['image_path' => $meta->image_path, 'cover_path' => $meta->cover_path];
    }

    private static function disk(): Filesystem
    {
        return Storage::disk('app-images');
    }
}
