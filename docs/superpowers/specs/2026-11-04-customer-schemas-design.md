# Customers as schemas — design

Date: 2026-11-04. Status: draft for review.

## Why

The platform is going to serve more than one organisation. The user's own company (about 3,500
employees, ~1,000 active users) is one customer; outside organisations are others. Each customer
builds its own metas and keeps its own data, apart from every other customer. Later, customers
will buy and sell metas in a public marketplace and pay by plan; this design is the base for that.

## Decided by the user

- **Main schema** holds users and their logins. **Each customer has its own schema** holding its
  metas and data.
- A table links users to customers. One person can belong to **several** customers.
- **Sign-up:** anyone signs up (email + password, or Google), then chooses:
  - **New customer** → a new, empty schema is created (no metas, no data), rows go into
    `customers` and `customer_users`, and the person becomes that customer's admin.
  - **Join customer** → asks for an invite code; the person joins with the roles the code gives.
- **Invite codes** are made by a customer's admin, carry one or more of that customer's roles,
  and are what lets anyone into an existing customer. There is no other way to join.
- **Profile** offers *New customer* and *Join with code* at any time.
- **Sign-in:** one address for everyone. After login the person picks one of their customers; with
  only one, it is chosen automatically.
- Anyone can create a customer instantly. Pricing (later) will restrict this.
- The tender market is out of scope for now.

## Goals

1. Main schema + one schema per customer, with every request, queued job, scheduled job, cache
   entry and stored file working inside exactly one customer.
2. Sign-up, new customer, join by code, sign-in with customer pick, switching customers.
3. Invite codes managed by a customer's admin.
4. The existing database becomes the first customer (the user's company) without losing anything.
5. Works on local MariaDB 10.4 and on Aiven MySQL 8.4.

## Non-goals (later projects)

- Pricing, plans, limits per plan.
- A web address per customer (subdomains). Everyone uses one address.
- Builder role changes, sandbox schema per customer, meta marketplace.
- The tender market across customers.
- Moving a customer's schema to another database server.
- Deleting a customer from the UI.

## Anti-goals (a failure even if it works)

- Any row, file, cache entry, notification or chat of one customer reachable from another.
- A customer's admin seeing or changing anything of another customer, or the main schema.
- Password hashes or tokens readable from a customer schema.
- The existing company data damaged or partly moved.

## What exists today (checked 2026-11-04)

- One database (`laravel` locally) with 199 tables and 93 migrations. `migrate` on an empty
  database fails at `2026_10_17_034136` (`after` inside `create table`), so a fresh schema cannot
  be built by running the migrations.
- 61 foreign keys reference `users`, across 32 tables (`created_by`, `user_id`, …).
- Cache, sessions and the queue use database tables (`CACHE_STORE`, `SESSION_DRIVER`,
  `QUEUE_CONNECTION` = database). Files use the local disk.
- Sign-up goes through `User::startAccount()` (email in `AuthController::register`, Google in
  `GoogleAuthController`). The first account becomes admin.
- Auth is Sanctum tokens. Roles: `roles` + `user_roles`; permissions in `meta_permissions` and
  `wfm_status_permissions`; access is cached per user.
- Many features use the `users` table directly in SQL (user names in grids, lookups, session
  values, notifications' recipients).
- Public things work without a login: public metas / portal (`/portal/:id`), websites
  (`/site/:code`, own domains in `site_domains`).

## Where tables live

**Main schema** (new database, e.g. `myapp_main`):

- `users` (with the person-wide settings already on it: locale, ui_size, preferences),
  `social_accounts`, `password_reset_tokens`, `personal_access_tokens`, `sessions`
- `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, its own `migrations`
- New: `customers`, `customer_users`, `customer_invites` (below)
- New: a domain → customer lookup for websites with their own domain (see Public pages)

**Each customer schema** — the core tables, everything that belongs to one organisation:

- metas and all `meta_*` tables, `meta_types`, `roles`, `user_roles`, `role_log`, `wfm_*`,
  `tags`, `widgets`, `autonumbers`, `record_log`, `translations`, `languages`, `contents`,
  `content_map`, `schedules`, `patches`, `patch_metas`, `patch_installs`, `session_values`,
  `user_notifications`, `chats`, `chat_members`, `chat_messages`, `site_releases`, `site_visits`
  and the per-customer part of `site_domains`
- plus any table the customer's metas create (Excel → structure, patches)

**App and demo tables** (`cal_`, `climb_`, `cm_`, `crm_`, `eam_`, `lms_`, `pay_`, `pm_`, `pos_`,
`proc_`, `school_`, `tender_`, `tenders`, `wand_`, `web_contact_`, `wh_`, `xl_`, and the market
tables `market_`, `companies`, `company_*`, `contracts`, `contract_*`, `payments`,
`escrow_entries`, `ratings`, `representation_requests`) stay in the first customer's schema and are
**not** created in new customer schemas.

## New tables (main schema)

- `customers`: id, code (unique, used in public URLs), name, schema_name (unique), created_by,
  timestamps.
- `customer_users`: customer_id, user_id, unique (customer_id, user_id), joined_at, invite_id
  (nullable). Roles are **not** here; they stay in the customer's own `user_roles`.
- `customer_invites`: id, customer_id, code (unique), role_ids (json, ids of roles in that
  customer's schema), created_by, expires_at (nullable), max_uses (nullable), uses, revoked_at
  (nullable), timestamps.
- `personal_access_tokens` gains `customer_id` (nullable): the customer the token is working in.
- `users` gains `is_platform_owner` (bool).

## How a request finds its customer

- The app has two connections: **main** (fixed) and **customer** (its database is set per
  request). Models of main-schema tables (User, Customer, CustomerUser, CustomerInvite, the token,
  SocialAccount) always use **main**; everything else uses **customer**.
- Middleware reads the token's `customer_id`, checks the user is still in `customer_users`, and
  points the customer connection at that schema. No customer on the token → only the main-schema
  routes work (profile, customer list, new customer, join, pick).
- Everything keyed by user that is cached (access, roles, session values, read caches) is keyed
  by customer + user. The frontend's read cache is cleared when the customer changes.
- **Queued jobs** carry the customer id and switch to its schema before running.
- **Scheduled commands** (`forms:run-scheduled`, file pruning, any other daily job) run once per
  customer, each inside its schema; one customer's failure doesn't stop the others.
- **Files** are stored under a per-customer folder.
- **SQL that uses `users`** (grids, lookups, notifications, session values, admins' query
  strings) keeps working and only ever sees that customer's members and no password hashes or
  tokens. How is left to the builder (my suggestion: a `users` view in each customer schema over
  the main `users` joined to `customer_users`, safe columns only).
- The 61 foreign keys to `users` are dropped; the user id columns stay.

## Flows

**Sign-up** (email or Google): creates the user only, no customer. The first-account-becomes-admin
rule is removed. The person then lands on a page with two choices: New customer / Join customer.

**New customer:** name → creates the schema from the core structure, adds the built-in lookup
rows the app needs to draw anything (meta types, a default language, the widget library; no metas),
creates an admin role in it and gives it to the person, adds `customers` and `customer_users`
rows, and opens the customer. If any step fails, nothing is left behind (no half-made schema,
no rows). Rate-limited per user and per IP (my call, until pricing exists).

**Join customer:** code → checks it exists, isn't revoked or expired, and has uses left; adds
`customer_users`, gives the code's roles in that customer's `user_roles`, counts the use, opens
the customer. Already a member → says so and opens it. A role on the code that no longer exists
is skipped.

**Sign-in:** after the password (or Google) check the person's customers are listed. None → the
New / Join page. One → chosen automatically. Several → a picker. The choice is stored on the token.

**Switching:** a switcher in the profile menu lists the person's customers; picking one changes
the token's customer and reloads the app. Profile also has New customer and Join with code.

**Invites (customer admin):** a page to create a code (roles, optional expiry, optional max uses),
list codes with their uses, copy a code, and revoke one. Codes are short and easy to type (my
call: 8 characters, no look-alike letters).

**Members (customer admin):** the existing role workspace's People list shows the customer's
members; an admin can remove someone from the customer (deletes their `customer_users` row and
their `user_roles` there). A person can leave a customer from their profile, except its last admin.

**Platform owner:** `is_platform_owner` users see a list of all customers (name, code, members,
created). Read-only for now.

## Public pages (no login)

Public metas, the portal and websites have no signed-in user, so the customer comes from the
address: public URLs include the customer's code (e.g. `/c/{code}/portal/:id`,
`/site/{code}/{site}`), and a website on its own domain is found through the main schema's
domain → customer lookup. Existing public links of the first customer keep working (redirect or
fallback to the first customer).

## Moving the existing database in

- The existing database becomes the first customer's schema (the user's company). A new main
  schema is created; users, social accounts, tokens, password resets, sessions, cache and queue
  tables move there with the same ids.
- Every existing user is linked to the first customer; their roles stay as they are. Existing
  tokens get the first customer as their customer, so nobody is logged out.
- Done by a command with a dry run that reports what it will move, refusing to run twice. It is
  run locally first, then on Aiven, with a dump taken before.
- The Aiven user must be allowed to create databases (check before relying on it).

## Building a new customer schema

Because migrations can't build an empty database, new schemas are created from a **core
structure** kept in the repo (core tables only) plus the built-in lookup rows. After this
project, changes to core tables are migrations that run on **every** customer schema
(each schema keeps its own `migrations` table), with a command that runs them across all
customers and reports failures per customer. Main-schema migrations are kept apart from
customer migrations.

## Calls made without the user (check these)

- Table names `customers` / `customer_users` / `customer_invites` (`companies` is the market's).
- Roles live per customer, so one person can be admin in one customer and a plain user in another.
- Built-in lookup rows (meta types, default language, widget library) go into every new schema;
  they are not the customer's metas.
- Customer creation is rate-limited until pricing exists.
- Invite codes are reusable until expired, revoked or out of uses; 8 easy-to-type characters.
- The customer's `code` is made from its name (made unique) and used only in public URLs.
- All existing users (4 locally, including market test accounts) are linked to the first
  customer with their current roles.
- Public URLs gain the customer's code; old links of the first customer keep working.

## Left to the builder

- How the core structure is produced and kept in step with later migrations.
- How the connection switch, job and scheduler wrappers are done.
- The `users`-in-SQL approach, as long as it meets the rules above.
- How the existing tests run with two schemas (they use SQLite `:memory:` today, where a second
  schema means an attached database), and which new tests prove isolation (one customer never
  sees another's metas, records, files, notifications, chats, cache, invites). Isolation should
  also be checked once on real MariaDB and MySQL 8.
- Exact page layouts, following the existing app style.
