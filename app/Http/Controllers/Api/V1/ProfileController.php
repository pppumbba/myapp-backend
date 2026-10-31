<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** The signed-in user's own account: read it, change name and email, change password. */
class ProfileController extends Controller
{
    /** GET /user */
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    /** PUT /user */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->update($data);

        return response()->json($user);
    }

    /**
     * PUT /user/preferences {key: value, ...} — merges into the user's preferences; only the keys
     * listed here may be set. form_saved_notice: show "Saved" inside a form after saving (default off);
     * chat_sound: a soft sound for new messenger messages (default on).
     */
    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'form_saved_notice' => ['sometimes', 'boolean'],
            'chat_sound' => ['sometimes', 'boolean'],
        ]);

        $user->update(['preferences' => [...$user->preferences ?? [], ...$data]]);

        return response()->json($user);
    }

    /**
     * PUT /user/password. Needs the current password; afterwards every other session
     * (other browsers and devices) is signed out, and this one stays signed in.
     */
    public function password(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ]);

        $user->update(['password' => Hash::make($data['password'])]);

        $current = $user->currentAccessToken();
        $user->tokens()->when($current?->id, fn ($q, $id) => $q->whereKeyNot($id))->delete();

        return response()->json(['message' => __('Password changed.')]);
    }
}
