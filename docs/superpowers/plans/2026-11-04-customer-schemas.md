# Customers as Schemas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One main schema for users and logins, one MySQL schema per customer for its metas and data, with sign-up → New / Join by invite code, a customer pick at sign-in, and the existing database adopted as the first customer.

**Architecture:** Two DB connections: `main` (fixed) and `customer` (default; its database is switched per request by `CustomerSchema::use()`). Main-schema models pin `$connection = 'main'`. The token carries `customer_id`; a middleware switches the schema, the cache prefix and the file root. Jobs and scheduled commands carry or loop over customers. New schemas are built from a committed core-structure SQL file, then kept current by running customer migrations across every schema.

**Tech Stack:** Laravel 12 / PHP 8.3, Sanctum tokens, MariaDB 10.4 locally and MySQL 8.4 on Aiven, PHPUnit on SQLite `:memory:`; React + TypeScript frontend (`myapp-frontend/frontend`).

**Spec:** `docs/superpowers/specs/2026-11-04-customer-schemas-design.md` (approved 2026-11-04).

## Global Constraints

- Works on MariaDB 10.4 and MySQL 8.4; quote reserved words in raw SQL; no MariaDB-only syntax.
- No new Composer or npm dependencies.
- Main-schema tables: `users`, `social_accounts`, `password_reset_tokens`, `personal_access_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`, `customers`, `customer_users`, `customer_invites`, `customer_domains`.
- App/demo tables never go into new schemas: prefixes `cal_ climb_ cm_ crm_ eam_ lms_ pay_ pm_ pos_ proc_ school_ tender_ wand_ web_contact_ wh_ xl_ market_ company_ contract_`, and tables `tenders companies contracts payments escrow_entries ratings representation_requests`.
- Built-in lookup rows copied into every new schema: `meta_types`, `languages` (the default one only), `widgets`. No metas.
- Invite codes: 8 characters from `ABCDEFGHJKMNPQRSTUVWXYZ23456789`; input is upper-cased and spaces/dashes removed before lookup.
- Customer creation limit: 3 per hour per user and 10 per hour per IP (`throttle:customers`).
- Existing tests keep passing on SQLite, where `main` and `customer` share one in-memory database. Tests that need two real schemas run only on a `*_customertest` MySQL/MariaDB database and are skipped elsewhere (same pattern as `PatchSchemaTest`).
- Run `vendor/bin/pint --dirty --format agent` before each commit; commit with the attribution trailer.

## Review Focus

1. A token whose customer membership was removed → 403 `{"message":"You are no longer in this customer.","customers":[…]}`, never that customer's data. (Task 4 test `test_a_removed_member_is_refused`.)
2. An invite code typed in lowercase or with spaces/dashes → still found. (Task 7 test `test_a_code_is_found_however_it_is_typed`.)
3. Joining a customer you're already in → no duplicate row, no extra use counted, opens it. (Task 7 test `test_joining_twice_keeps_one_membership`.)
4. Customer creation failing half-way → no schema, no `customers`/`customer_users` row left. (Task 5 test `test_a_failed_create_leaves_nothing`.)
5. The last admin leaving a customer → 422, membership kept. (Task 8 test `test_the_last_admin_cannot_leave`.)

---

### Task 1: Two connections and the schema switch

**Files:**
- Modify: `config/database.php` (add `main` and `customer` connections; default `customer`)
- Create: `config/customers.php`
- Create: `app/Support/CustomerSchema.php`
- Create: `app/Models/Concerns/OnMainSchema.php`
- Modify: `app/Models/User.php`, `app/Models/SocialAccount.php` (use `OnMainSchema`)
- Create: `app/Models/PersonalAccessToken.php` (extends Sanctum's, `OnMainSchema`); register with `Sanctum::usePersonalAccessTokenModel()` in `AppServiceProvider::boot`
- Modify: `tests/TestCase.php`
- Test: `tests/Feature/CustomerSchemaTest.php`

**Interfaces:**
- Produces:
  - `config('customers')`: `schema_prefix` (`'cust_'`), `main_tables` (list above), `app_prefixes`, `app_tables`, `lookup_tables` (`['meta_types','languages','widgets']`), `first_code` (env `CUSTOMER_FIRST_CODE`, default `'main'`).
  - `CustomerSchema::use(Customer $customer): void` — sets `database.connections.customer.database` to `$customer->schema_name`, `DB::purge('customer')`, sets `cache.prefix` to `{base}c{id}_` and `Cache::forgetDriver()`, sets the `local` disk root (Task 6), forgets per-request caches (`Access`, `SessionValues`, `Translations`, `MetaTree` static caches).
  - `CustomerSchema::current(): ?Customer`, `CustomerSchema::run(Customer $c, Closure $fn): mixed` (switch, run, switch back), `CustomerSchema::forget(): void`.
  - Trait `OnMainSchema` sets `protected $connection = 'main'`.
- On SQLite, `use()` keeps the database unchanged (both connections are the same in-memory DB).

- [ ] **Step 1: Write failing tests**
  - `test_main_models_use_the_main_connection`: `(new User)->getConnectionName() === 'main'`, same for `SocialAccount`, `PersonalAccessToken`; `(new Meta)->getConnectionName()` is `null` (default) and `config('database.default') === 'customer'`.
  - `test_run_switches_back_afterwards`: with customer A current, `CustomerSchema::run($b, fn () => CustomerSchema::current()->id)` returns B's id and `current()` is A afterwards, also when the closure throws.
  - `test_the_cache_is_kept_apart_per_customer`: `Cache::put('k', 'a')` under A, `Cache::get('k')` under B is null, back under A is `'a'` (use the `array` store with prefix; assert via `Cache::getPrefix()` differs).
- [ ] **Step 2:** Run `php artisan test --compact tests/Feature/CustomerSchemaTest.php` — FAIL (classes missing).
- [ ] **Step 3: Implement.** In `config/database.php` copy the existing mysql/mariadb/sqlite block into `main` (env `DB_MAIN_DATABASE`, falling back to `DB_DATABASE`) and `customer` (env `DB_DATABASE`); `default` → `env('DB_CONNECTION_DEFAULT', 'customer')`, driver from `DB_CONNECTION`. In `TestCase::setUp`, after the parent: `DB::connection('main')->setPdo(DB::connection('customer')->getPdo())` so both share the in-memory DB, then create a `Customer` (Task 2 model; until then guard with `class_exists`) and `CustomerSchema::use()` it. `RefreshDatabase` must migrate both migration paths: override `migrateFreshUsing()` to add `'--path' => ['database/migrations', 'database/migrations/main']`.
- [ ] **Step 4:** Run the new test and the full suite `php artisan test --compact` — PASS (no existing test broken).
- [ ] **Step 5:** Commit `feat: main and customer connections with a schema switch`.

### Task 2: Main-schema tables and models

**Files:**
- Create: `database/migrations/main/2026_11_05_000000_create_main_tables.php` (creates each `main_tables` entry from the 0001 migrations only `if (! Schema::connection('main')->hasTable(...))`; then `customers`, `customer_users`, `customer_invites`, `customer_domains`; adds `personal_access_tokens.customer_id` nullable, `users.is_platform_owner` bool default false)
- Create: `app/Models/Customer.php`, `CustomerUser.php`, `CustomerInvite.php`, `CustomerDomain.php` (all `OnMainSchema`)
- Modify: `app/Models/User.php` (`customers()`; `roles()` stays on the customer connection)
- Test: `tests/Feature/CustomerModelsTest.php`

**Interfaces:**
- `customers`: id, code (unique, 40), name, schema_name (unique, 64), files_folder (nullable; null = the existing root, used by the first customer), created_by, timestamps.
- `customer_users`: id, customer_id, user_id, invite_id nullable, theme_meta_id nullable (moves here from `users.theme_meta_id`, because a theme is a meta of one customer), joined_at; unique (customer_id, user_id).
- `customer_invites`: id, customer_id, code (unique, 8), role_ids json, created_by, expires_at, max_uses, uses (default 0), revoked_at, timestamps.
- `customer_domains`: id, domain (unique), customer_id.
- `User::customers(): BelongsToMany` (via `customer_users`, ordered by name); `User::isIn(Customer $c): bool`.
- `Customer::makeCode(string $name): string` — slug of the name, `-2`, `-3`… until unique.
- `CustomerInvite::isUsable(): bool` — not revoked, not expired, `max_uses` null or `uses < max_uses`.

- [ ] **Step 1: Failing tests:** `test_a_user_lists_their_customers` (two customers, user linked to one → `customers()->pluck('id')` is that one); `test_codes_are_made_unique` (`makeCode('Acme LLC')` → `acme-llc`, again after creating one → `acme-llc-2`); `test_an_invite_stops_being_usable` (revoked, expired yesterday, `max_uses=1, uses=1` → false; fresh → true); `test_roles_still_read_from_the_customer_schema` (`$user->roles()->getRelated()->getConnectionName()` is not `'main'` and attach/detach works).
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement migration + models. Move every read/write of `users.theme_meta_id` (`grep -rn theme_meta_id app`; includes `MetaUsage.php:96` and the theme pick endpoint) to the current `CustomerUser` row.
- [ ] **Step 4:** Run the test + full suite — PASS.
- [ ] **Step 5:** Commit `feat: customers, members, invites tables`.

### Task 3: Core structure, drop user FKs, migrate every schema

**Files:**
- Create: `database/migrations/2026_11_05_000001_drop_foreign_keys_to_users.php` (MySQL/MariaDB only: loop `information_schema.KEY_COLUMN_USAGE` where `REFERENCED_TABLE_NAME = 'users'` in the current schema and drop each; no-op on SQLite; `down()` does nothing)
- Create: `app/Support/CoreStructure.php`
- Create: `database/schema/customer-core.sql` (generated, committed)
- Modify: `routes/console.php` (commands `customers:dump-core`, `customers:migrate`)
- Test: `tests/Feature/CoreStructureTest.php`

**Interfaces:**
- `CoreStructure::isCoreTable(string $table): bool` — false for `main_tables`, `app_tables`, any `app_prefixes`.
- `CoreStructure::dump(): string` — from the current customer schema: `SHOW CREATE TABLE` for each core table (AUTO_INCREMENT values removed, DEFINER removed from triggers), the triggers on core tables, then `INSERT`s for `lookup_tables` (languages: only `is_default = 1`), then `INSERT INTO migrations` for every migration file in `database/migrations` (top level only) with batch 1. Generated columns are left out of the INSERTs.
- `customers:dump-core` writes it to `database/schema/customer-core.sql`.
- `customers:migrate {--customer=}` — for each customer (or one), `CustomerSchema::run` + `migrate --database=customer --path=database/migrations --force`; prints one line per customer `code: ok` / `code: FAILED <message>`, continues after failures, exit code 1 if any failed. Main migrations run with `migrate --database=main --path=database/migrations/main`.

- [ ] **Step 1: Failing tests:** `test_core_tables_leave_out_app_and_main_tables` (`isCoreTable('metas')` true, `'eam_assets'`, `'market_bids'`, `'users'`, `'tenders'`, `'xl_asset_adjustment'` false); `test_the_dump_has_no_app_tables` (MySQL only, skipped on SQLite: dump contains `CREATE TABLE \`metas\``, no `eam_`, no `AUTO_INCREMENT=`, no `DEFINER`, contains `INSERT INTO \`meta_types\``).
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement; run `php artisan customers:dump-core` against local MariaDB and commit the SQL file.
- [ ] **Step 4:** Run tests + full suite — PASS.
- [ ] **Step 5:** Commit `feat: core structure for new customer schemas`.

### Task 4: Request middleware and the token's customer

**Files:**
- Create: `app/Http/Middleware/UseCustomer.php` (alias `customer`)
- Modify: `bootstrap/app.php` (alias), `routes/api.php` (every `auth:sanctum` route group gets `customer`, except: `/user` (works without), `/logout`, `/me/customers*`, `/customers`, `/customers/join`, `/customers/{customer}/use`, `/platform/*`)
- Test: `tests/Feature/UseCustomerTest.php`

**Interfaces:**
- `UseCustomer::handle`: token = `$request->user()->currentAccessToken()`. If it's a `PersonalAccessToken`: no `customer_id` → 409 `{"message":"Pick a customer.","customers":[…]}`; `customer_id` set but user not in `customer_users` → 403 `{"message":"You are no longer in this customer.","customers":[…]}`; else `CustomerSchema::use($customer)`. If it's a `TransientToken` (only `Sanctum::actingAs` in tests): keep `CustomerSchema::current()` when `app()->runningUnitTests()`, otherwise 409.
- `customers` list shape everywhere: `[{id, code, name, is_admin}]`.
- `/user` response adds `customer` (current or null) and `customers`.

- [ ] **Step 1: Failing tests** (use real tokens: `$user->createToken('t')` then set `customer_id`): `test_a_token_without_a_customer_must_pick` (GET `/api/metas` → 409 and `customers` lists the user's one customer); `test_a_removed_member_is_refused` (delete the membership → 403 with message `You are no longer in this customer.`); `test_user_shows_the_current_customer`.
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement middleware and route grouping. Add the two messages to `lang/mn.json`.
- [ ] **Step 4:** Run + full suite — PASS.
- [ ] **Step 5:** Commit `feat: requests run inside the token's customer`.

### Task 5: Creating a customer

**Files:**
- Create: `app/Support/Customers.php`
- Create: `app/Http/Controllers/Api/V1/CustomerController.php`
- Modify: `app/Providers/AppServiceProvider.php` (limiter `customers`), `routes/api.php`
- Test: `tests/Feature/CustomerCreateTest.php` (MySQL-only parts skipped on SQLite)

**Interfaces:**
- `Customers::create(User $owner, string $name): Customer` — `CREATE DATABASE` `{schema_prefix}{random 10 lowercase}` with the server's default charset/collation; runs `customer-core.sql` in it; creates role `admin` (`Role::ADMIN`) and `user_roles` for the owner; creates the `users` view (Task 9 `Customers::createUsersView(Customer)`); inserts `customers` + `customer_users`. Any failure → `DROP DATABASE IF EXISTS` and delete the rows, then rethrow. On SQLite (tests) the schema step is skipped and the rows and role are still made.
- `POST /customers {name}` (auth, no customer needed, `throttle:customers`) → 201 `{customer, token}`: a new token bound to the new customer (the old one is deleted).
- `Customers::bindToken(User $u, Customer $c): string` — new plain-text token with `customer_id`; used by create, join, use and login.

- [ ] **Step 1: Failing tests:** `test_a_new_customer_makes_its_owner_admin` (POST → 201; `customer_users` row; within that customer `$user->roleIds()` contains the admin role id; returned token works on `/api/user` and shows the customer); `test_creating_is_rate_limited` (4th POST within the hour → 429); `test_a_failed_create_leaves_nothing` (MySQL only: make the SQL file path point at a broken file via config → exception, no new `cust_` database in `SHOW DATABASES`, no rows); `test_a_new_schema_has_core_tables_and_no_metas` (MySQL only: `metas` count 0, `meta_types` count > 0, no `eam_assets` table).
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement. Limiter: `[Limit::perHour(3)->by('customers:'.$user->id), Limit::perHour(10)->by('customers-ip:'.$ip)]`.
- [ ] **Step 4:** Run + full suite — PASS.
- [ ] **Step 5:** Commit `feat: create a customer with its own schema`.

### Task 6: Jobs, scheduler and files per customer

**Files:**
- Create: `app/Jobs/Middleware/InCustomer.php`
- Modify: `app/Jobs/SendNotificationEmail.php` (store `public ?int $customerId = CustomerSchema::current()?->id` in the constructor; `middleware()` returns `[new InCustomer]`)
- Modify: `app/Support/CustomerSchema.php` (`use()` sets `filesystems.disks.local.root` to `storage_path('app/private')` + (`files_folder` ?? `customers/{id}` unless it's the first customer), `Storage::forgetDisk('local')`)
- Modify: `routes/console.php` (`customers:each {name} {--only=}` runs an artisan command once per customer inside `CustomerSchema::run`, continuing after failures; schedule `customers:each forms:run-scheduled` every minute and `customers:each contents:prune` daily; the three `market:*` schedules become `customers:each market:… --only={first_code}`)
- Test: `tests/Feature/CustomerJobsTest.php`

- [ ] **Step 1: Failing tests:** `test_a_queued_email_runs_in_its_customer` (dispatch under A with the `database` queue faked by `Queue::fake()`; assert pushed job's `customerId` is A; then run `(new InCustomer)->handle($job, fn () => CustomerSchema::current()->id)` while B is current → returns A's id and B is current afterwards); `test_files_go_to_the_customer_folder` (`Storage::disk('local')->path('x')` contains `customers/{B id}` under B and doesn't under the first customer); `test_each_runs_a_command_for_every_customer` (two customers; a test command that records `CustomerSchema::current()->id` → both ids recorded; `--only` limits to one).
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run + full suite — PASS.
- [ ] **Step 5:** Commit `feat: jobs, schedules and files stay in their customer`.

### Task 7: Joining with an invite code, and invites for admins

**Files:**
- Create: `app/Http/Controllers/Api/V1/CustomerInviteController.php`
- Modify: `app/Http/Controllers/Api/V1/CustomerController.php` (`join`), `app/Support/Customers.php`, `routes/api.php`
- Test: `tests/Feature/CustomerInviteTest.php`

**Interfaces:**
- `Customers::normalizeCode(string $typed): string` (upper-case, strip spaces and `-`), `Customers::newCode(): string` (8 chars from the alphabet, retried until unique).
- `Customers::join(User $u, string $code): Customer` — 422 `This code is not valid.` when missing/unusable; already a member → returns the customer, no use counted; else `customer_users` row (with `invite_id`), `user_roles` for each `role_ids` that still exists in that schema (inside `CustomerSchema::run`), `uses + 1`. All in one transaction per connection, use counted with a locked row.
- `POST /customers/join {code}` → `{customer, token}` (bound token).
- Admin of the current customer (`Access` admin check): `GET /customer/invites` → `[{id, code, roles:[{id,name}], expires_at, max_uses, uses, revoked_at, created_by_name}]`; `POST /customer/invites {role_ids[], expires_at?, max_uses?}` (role ids must exist in the current schema) → 201 invite; `POST /customer/invites/{invite}/revoke`. Invites of other customers → 404.

- [ ] **Step 1: Failing tests:** `test_a_code_gives_its_roles` (admin makes code with role R; another user joins → member, has R, `uses` = 1); `test_a_code_is_found_however_it_is_typed` (code `ABCD2345` joined as `abcd-2345 ` works); `test_joining_twice_keeps_one_membership` (second join → 200, one row, `uses` still 1); `test_a_revoked_or_used_up_code_is_refused` (422 with message); `test_non_admins_cannot_manage_invites` (403); `test_invites_of_another_customer_are_hidden` (revoke B's invite while in A → 404).
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement; add messages to `lang/mn.json`.
- [ ] **Step 4:** Run + full suite — PASS.
- [ ] **Step 5:** Commit `feat: invite codes with roles`.

### Task 8: Sign-up, sign-in, pick, switch, leave, members, platform list

**Files:**
- Modify: `app/Models/User.php` (`startAccount` no longer makes the first account admin)
- Modify: `app/Http/Controllers/Api/V1/AuthController.php`, `GoogleAuthController.php`, `CustomerController.php`, `routes/api.php`
- Create: `app/Http/Controllers/Api/V1/PlatformController.php`
- Test: `tests/Feature/CustomerSignInTest.php`

**Interfaces:**
- `/register` and Google sign-up → `{user, token, customers: []}`, token without customer.
- `/login` and Google login → `{user, token, customers}`; exactly one customer → token already bound to it.
- `POST /customers/{customer}/use` → `{customer, token}` (member only, else 403); old token deleted.
- `GET /me/customers` → customers list. `DELETE /me/customers/{customer}` → leave; 422 `You are the last admin of this customer.` when the user is that customer's only admin.
- `DELETE /customer/members/{user}` (admin of current customer) → removes `customer_users` row and that user's `user_roles` in the schema, revokes that user's tokens bound to this customer; same last-admin rule.
- `GET /platform/customers` (`is_platform_owner` only, else 403) → `[{id, code, name, members, created_at}]`.

- [ ] **Step 1: Failing tests:** `test_signing_up_gives_no_customer_and_no_admin` (first ever user registers → `customers` empty, no admin role anywhere); `test_one_customer_is_picked_automatically` (login → token works on `/api/metas` without a 409); `test_several_customers_need_a_pick` (login → `/api/metas` 409; `use` → 200, then works); `test_use_refuses_a_customer_you_are_not_in` (403); `test_the_last_admin_cannot_leave` (422, still a member); `test_an_admin_removes_a_member` (removed member's bound token → 403 on `/api/metas`); `test_only_platform_owners_list_customers`.
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement. Update tests that relied on the first-account-admin rule (`grep -rn "first" tests/Feature/AuthTest.php` and similar) to create the admin explicitly.
- [ ] **Step 4:** Run + full suite — PASS.
- [ ] **Step 5:** Commit `feat: sign-up, sign-in and switching between customers`.

### Task 9: `users` inside customer SQL

**Files:**
- Modify: `app/Support/Customers.php` (`createUsersView(Customer $c): void`)
- Modify: `app/Http/Controllers/Api/V1/LanguageController.php:77` (update `users` through the `User` model on main, limited to the current customer's members), `app/Http/Controllers/Api/V1/NotificationRuleController.php:63` and any other `DB::table('users')`/`join('users'…)` found by `grep -rn "table('users')\|join('users\|JOIN users\|FROM users" app` (reads stay, they now hit the view; writes go to the model)
- Modify: `app/Support/Builders.php`/`config/builders.php` only if the view needs listing (it is already a system table)
- Test: `tests/Feature/CustomerUsersViewTest.php` (MySQL only)

**Interfaces:**
- View `users` in each customer schema: `SELECT u.id, u.name, u.email, u.locale, u.ui_size, u.created_at, u.updated_at FROM \`{main}\`.users u JOIN \`{main}\`.customer_users cu ON cu.user_id = u.id AND cu.customer_id = {id}` (add any other non-secret column the existing SQL reads; never `password`, `remember_token`, tokens). Recreated by `customers:migrate` after migrating each schema (`CREATE OR REPLACE VIEW`).

- [ ] **Step 1: Failing tests (MySQL only):** `test_the_view_shows_only_members` (A has users 1, 2; B has 3 → in A `DB::table('users')->pluck('id')` is [1, 2]); `test_the_view_has_no_password` (`Schema::getColumnListing('users')` under A has no `password`).
- [ ] **Step 2:** Run on the `*_customertest` DB — FAIL.
- [ ] **Step 3:** Implement and convert the writes.
- [ ] **Step 4:** Run + full suite — PASS.
- [ ] **Step 5:** Commit `feat: customer SQL sees only its own members`.

### Task 10: Public pages find their customer

**Files:**
- Create: `app/Http/Middleware/UseCustomerFromAddress.php` (alias `customer.public`)
- Modify: `routes/api.php` (public metas, portal and site routes get an optional `{customer}` code prefix `c/{customer}/…` plus `customer.public`; the old unprefixed routes stay and use the first customer)
- Modify: frontend public routes `/c/:customer/portal/:id` and `/c/:customer/site/:code` in `src/App.tsx`, and the API base used by `PortalPage.tsx` / `sites/*`
- Test: `tests/Feature/PublicCustomerTest.php`

**Interfaces:**
- `UseCustomerFromAddress`: request host found in `customer_domains` → that customer; else route `{customer}` code → that customer (404 if unknown); else the customer with code `config('customers.first_code')`. Signed-in users on public routes still use this, not their token's customer.
- Admins of a customer add their own domain: `site_domains` writes also upsert `customer_domains` (domain unique across customers → 422 `This domain is used by another customer.`).

- [ ] **Step 1: Failing tests:** `test_a_public_meta_opens_under_its_customer_code` (public meta in B reachable at `/api/c/{B code}/…`, 404 at `/api/c/{A code}/…`); `test_old_public_links_use_the_first_customer`; `test_a_domain_belongs_to_one_customer` (second customer claiming it → 422).
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run + full suite, `npm run build` in the frontend — PASS.
- [ ] **Step 5:** Commit `feat: public pages carry their customer`.

### Task 11: Adopt the existing database as the first customer

**Files:**
- Modify: `routes/console.php` (`customers:adopt {--name=} {--dry-run}`)
- Create: `app/Support/CustomerAdopt.php`
- Modify: `docker/start.sh` (main migrations, then `customers:migrate`), `.env.example` (`DB_MAIN_DATABASE`, `CUSTOMER_FIRST_CODE`)
- Test: `tests/Feature/CustomerAdoptTest.php` (MySQL only, on a scratch copy)

**Interfaces:**
- `CustomerAdopt::plan(): array{tables: array<string,int>, users: int, tokens: int}` (what would move, row counts); `CustomerAdopt::run(string $name): Customer`:
  1. refuse if `main.customers` has any row (`Already adopted.`);
  2. create the main tables (main migrations) in `DB_MAIN_DATABASE`;
  3. copy every `main_tables` table's rows from the current schema with the same ids (generated columns skipped);
  4. insert the first customer (`code` = `first_code`, `schema_name` = current `DB_DATABASE`, `files_folder` = null), `customer_users` for every user, copy `users.theme_meta_id` into it, set `customer_id` on every token;
  5. only after all copies succeed: drop the moved tables from the customer schema and create the `users` view.
- `--dry-run` prints the plan and changes nothing.

- [ ] **Step 1: Failing tests (MySQL only):** `test_adopting_moves_users_and_keeps_ids` (scratch schema with 2 users and a token → main has the same ids, token has `customer_id`, customer schema has a `users` view instead of the table); `test_adopting_twice_is_refused`; `test_a_dry_run_changes_nothing`.
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement and update `start.sh`.
- [ ] **Step 4:** Run tests. Then locally, on a copy of `laravel` (as in the load-test routine): `customers:adopt --dry-run`, then `customers:adopt --name="<company name>"`, log in through the browser, open a grid and a form. Do **not** run on the real local DB or Aiven in this task.
- [ ] **Step 5:** Commit `feat: adopt the existing database as the first customer`.

### Task 12: Frontend — onboarding, picker, switcher, invites, members, platform

**Files:**
- Modify: `src/auth/AuthContext.tsx` (state `customer`, `customers`; `createCustomer(name)`, `joinCustomer(code)`, `useCustomer(id)`, `leaveCustomer(id)`; each stores the returned token and clears the api read cache the same way login does)
- Create: `src/pages/StartPage.tsx` (route `/start`: two cards, *New customer* (name) and *Join customer* (code)), `src/pages/PickCustomerPage.tsx` (`/pick`)
- Modify: `src/App.tsx` (signed in with no customer → `/start`; several and none picked (409) → `/pick`), `src/lib/api.ts` (on 409 `Pick a customer.` go to `/pick`; on 403 `You are no longer in this customer.` refresh customers and go to `/pick` or `/start`)
- Modify: profile menu (switcher listing customers with a check on the current one), `src/pages/ProfilePage.tsx` (Customers section: list, switch, New customer, Join with code, Leave)
- Create: `src/pages/InvitesPage.tsx` (`/admin/invites`, admin nav item "Invites": create with roles, expiry, max uses; list with copy and revoke)
- Modify: `src/pages/RoleWorkspacePage.tsx` People tab (Remove from customer)
- Create: `src/pages/PlatformCustomersPage.tsx` (`/platform/customers`, shown only when `user.is_platform_owner`)
- Modify: `src/i18n/mn.json` (all new strings via `t()`)

- [ ] **Step 1:** Implement. Follow the existing page and modal components (reuse what `PatchesPage.tsx` and `RoleWorkspacePage.tsx` use).
- [ ] **Step 2:** `npm run build` — no type errors.
- [ ] **Step 3: Browser check** on the adopted local copy from Task 11: sign up a new user → `/start` → New customer → empty app with admin menus; make a role and an invite; sign up a second user → Join with the code typed in lowercase → sees only what the role allows; switch between two customers from the profile menu and check that grids and the messenger show only that customer's data; leave a customer; last admin leaving is refused.
- [ ] **Step 4:** Commit `feat: customer onboarding, picker, switcher and invites`.

### Task 13: Whole-branch check

- [ ] Run `php artisan test --compact` (SQLite) and the MySQL-only tests against a `*_customertest` database on local MariaDB; record results.
- [ ] Run `vendor/bin/pint --dirty --format agent`, `npm run build`.
- [ ] Update `.env.example` and write down the production steps (dump Aiven → check `avnadmin` can `CREATE DATABASE` and create views → `customers:adopt --dry-run` → `customers:adopt`) in the commit message; production is **not** run as part of this plan.
- [ ] Commit any fixes.
