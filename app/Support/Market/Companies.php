<?php

namespace App\Support\Market;

use App\Models\Company;
use App\Models\CompanyInvite;
use App\Models\CompanyMember;
use App\Models\Content;
use App\Models\RepresentationRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\Market\Khur\KhurDriver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Companies on the market: registered from ХУР, and who acts for them.
 */
class Companies
{
    /** A company registry number: 7 digits. */
    public const REGISTRY_NUMBER = '/^\d{7}$/';

    /** Every right, as an approved representative gets them. */
    public const ALL_RIGHTS = ['can_bid' => true, 'can_sign' => true, 'can_manage' => true];

    /** What a power of attorney may be sent as, and how big. */
    public const ATTORNEY_TYPES = ['pdf', 'jpg', 'jpeg', 'png'];

    public const ATTORNEY_MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Registers the company with this number (or finds it, when someone already did). Its
     * director, by registry number, becomes a member with every right.
     *
     * @return array{company: Company, member: ?CompanyMember}
     */
    public static function register(User $user, string $registryNumber): array
    {
        abort_unless($user->isVerified(), 403, __('Verify yourself with DAN first.'));
        $number = trim($registryNumber);
        abort_unless(preg_match(self::REGISTRY_NUMBER, $number) === 1, 422, __('A company registry number is 7 digits.'));

        $company = Company::where('registry_number', $number)->first();
        if (! $company) {
            $found = app(KhurDriver::class)->company($number);
            abort_unless($found, 422, __('ХУР has no company with this registry number.'));
            abort_if($found['status'] === 'liquidated', 422, __('This company is liquidated.'));
            $company = Company::create([
                'registry_number' => $number,
                'name' => $found['name'],
                'status' => $found['status'],
                'director_registry_number' => $found['director_registry_number'],
                'director_name' => $found['director_name'],
                'checked_at' => now(),
                'created_by' => $user->id,
            ]);
        }

        if ($company->director_registry_number !== null && $company->director_registry_number === $user->registry_number) {
            CompanyMember::firstOrCreate(['company_id' => $company->id, 'user_id' => $user->id], self::ALL_RIGHTS);
        }

        return ['company' => $company, 'member' => $company->members()->where('user_id', $user->id)->first()];
    }

    /**
     * Asks to act for a company. A director request gives a one-time link for the company's
     * director to approve; an attorney request carries the power of attorney for staff to review.
     *
     * @return array{request: RepresentationRequest, approve_url: ?string}
     */
    public static function requestRepresentation(User $user, Company $company, string $method, ?UploadedFile $file = null): array
    {
        abort_unless($user->isVerified(), 403, __('Verify yourself with DAN first.'));
        abort_if($company->isFrozen(), 422, __('This company is frozen: ХУР says it is no longer active.'));
        abort_if($company->members()->where('user_id', $user->id)->exists(), 422, __('You already act for this company.'));
        abort_if(
            RepresentationRequest::where(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'pending'])->exists(),
            422,
            __('You already asked to act for this company. Wait for the answer.'),
        );
        abort_unless(in_array($method, ['director', 'attorney'], true), 422, __('Ask the director, or send a power of attorney.'));

        if ($method === 'director') {
            $token = Str::random(48);
            $request = RepresentationRequest::create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'method' => 'director',
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(14),
            ]);

            return ['request' => $request, 'approve_url' => self::frontendUrl("/market/approve/{$token}")];
        }

        abort_unless(
            $file && $file->isValid() && in_array(strtolower($file->getClientOriginalExtension()), self::ATTORNEY_TYPES, true) && $file->getSize() <= self::ATTORNEY_MAX_BYTES,
            422,
            __('Send the power of attorney as a PDF or a photo (JPG or PNG), up to 10 MB.'),
        );
        $content = Content::create([
            'physical_path' => $file->store('market/attorney', 'local'),
            'file_name' => $file->getClientOriginalName(),
            'file_extension' => strtolower($file->getClientOriginalExtension()),
            'file_size' => $file->getSize(),
            'is_public' => false,
            'created_by' => $user->id,
        ]);
        $request = RepresentationRequest::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'method' => 'attorney',
            'content_id' => $content->id,
        ]);

        $admins = User::whereHas('roles', fn ($q) => $q->where('code', Role::ADMIN))->get();
        foreach ($admins as $admin) {
            MarketNotify::send($admin, __('A power of attorney to review'), __(':person asks to act for :company.', [
                'person' => $user->verified_name,
                'company' => $company->name,
            ]), '/admin/market-reviews');
        }

        return ['request' => $request, 'approve_url' => null];
    }

    /** Staff answer a power of attorney request; a rejection needs a reason, which the requester sees. */
    public static function decideByStaff(User $admin, RepresentationRequest $request, bool $approve, ?string $reason): RepresentationRequest
    {
        abort_unless($request->method === 'attorney' && $request->status === 'pending', 422, __('This request has already been decided.'));
        $reason = filled($reason) ? trim((string) $reason) : null;
        abort_if(! $approve && $reason === null, 422, __('Say why the request is declined.'));

        return self::decide($request, $admin, $approve, $reason);
    }

    /** The pending director request behind an approval link; 404 when the link is unknown, used or expired. */
    public static function approvalFor(string $token): RepresentationRequest
    {
        $request = RepresentationRequest::with(['company', 'user'])
            ->where('token_hash', hash('sha256', $token))
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();
        abort_unless($request, 404, __('This link is no longer valid.'));

        return $request;
    }

    /** The director answers a request: only the person ХУР lists as the company's director may. */
    public static function decideByDirector(User $director, string $token, bool $approve): RepresentationRequest
    {
        $request = self::approvalFor($token);
        $company = $request->company;
        abort_unless(
            $director->isVerified() && $company->director_registry_number !== null && $director->registry_number === $company->director_registry_number,
            403,
            __('Only the director registered in ХУР can decide this.'),
        );

        return self::decide($request, $director, $approve, null);
    }

    /**
     * Closes a request: an approval makes the requester a member with every right; either way
     * the requester is told.
     */
    private static function decide(RepresentationRequest $request, User $by, bool $approve, ?string $reason): RepresentationRequest
    {
        DB::transaction(function () use ($request, $by, $approve, $reason) {
            $request->update([
                'status' => $approve ? 'approved' : 'rejected',
                'reason' => $reason,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'token_hash' => null,
            ]);
            if ($approve) {
                CompanyMember::firstOrCreate(['company_id' => $request->company_id, 'user_id' => $request->user_id], self::ALL_RIGHTS);
            }
        });

        $name = $request->company->name;
        MarketNotify::send(
            $request->user,
            $approve ? __('You now act for :company', ['company' => $name]) : __('Your request to act for :company was declined', ['company' => $name]),
            $reason,
            '/market/account',
            $approve ? 'success' : 'alert',
        );

        return $request;
    }

    /**
     * A one-time link for a colleague to join with these rights (valid for 7 days).
     *
     * @param  array{can_bid?: bool, can_sign?: bool, can_manage?: bool}  $rights
     */
    public static function invite(User $by, Company $company, array $rights): string
    {
        self::requireManager($by, $company);
        $token = Str::random(48);
        CompanyInvite::create([
            'company_id' => $company->id,
            'token_hash' => hash('sha256', $token),
            ...self::rights($rights),
            'created_by' => $by->id,
            'expires_at' => now()->addDays(7),
        ]);

        return self::frontendUrl("/market/join/{$token}");
    }

    /** The colleague follows the invite: they join with its rights. */
    public static function acceptInvite(User $user, string $token): CompanyMember
    {
        abort_unless($user->isVerified(), 403, __('Verify yourself with DAN first.'));
        $invite = CompanyInvite::with('company')->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')->where('expires_at', '>', now())->first();
        abort_unless($invite, 404, __('This link is no longer valid.'));
        abort_if($invite->company->isFrozen(), 422, __('This company is frozen: ХУР says it is no longer active.'));
        abort_if($invite->company->members()->where('user_id', $user->id)->exists(), 422, __('You already act for this company.'));

        return DB::transaction(function () use ($invite, $user) {
            $invite->update(['used_by' => $user->id, 'used_at' => now()]);

            return CompanyMember::create([
                'company_id' => $invite->company_id,
                'user_id' => $user->id,
                'can_bid' => $invite->can_bid,
                'can_sign' => $invite->can_sign,
                'can_manage' => $invite->can_manage,
            ]);
        });
    }

    /**
     * A manager changes what a member may do.
     *
     * @param  array{can_bid?: bool, can_sign?: bool, can_manage?: bool}  $rights
     */
    public static function updateRights(User $by, CompanyMember $member, array $rights): CompanyMember
    {
        self::requireManager($by, $member->company);
        $rights = self::rights($rights);
        abort_if($member->can_manage && ! $rights['can_manage'] && self::isLastManager($member), 422, __('A company needs at least one member who can manage it.'));
        $member->update($rights);

        return $member;
    }

    /** A manager removes a member (a member may also remove themselves). */
    public static function removeMember(User $by, CompanyMember $member): void
    {
        if ($member->user_id !== $by->id) {
            self::requireManager($by, $member->company);
        }
        abort_if($member->can_manage && self::isLastManager($member), 422, __('A company needs at least one member who can manage it.'));
        $member->delete();
    }

    /**
     * Reads the company from ХУР again. A company gone liquidated is frozen (its members are
     * told); one active again is thawed. Returns whether anything changed.
     */
    public static function recheck(Company $company): bool
    {
        $found = app(KhurDriver::class)->company($company->registry_number);
        $company->checked_at = now();
        if ($found) {
            $company->fill([
                'name' => $found['name'],
                'status' => $found['status'],
                'director_registry_number' => $found['director_registry_number'],
                'director_name' => $found['director_name'],
            ]);
        }
        $gone = ($found['status'] ?? 'liquidated') === 'liquidated';
        $freezes = $gone && ! $company->isFrozen();
        $company->frozen_at = $gone ? ($company->frozen_at ?? now()) : null;
        $changed = $company->isDirty(['name', 'status', 'director_registry_number', 'director_name', 'frozen_at']);
        $company->save();

        if ($freezes) {
            foreach ($company->members()->with('user')->get() as $member) {
                MarketNotify::send($member->user, __(':company is frozen', ['company' => $company->name]), __('ХУР says the company is no longer active, so no one can act for it.'), '/market/account', 'danger');
            }
        }

        return $changed;
    }

    /** 403 unless the user may manage the company's members. */
    private static function requireManager(User $user, Company $company): void
    {
        abort_unless(MarketAccess::canActFor($user, $company, 'manage'), 403, __('Only the company\'s managers can do this.'));
    }

    /** Whether no one else in the company can manage it. */
    private static function isLastManager(CompanyMember $member): bool
    {
        return ! CompanyMember::where('company_id', $member->company_id)->whereKeyNot($member->id)->where('can_manage', true)->exists();
    }

    /**
     * The three rights, each a plain boolean.
     *
     * @param  array<string, mixed>  $rights
     * @return array{can_bid: bool, can_sign: bool, can_manage: bool}
     */
    private static function rights(array $rights): array
    {
        return [
            'can_bid' => (bool) ($rights['can_bid'] ?? false),
            'can_sign' => (bool) ($rights['can_sign'] ?? false),
            'can_manage' => (bool) ($rights['can_manage'] ?? false),
        ];
    }

    /** A page of the frontend. */
    private static function frontendUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }

    /**
     * A company as the API shows it.
     *
     * @return array{id: int, name: string, registry_number: string, status: string, frozen: bool}
     */
    public static function show(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'registry_number' => $company->registry_number,
            'status' => $company->status,
            'frozen' => $company->isFrozen(),
        ];
    }
}
