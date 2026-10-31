<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company on the market, as ХУР describes it (name, status, director), and who acts for it.
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $fillable = ['registry_number', 'name', 'status', 'director_registry_number', 'director_name', 'checked_at', 'frozen_at', 'created_by'];

    /** The people who act for the company, each with their rights. */
    public function members(): HasMany
    {
        return $this->hasMany(CompanyMember::class);
    }

    /** Whether ХУР says the company is gone, so nobody may act for it. */
    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'frozen_at' => 'datetime'];
    }
}
