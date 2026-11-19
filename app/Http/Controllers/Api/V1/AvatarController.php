<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profile pictures. Users live in the main schema, so pictures do too: one folder for every
 * customer (storage/app/avatars), not the customer's own files disk.
 */
class AvatarController extends Controller
{
    /** {user id}-{random}.{ext}: a new name per upload, so browsers never show an old one. */
    private const FILE = '/^\d+-[A-Za-z0-9]{16}\.(png|jpg|webp|gif)$/';

    /**
     * GET /api/avatars/{file} — open to anyone with the name (an <img> sends no token); the name
     * is random and changes with every upload, so it can be cached for long.
     */
    public function show(string $file): Response
    {
        abort_unless(preg_match(self::FILE, $file) && self::disk()->exists($file), 404);

        return self::disk()->response($file, null, ['Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    /** POST /api/user/avatar {avatar} — a PNG, JPG, WebP or GIF up to 5 MB; replaces the one before. Answers the user. */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,gif', 'max:5120', 'dimensions:min_width=32,min_height=32,max_width=8000,max_height=8000'],
        ], [
            'avatar.dimensions' => __('The picture must be between 32 and 8000 pixels on each side.'),
        ]);

        $user = $request->user();
        $upload = $request->file('avatar');
        $ext = match ($upload->guessExtension()) {
            'jpg', 'jpeg' => 'jpg',
            'webp' => 'webp',
            'gif' => 'gif',
            default => 'png',
        };
        $file = $user->id.'-'.Str::random(16).'.'.$ext;
        self::disk()->putFileAs('', $upload, $file);

        return response()->json($this->setAvatar($user, $file));
    }

    /** DELETE /api/user/avatar — back to initials. */
    public function destroy(Request $request): JsonResponse
    {
        return response()->json($this->setAvatar($request->user(), null));
    }

    /** Keeps [file] as the picture (null: none) and drops the old one. */
    private function setAvatar(User $user, ?string $file): User
    {
        $old = $user->avatar;
        $user->update(['avatar' => $file]);
        if ($old && $old !== $file && preg_match(self::FILE, $old)) {
            self::disk()->delete($old);
        }

        return $user;
    }

    private static function disk(): Filesystem
    {
        return Storage::disk('avatars');
    }
}
