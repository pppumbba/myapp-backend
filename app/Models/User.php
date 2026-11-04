<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\OnMainSchema;
use App\Support\CustomerSchema;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'theme_mode', 'ui_size', 'preferences', 'locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, OnMainSchema;

    /** @var list<string> */
    protected $appends = ['theme_meta_id'];

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

    /**
     * The builder features the user's roles give (Role::FEATURES); an admin has them all.
     *
     * @return list<string>
     */
    public function features(): array
    {
        return $this->perRequest('features', fn () => $this->isAdmin()
            ? Role::FEATURES
            : array_values(array_intersect(Role::FEATURES, $this->roles()->pluck('features')->flatten()->unique()->all())));
    }

    /** Whether the user may make metas and change their own: a "build" or "studio" role, or an admin. */
    public function isBuilder(): bool
    {
        return $this->features() !== [];
    }

    /** After the user's roles change: isAdmin(), roleIds() and features() look again. */
    public function forgetRoles(): void
    {
        foreach (['is_admin', 'role_ids', 'features'] as $key) {
            request()->attributes->remove(self::requestKey($this->id, $key));
        }
    }

    /**
     * Worked out once per request: access checks ask many times. Kept on the request, not
     * the model, as one user object can outlive a request (e.g. Sanctum::actingAs in tests).
     */
    private function perRequest(string $key, callable $value): mixed
    {
        $cache = request()->attributes;
        $key = self::requestKey($this->id, $key);
        if (! $cache->has($key)) {
            $cache->set($key, $value());
        }

        return $cache->get($key);
    }

    /** Roles differ per customer, so what perRequest() keeps is kept per customer too. */
    private static function requestKey(int $userId, string $key): string
    {
        return 'user.'.$userId.'.c'.(CustomerSchema::current()?->id ?? 0).'.'.$key;
    }

    /** The customers this person is in. */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_users')->orderBy('name');
    }

    public function isIn(Customer $customer): bool
    {
        return CustomerUser::where('customer_id', $customer->id)->where('user_id', $this->id)->exists();
    }

    /** The theme picked in the current customer (a meta of that customer); none outside a customer. */
    protected function themeMetaId(): Attribute
    {
        return Attribute::get(fn () => ($customer = CustomerSchema::current()) && $this->id
            ? CustomerUser::where('customer_id', $customer->id)->where('user_id', $this->id)->value('theme_meta_id')
            : null);
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
