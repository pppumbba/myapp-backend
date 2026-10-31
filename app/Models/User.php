<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'theme_mode', 'theme_meta_id', 'ui_size', 'preferences', 'locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * A new account. The very first account runs the place: it gets the admin role.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function startAccount(array $attributes): self
    {
        $first = ! self::exists();
        $user = self::create($attributes);
        if ($first) {
            $user->roles()->attach(Role::firstOrCreate(['code' => Role::ADMIN], ['name' => 'Administrator'])->id);
            $user->forgetRoles();
        }

        return $user;
    }

    /** Sign-in accounts at other providers (Google) linked to this user. */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    /** @return int[] */
    public function roleIds(): array
    {
        return $this->perRequest('role_ids', fn () => $this->roles()->pluck('roles.id')->all());
    }

    public function isAdmin(): bool
    {
        return $this->perRequest('is_admin', fn () => $this->roles()->where('code', Role::ADMIN)->exists());
    }

    /** After the user's roles change: isAdmin() and roleIds() look again. */
    public function forgetRoles(): void
    {
        request()->attributes->remove("user.{$this->id}.is_admin");
        request()->attributes->remove("user.{$this->id}.role_ids");
    }

    /**
     * Worked out once per request: access checks ask many times. Kept on the request, not
     * the model, as one user object can outlive a request (e.g. Sanctum::actingAs in tests).
     */
    private function perRequest(string $key, callable $value): mixed
    {
        $cache = request()->attributes;
        $key = "user.{$this->id}.{$key}";
        if (! $cache->has($key)) {
            $cache->set($key, $value());
        }

        return $cache->get($key);
    }

    /** The companies this user acts for, with their rights in each. */
    public function companyMemberships(): HasMany
    {
        return $this->hasMany(CompanyMember::class);
    }

    /** Whether DAN has confirmed who this person is. */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** What this user has been told about. */
    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }
}
