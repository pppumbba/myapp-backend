<?php

namespace App\Models;

use App\Models\Concerns\OnCustomerSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A role users can have. The "admin" role sees and changes everything. features are builder
 * abilities: "build" makes metas and changes the user's own, "studio" builds their own apps
 * in the studio (see Builders).
 */
class Role extends Model
{
    use OnCustomerSchema;

    public const ADMIN = 'admin';

    /** What a role's features may hold. */
    public const FEATURES = ['build', 'studio'];

    protected $fillable = ['code', 'name', 'description', 'features'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['features' => 'array'];
    }
}
