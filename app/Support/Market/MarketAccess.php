<?php

namespace App\Support\Market;

use App\Models\Company;
use App\Models\User;

/** Who may act for a company on the market. */
class MarketAccess
{
    /** The rights a member can hold; browse comes with membership. */
    public const RIGHTS = ['browse', 'bid', 'sign', 'manage'];

    /**
     * Whether the user may do this for the company: they are verified, a member, the company
     * is not frozen, and (beyond browse) they hold that right.
     */
    public static function canActFor(?User $user, Company $company, string $right): bool
    {
        if (! $user?->isVerified() || $company->isFrozen() || ! in_array($right, self::RIGHTS, true)) {
            return false;
        }
        $member = $company->members()->where('user_id', $user->id)->first();
        if (! $member) {
            return false;
        }

        return $right === 'browse' || (bool) $member->{"can_{$right}"};
    }
}
