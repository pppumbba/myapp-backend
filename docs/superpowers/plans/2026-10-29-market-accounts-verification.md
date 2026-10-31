# Market part 1: accounts & verification — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** People verify with DAN, register companies checked through ХУР, become company representatives (director approval or power of attorney reviewed by staff), and manage colleagues' rights. Everything runs against fake DAN and ХУР drivers.

**Architecture:**
- A `Market` area in the backend (`app/Support/Market`, `app/Http/Controllers/Api/V1/Market`) with its own tables. The services make every access decision. Controllers stay thin.
- DAN and ХУР sit behind driver interfaces bound in the container. Only the fake drivers are built here: the real ones need the government agreement and come in a later plan.
- In the frontend, signed-in users get a "Market account" area, and admins get a review queue.

**Tech stack:**
- Backend: Laravel 13, Sanctum bearer tokens, MySQL/MariaDB, PHPUnit feature tests.
- Frontend: React 19, react-router 7 and Untitled UI components.

**Spec:** `docs/superpowers/specs/2026-10-29-tender-marketplace-design.md` (§6.1 and §8 part 1).

## Global constraints

- Follow `myapp-backend/CLAUDE.md`:
  - Create files with `php artisan make:* --no-interaction`.
  - Use curly braces everywhere, explicit return types and PHPDoc array shapes.
  - Run `vendor/bin/pint --dirty --format agent` after any PHP change.
  - Feature tests use `RefreshDatabase`, with commands like `php artisan test --compact <file>`.
- **No new dependencies.**
- Backend messages use `__()`. Every new string also goes in `lang/mn.json`.
- Frontend text uses `t()`. Every new string also goes in `src/i18n/mn.json` (check the JSON parses afterwards).
- Admin-only endpoints call `Access::requireAdmin($request->user())`.
- **Individual registry number** (регистрийн дугаар): two Cyrillic capitals then 8 digits, `/^[А-ЯӨҮЁ]{2}\d{8}$/u`. Before validating, trim it and uppercase it with `mb_strtoupper`.
- **Company registry number:** 7 digits, `/^\d{7}$/`.
- **Rights:** `browse` is implied for every member. Stored rights are the `can_bid`, `can_sign` and `can_manage` booleans. An approved representative gets all three. An invited colleague gets whatever the inviter picks.
- **Tokens:**
  - Director-approval and invite tokens are `Str::random(48)`. Only `hash('sha256', $token)` is stored.
  - Director tokens expire after 14 days and invite tokens after 7 days.
  - Each token works once.
- **DAN state:** `Str::random(40)` kept in the cache as `market-dan:{state}` (the user id) for 10 minutes, and pulled once on use.
- **Frontend URLs** are built from `config('app.frontend_url')`, as in GoogleAuthController.
- **Commits:** the backend repo is git. Commit at the end of each task **only if the owner has said to commit**. Otherwise leave the work uncommitted. The frontend folder is not a repo.

## Review focus

1. The same person (registry number) tries to verify a second account. This is refused with 422, and the first account keeps the number. Tested in Task 2.
2. A DAN callback comes back with a reused, expired or unknown state. The browser goes to the frontend with `dan_error`, and nobody gets verified. Tested in Task 2.
3. A verified user who is not the ХУР director opens a director-approval link. They get 403, and the request stays pending. Tested in Task 4.
4. The last member with `can_manage` removes themselves or drops their own manage right. This is refused with 422. Tested in Task 6.
5. A second person registers a company that is already on the platform. No duplicate is made: they get the existing company, without membership. A company ХУР lists as liquidated can't be registered. Tested in Task 3.

---

### Task 1: Tables, models and the market config

**Files:**
- Create: migration `database/migrations/2026_11_30_000000_create_market_account_tables.php`
- Create: `app/Models/Company.php`, `CompanyMember.php`, `RepresentationRequest.php`, `CompanyInvite.php`
- Create: `database/factories/CompanyFactory.php`
- Create: `config/market.php`
- Modify: `app/Models/User.php` (fillable/casts and relations)
- Test: `tests/Feature/Market/MarketModelsTest.php`

**Schema:**
- `users`: add `registry_number` (string 10, nullable, unique), `verified_name` (string, nullable) and `verified_at` (timestamp, nullable).
- `companies`:
  - `id`
  - `registry_number` (string 7, unique)
  - `name`
  - `status` (string 20: `active` | `liquidated`)
  - `director_registry_number` (string 10, nullable)
  - `director_name` (nullable)
  - `checked_at` (timestamp)
  - `frozen_at` (timestamp, nullable)
  - `created_by` (FK users, nullOnDelete)
  - timestamps
- `company_members`: `id`, `company_id` (FK cascade), `user_id` (FK cascade), `can_bid`, `can_sign`, `can_manage` (bool, default false), timestamps. Unique on `company_id` + `user_id`.
- `representation_requests`:
  - `id`
  - `company_id` (FK cascade)
  - `user_id` (FK cascade)
  - `method` (string 10: `director` | `attorney`)
  - `status` (string 10: `pending` | `approved` | `rejected`, default `pending`)
  - `token_hash` (string 64, nullable, unique)
  - `expires_at` (nullable)
  - `content_id` (FK contents, nullOnDelete, nullable)
  - `reason` (text, nullable)
  - `decided_by` (FK users, nullOnDelete, nullable)
  - `decided_at` (nullable)
  - timestamps
  - index on `status` + `created_at`
- `company_invites`: `id`, `company_id` (FK cascade), `token_hash` (unique), `can_bid`, `can_sign`, `can_manage`, `created_by` (FK users), `expires_at`, `used_by` (nullable FK users), `used_at` (nullable), timestamps.

**`config/market.php`:**
```php
return [
    'dan' => ['driver' => env('MARKET_DAN_DRIVER', 'fake')],
    'khur' => ['driver' => env('MARKET_KHUR_DRIVER', 'fake')],
    // Test people and companies the fake drivers answer with.
    'fake' => [
        'citizens' => [
            ['registry_number' => 'УБ90010112', 'name' => 'Бат-Эрдэнэ Ганбаатар'],
            ['registry_number' => 'УБ85051523', 'name' => 'Сарангэрэл Должин'],
            ['registry_number' => 'ДА92120334', 'name' => 'Тэмүүлэн Болд'],
        ],
        'companies' => [
            ['registry_number' => '5123456', 'name' => 'Говь Ханган ХХК', 'status' => 'active', 'director_registry_number' => 'УБ90010112', 'director_name' => 'Бат-Эрдэнэ Ганбаатар'],
            ['registry_number' => '6234567', 'name' => 'Талст Үйлчилгээ ХХК', 'status' => 'active', 'director_registry_number' => 'УБ85051523', 'director_name' => 'Сарангэрэл Должин'],
            ['registry_number' => '2345678', 'name' => 'Хуучин Наймаа ХХК', 'status' => 'liquidated', 'director_registry_number' => 'ДА92120334', 'director_name' => 'Тэмүүлэн Болд'],
        ],
    ],
];
```

**Interfaces:**
- **Produces:**
  - `User::companyMemberships(): HasMany` and `User::isVerified(): bool` (true when `verified_at` is not null).
  - `Company::members(): HasMany` and `Company::isFrozen(): bool`.
  - `CompanyMember::company()` and `CompanyMember::user()` (BelongsTo).
  - `RepresentationRequest::company()`, `user()` and `content()`.
  - `CompanyInvite::company()`.
  - `CompanyFactory` with an `active()` default state.

- [ ] **Step 1: Write the failing test** `test_a_company_lists_its_members_and_a_user_their_memberships`. Create a company with the factory and a member row. Assert `$company->members->sole()->user_id === $user->id`, that `$user->companyMemberships` has a count of 1, and that `$user->isVerified()` is false until `verified_at` is set.
- [ ] **Step 2: Run it.** `php artisan test --compact tests/Feature/Market/MarketModelsTest.php`. Expected: FAIL (no table or class).
- [ ] **Step 3: Implement the migration, models, factory, config and User changes listed above.**
- [ ] **Step 4: Run it.** Expected: PASS. Then `php artisan test --compact` (the full suite stays green) and pint.
- [ ] **Step 5: Commit** (if asked): `feat(market): account tables and models`.

### Task 2: DAN verification (fake driver)

**Files:**
- Create: `app/Support/Market/Dan/DanDriver.php` (interface) and `FakeDanDriver.php`
- Create: `app/Support/Market/Verification.php`
- Create: `app/Http/Controllers/Api/V1/Market/DanController.php`
- Create: `resources/views/market/dan-fake.blade.php`
- Modify: `app/Providers/AppServiceProvider.php` (binding), `routes/api.php`, `routes/web.php`, `lang/mn.json`
- Test: `tests/Feature/Market/DanVerificationTest.php`

**Interfaces:**
- **`DanDriver`:**
  - `authorizeUrl(string $state): string`
  - `citizen(string $code): array{registry_number: string, name: string}`. Throws `RuntimeException` when the code is bad.
- **`FakeDanDriver`:**
  - `authorizeUrl` returns `url('/auth/dan/fake').'?state='.$state`.
  - `citizen('fake-'.$registryNumber)` returns that entry from `config('market.fake.citizens')`.
- **Binding:** in `AppServiceProvider::register()`, bind `DanDriver` to `FakeDanDriver` when `config('market.dan.driver') === 'fake'`. Otherwise throw `RuntimeException('Only the fake DAN driver exists yet.')` when it is resolved.
- **`Verification`:**
  - `static start(User $user): string` returns the authorize URL and caches the state.
  - `static complete(string $state, string $code): User`. It aborts with 422 for:
    - an unknown or expired state: `__('The DAN sign-in expired. Try again.')`
    - a number already on another user: `__('This person is already verified on another account.')`
    - a user already verified with a different number: `__('This account is already verified as someone else.')`

    On success it sets `registry_number`, `verified_name` and `verified_at`. Verifying again with the same number is a no-op that still succeeds.
- **Routes:**
  - API (auth:sanctum): `POST /api/market/dan/start` returns `{url}`.
  - API (auth:sanctum): `GET /api/market/me` returns:
    ```
    {
      verified: {name, registry_number, verified_at} | null,
      memberships: [{company: {id, name, registry_number, status, frozen}, can_bid, can_sign, can_manage}],
      requests: [{id, company: {id, name}, method, status, reason, created_at}]
    }
    ```
    Task 3 fills in memberships and requests; return empty arrays until then.
  - Web: `GET /auth/dan/callback?state&code` calls `complete`, then redirects to `{frontend}/market/account?dan=ok`. On an abort it redirects to `?dan_error=<message>`.
  - Web, **fake only, and 404 when `app()->isProduction()`**: `GET /auth/dan/fake?state` renders one link per test citizen, pointing to `/auth/dan/callback?state=…&code=fake-<regnum>`.

- [ ] **Step 1: Write the failing tests:**
  - `test_a_user_verifies_through_the_fake_dan_and_is_marked_verified`: start, follow the URL, the fake page lists `УБ90010112`, then the callback. Assert a redirect to `/market/account?dan=ok` and that `registry_number` is `УБ90010112`.
  - `test_the_same_person_cannot_verify_a_second_account`: Review focus 1.
  - `test_a_reused_or_unknown_state_verifies_nobody`: Review focus 2. Run the callback twice with one state; the second redirect carries `dan_error`.
  - `test_the_fake_page_is_missing_in_production`.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement** the driver, service, controller, view, binding and routes.
- [ ] **Step 4: Run them.** Expected: PASS. Then run pint.
- [ ] **Step 5: Commit** (if asked): `feat(market): DAN verification with a fake driver`.

### Task 3: ХУР lookup and company registration

**Files:**
- Create: `app/Support/Market/Khur/KhurDriver.php` (interface) and `FakeKhurDriver.php`
- Create: `app/Support/Market/Companies.php`
- Create: `app/Support/Market/MarketAccess.php`
- Create: `app/Http/Controllers/Api/V1/Market/CompanyController.php`
- Modify: `AppServiceProvider` (binding as in Task 2, for `market.khur.driver`), `routes/api.php`, `DanController::me` (fill memberships and requests), `lang/mn.json`
- Test: `tests/Feature/Market/CompanyRegistrationTest.php`

**Interfaces:**
- **`KhurDriver::company(string $registryNumber): ?array`**. It returns `array{registry_number, name, status, director_registry_number, director_name}`, or null when ХУР doesn't know the company. The fake reads `config('market.fake.companies')`.
- **`MarketAccess::canActFor(?User $user, Company $company, string $right): bool`:**
  - `$right` is one of `browse | bid | sign | manage`.
  - It is false for a null or unverified user, a non-member or a frozen company.
  - `browse` is true for any member. Each other right reads its `can_*` column.
- **`Companies::register(User $user, string $registryNumber): array{company: Company, member: ?CompanyMember}`:**
  - Aborts with 403 `__('Verify yourself with DAN first.')` when the user isn't verified.
  - Aborts with 422 for a malformed number, for one ХУР doesn't know (`__('ХУР has no company with this registry number.')`) and for a liquidated company (`__('This company is liquidated.')`).
  - If the company already exists it is returned as it is, with no lookup or duplicate.
  - Otherwise it is created from ХУР with `checked_at = now()`.
  - When the user's registry number equals `director_registry_number`, the user becomes a member with all three rights, if not one already.
  - Returns the user's member row, or null.
- **Route:** `POST /api/market/companies {registry_number}` returns 201 with `{company, member}`.

- [ ] **Step 1: Write the failing tests:**
  - `test_the_director_registers_a_company_and_becomes_its_manager`
  - `test_an_unverified_user_cannot_register_a_company` (403)
  - `test_an_unknown_or_liquidated_company_is_refused`: Review focus 5, second half. Uses `2345678` and `9999999`.
  - `test_registering_a_company_already_on_the_platform_returns_it_without_membership`: Review focus 5. A second verified user who isn't the director gets the same id, member null, and `Company::count()` stays 1.
  - `test_me_lists_memberships`
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them.** Expected: PASS. Then run pint.
- [ ] **Step 5: Commit** (if asked): `feat(market): companies from ХУР`.

### Task 4: Representation through director approval

**Files:**
- Modify: `app/Support/Market/Companies.php`, `CompanyController.php`, `routes/api.php`, `lang/mn.json`
- Create: `app/Support/Market/MarketNotify.php`
- Test: `tests/Feature/Market/DirectorApprovalTest.php`

**Interfaces:**
- **`MarketNotify::send(User $to, string $title, ?string $body = null, ?string $link = null, string $type = 'info'): void`** inserts a `UserNotification`. The link is a frontend path such as `/market/account`.
- **`Companies::requestRepresentation(User $user, Company $company, string $method, ?UploadedFile $file = null): array{request: RepresentationRequest, approve_url: ?string}`:**
  - Aborts with 403 for an unverified user.
  - Aborts with 422 when the user is already a member, already has a pending request for this company, or the company is frozen.
  - For `director`: makes the token and returns `approve_url = {frontend}/market/approve/{token}`.
  - For `attorney`: see Task 5.
- **`Companies::approvalFor(string $token): RepresentationRequest`** aborts with 404 for an unknown, used or expired token.
- **`Companies::decideByDirector(User $director, string $token, bool $approve): RepresentationRequest`:**
  - Aborts with 403 `__('Only the director registered in ХУР can decide this.')` unless the director is verified and their `registry_number` equals the company's `director_registry_number`.
  - Approving creates the member with all three rights.
  - Either way it sets `status`, `decided_by` and `decided_at`, clears `token_hash`, and notifies the requester.
- **Routes:**
  - `POST /api/market/companies/{company}/representations` (multipart: `method`, optional `file`) returns 201 with `{request, approve_url}`.
  - `GET /api/market/approvals/{token}` returns `{company: {name, registry_number}, requester: {verified_name}, method, created_at}`.
  - `POST /api/market/approvals/{token} {decision: approve|reject}`.

- [ ] **Step 1: Write the failing tests:**
  - `test_the_director_approves_and_the_requester_becomes_a_manager`: the requester gets a notification.
  - `test_someone_who_is_not_the_director_cannot_decide`: Review focus 3. Expect 403 and the request still `pending`.
  - `test_an_approval_link_works_once_and_expires`: a second POST returns 404, and so does one made 15 days later (`$this->travel(15)->days()`).
  - `test_a_member_cannot_request_again`: 422.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them.** Expected: PASS. Then run pint.
- [ ] **Step 5: Commit** (if asked): `feat(market): representation by director approval`.

### Task 5: Power of attorney and the staff review queue

**Files:**
- Modify: `Companies.php` (the attorney branch and `decideByStaff`), `routes/api.php`, `lang/mn.json`
- Create: `app/Http/Controllers/Api/V1/Market/ReviewController.php`
- Test: `tests/Feature/Market/AttorneyReviewTest.php`

**Interfaces:**
- **`requestRepresentation` with method `attorney`:**
  - Requires `file`: `pdf`, `jpg`, `jpeg` or `png`, at most 10 MB. Otherwise 422.
  - Stores the file with `$file->store('market/attorney')` on the local (private) disk and creates a `Content` with `physical_path`, `file_name`, `file_extension`, `file_size`, `is_public = false` and `created_by`. There is no `content_map` row.
  - `approve_url` is null.
  - Notifies every admin (`User::all()->filter->isAdmin()`) with the link `/admin/market-reviews`.
- **`Companies::decideByStaff(User $admin, RepresentationRequest $request, bool $approve, ?string $reason): RepresentationRequest`:**
  - Rejecting requires a reason (422 without one).
  - Only `pending` attorney requests can be decided (422 otherwise).
  - Approving creates the member with all three rights.
  - Notifies the requester, and a rejection includes the reason.
- **Routes, all admin-only:**
  - `GET /api/market/reviews?status=pending|approved|rejected` (default `pending`). Returns a paginated list, 25 per page, newest first, of `{id, company: {id, name, registry_number}, user: {id, name, verified_name, registry_number}, method, status, reason, created_at, decided_at, file: {name, size} | null}`.
  - `GET /api/market/reviews/{request}/file` streams the stored document with `Storage::download`.
  - `POST /api/market/reviews/{request}/decide {decision: approve|reject, reason?}`.

- [ ] **Step 1: Write the failing tests** (use `Storage::fake('local')`):
  - `test_an_attorney_request_waits_in_the_queue_and_an_admin_approves_it`
  - `test_rejecting_needs_a_reason_and_tells_the_requester`
  - `test_non_admins_cannot_see_the_queue_or_the_file`: 403 on all three routes.
  - `test_the_attorney_file_must_be_a_pdf_or_image`
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them.** Expected: PASS. Then run pint.
- [ ] **Step 5: Commit** (if asked): `feat(market): power of attorney review queue`.

### Task 6: Members, rights and invites

**Files:**
- Modify: `Companies.php`, `CompanyController.php`, `routes/api.php`, `lang/mn.json`
- Test: `tests/Feature/Market/CompanyMembersTest.php`

**Interfaces** (all actions need `MarketAccess::canActFor($user, $company, 'manage')`, else 403):
- **`Companies::invite(User $by, Company $company, array{can_bid: bool, can_sign: bool, can_manage: bool} $rights): string`** returns `{frontend}/market/join/{token}`.
- **`Companies::acceptInvite(User $user, string $token): CompanyMember`:**
  - Aborts with 403 for an unverified user.
  - Aborts with 404 for an unknown, used or expired token.
  - Aborts with 422 when the user is already a member.
  - Marks the invite used.
- **`Companies::updateRights(User $by, CompanyMember $member, array $rights): CompanyMember`** and **`Companies::removeMember(User $by, CompanyMember $member): void`**. Both abort with 422 `__('A company needs at least one member who can manage it.')` when they would leave no `can_manage` member. A member may remove themselves.
- **Routes:**
  - `GET /api/market/companies/{company}/members` (members only; returns `[{id, user: {id, verified_name}, can_bid, can_sign, can_manage}]`)
  - `PATCH /api/market/companies/{company}/members/{member}`
  - `DELETE /api/market/companies/{company}/members/{member}`
  - `POST /api/market/companies/{company}/invites` returns `{url}`
  - `POST /api/market/invites/{token}/accept`

- [ ] **Step 1: Write the failing tests:**
  - `test_a_manager_invites_a_colleague_with_chosen_rights`: the colleague accepts with bid only, and `canActFor(colleague, 'sign')` is false.
  - `test_the_last_manager_cannot_leave_or_drop_manage`: Review focus 4.
  - `test_members_without_manage_cannot_change_others`: 403.
  - `test_an_invite_works_once`
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them.** Expected: PASS. Then run pint.
- [ ] **Step 5: Commit** (if asked): `feat(market): company members and invites`.

### Task 7: Daily ХУР recheck

**Files:**
- Modify: `Companies.php`, `routes/console.php`
- Test: `tests/Feature/Market/CompanyRecheckTest.php`

**Interfaces:**
- **`Companies::recheck(Company $company): bool`:**
  - Reads ХУР again and updates `name`, `status`, the director fields and `checked_at`.
  - When the status becomes `liquidated`, it sets `frozen_at` and notifies each member.
  - When the company is active again, it clears `frozen_at`.
  - Returns whether anything changed.
- **Command:** `market:recheck-companies` rechecks companies with `checked_at` older than 7 days, oldest first, at most 200 per run. It prints `{n} companies checked, {m} changed.` Scheduled `->daily()` in `routes/console.php`, next to `contents:prune`.

- [ ] **Step 1: Write the failing test** `test_a_company_liquidated_in_khur_is_frozen_and_members_lose_rights`. Change the fake config entry to `liquidated`, travel 8 days and run the command. Assert `frozen_at` is set, `canActFor(…, 'bid')` is false and the members got notifications.
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run it.** Expected: PASS. Then run the full suite: `php artisan test --compact`. Then run pint.
- [ ] **Step 5: Commit** (if asked): `feat(market): daily ХУР recheck`.

### Task 8: Frontend market account (verify, companies, approve, join)

**Files** (under `myapp-frontend/frontend/src`):
- Create: `lib/marketApi.ts`
- Create: `pages/market/MarketAccountPage.tsx`, `CompanyPage.tsx`, `ApprovePage.tsx`, `JoinPage.tsx`
- Modify: `App.tsx` (routes inside the shell layout route), `components/AppShell.tsx` (an `everyone` nav item "Market account", `/market/account`, icon `ShieldTick`), `i18n/mn.json`

**Interfaces:**
- **`marketApi`** wraps the Task 2–6 routes with `api()` from `lib/api.ts`:
  - `me()`
  - `startDan(): Promise<{url: string}>`
  - `registerCompany(registry_number)`
  - `requestRepresentation(companyId, method, file?)` (FormData)
  - `approval(token)` and `decideApproval(token, decision)`
  - `members(companyId)`, `updateMember(...)` and `removeMember(...)`
  - `invite(companyId, rights)` and `acceptInvite(token)`
  - Types mirror the JSON shapes in Tasks 2–6.
- **`/market/account`:**
  - The verification card shows "Verify with DAN", which calls `startDan` and then `window.location.href = url`. Once verified it shows the name and number.
  - It reads `?dan=ok` and `?dan_error=` into a success or error Alert, then clears them with `setSearchParams` and `replace`.
  - Below that: "My companies", plus a company registry-number input and a Register button.
  - If the response has `member: null`, it offers both ways: **Ask the director** (shows `approve_url` with a Copy button) or **Upload power of attorney** (file drop).
  - Pending requests and their status are listed.
- **`/market/companies/:id`:** the members table with rights checkboxes, which save at once. Remove and Invite open a Modal with three checkboxes and show a link with a Copy button.
- **`/market/approve/:token`:** shows who asks for which company, with Approve and Reject buttons. A 403 shows the server message. Someone not yet verified is first sent to verify.
- **`/market/join/:token`:** works the same way, with an Accept button.
- **Skeletons:** use the existing patterns (`animate-pulse` blocks, `PageHeader`, `Alert`, `Button size="sm"`).

- [ ] **Step 1: Implement** `marketApi.ts` and the four pages, routes and nav item.
- [ ] **Step 2: Run** `npx tsc --noEmit -p .` in `frontend`. Expected: exit 0. Then check `mn.json` parses with `node -e "JSON.parse(...)"`.
- [ ] **Step 3: Browser check** (Chrome, localhost:5173, `MARKET_*` drivers fake):
  1. Verify as `УБ90010112` and see the badge.
  2. Register `5123456` and become its manager.
  3. Use a second user verified as `ДА92120334`: register `6234567`, ask the director, open the link as the user verified as `УБ85051523`, approve, and see the notification.
  4. Invite a colleague and accept.

  There should be no console errors.

### Task 9: Admin review queue page

**Files:**
- Create: `myapp-frontend/frontend/src/pages/market/MarketReviewsPage.tsx`
- Modify: `App.tsx` (`/admin/market-reviews` under `AdminRoute` inside the shell layout route), `AppShell.tsx` (admin nav item "Market reviews", icon `ClipboardCheck`), `lib/marketApi.ts` (`reviews(status, page)`, `reviewFileUrl(id)`, `decideReview(id, decision, reason?)`), `i18n/mn.json`

**What it does:**
- Status tabs (Pending / Approved / Rejected) over a table of company, person (verified name and number), method, submitted date and file link.
- The file downloads through `api` as a blob, because the token is in the header, not a URL.
- Approve takes effect at once. Reject opens a Modal with a required reason.
- Uses the existing `Table` and `PaginationCardMinimal` (refresh between previous and next).

- [ ] **Step 1: Implement.**
- [ ] **Step 2: Run** `npx tsc --noEmit -p .`. Expected: exit 0.
- [ ] **Step 3: Browser check.** A power-of-attorney request from Task 8's user shows under Pending. Open its file, reject without a reason (blocked), reject with one, and see the requester's notification with the reason.
- [ ] **Step 4:** Update memory (`tender-marketplace-plan.md`: part 1 built, how to test with the fake drivers).
