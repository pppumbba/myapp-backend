# Market part 2: contracts, fees & payments — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One contract model (parties, items, price, delivery date, payment terms, discount, pledge) signed with a fresh DAN sign-in, tracked from draft to completion with ratings; fees set by staff and paid through a payment provider (fake + QPay); escrow ledger in test mode with disputes decided by staff.

**Architecture:** Market module (`app/Support/Market/*`) holds every rule; controllers in `Api/V1/Market` are thin. Per-party access is checked in `Contracts::sideOf`, never in the UI. Payments go through a `PaymentDriver` interface bound in `AppServiceProvider` like DAN/ХУР. Until tenders (part 3) and the marketplace (part 4) exist, a buyer starts a **direct contract** with a vendor (spec 6.3: "sign a contract directly"); those parts will call the same `Contracts::draft`.

**Tech Stack:** Laravel 13, PHPUnit, dompdf (already installed, `Barryvdh\DomPDF\Facade\Pdf`), React 19 + Untitled UI.

**Spec:** `docs/superpowers/specs/2026-10-29-tender-marketplace-design.md` (§6.4, §6.5, §8 part 2). Builds on part 1 (`docs/superpowers/plans/2026-10-29-market-accounts-verification.md`).

## Global Constraints

- ₮ only, amounts stored as `decimal(15,2)`, fees rounded to whole ₮.
- No git commits (owner: "no commits"). No new dependencies.
- Every message through `__()` + `lang/mn.json`; every UI string through `t()` + `src/i18n/mn.json`.
- `php artisan make:* --no-interaction`; `vendor/bin/pint --dirty --format agent` after PHP changes; tests with `php artisan test --compact`.
- Fake drivers and pages return 404 in production.
- Anti-goals (spec §4): one party's contracts/payments are never readable by another company; held money moves only on buyer confirmation, the auto-release timeout, or a staff dispute decision.

## Defaults chosen (spec §9: "listed in each part's plan")

| Setting | Default | Where |
|---|---|---|
| Posting fee | fixed 10 000₮ | `market_fees` row, staff-editable |
| Bidding fee | fixed 5 000₮ | `market_fees` |
| Contract fee | 1% of contract total | `market_fees` |
| Middleman fee | 2% of contract total | `market_fees` |
| Auto-release of a requested escrow step | 7 days | `config('market.escrow.auto_release_days')` |
| Escrow mode | `test` | `config('market.escrow.mode')` |
| Payment driver | `fake` | `config('market.payments.driver')` |

## Decisions (calls)

- **Who acts:** a side is a company (all members browse; actions need `can_sign`) or an individual verified user. Viewing a contract needs the side; every change needs the `sign` right. Admins see a contract only while it has a dispute.
- **Editing:** either side edits in `draft`/`signing`; any edit bumps `version`, drops signatures and returns to `draft`.
- **Status:** `draft → signing (one signature) → awaiting_payment (both signed, buyer fees due) → active → in_progress → delivered → completed`; buyer rejection `delivered → in_progress` with a reason; `cancelled` from draft/signing/awaiting_payment by either side.
- **Fees on a contract:** contract fee (direct/proposal contracts only — a tender's poster already paid the posting fee) + middleman fee when middleman ≠ off; one invoice to the buyer after both signatures.
- **Payment steps:** label + percent; amounts computed from the total. Without escrow they are informational. With `middleman = escrow`: buyer funds a step (escrow payment) → vendor requests release → buyer confirms (released) or rejects with a reason; requested steps auto-release after 7 days. A dispute (middleman on or escrow) freezes unreleased held steps until staff decide release or refund.
- **PDF:** Blade view → dompdf, stored at `market/contracts/{id}-v{version}.pdf` on the local disk once both sides signed; path on the contract.
- **Signing:** `Verification::start` gains a purpose; the DAN callback checks the person who signed in is the user who started it, then records the signature for that version.

## Review Focus

1. Another company's member (or an unverified user) reads or changes a contract → 403 everywhere, including the PDF and payments.
2. A signature made on an older version still counts after an edit → it must not.
3. A payment marked paid twice (callback + manual check) → fees/escrow applied once.
4. A frozen (disputed) step auto-releasing → never.
5. A DAN sign-in as a different person completing someone else's signature → refused.

---

### Task 1: Tables and models

**Files:** migration `2026_12_01_000000_create_market_contract_tables.php`; models `Contract`, `ContractItem`, `ContractStep`, `ContractSignature`, `ContractEvent`, `ContractDispute`, `Rating`, `MarketFee`, `Payment`, `EscrowEntry`; test `tests/Feature/Market/ContractModelsTest.php`.

- contracts: `number` unique (`C-{Y}-{id:06}` set after insert), `source` (direct|tender|proposal), `source_id`, `title`, `buyer_company_id`/`buyer_user_id`, `vendor_company_id`/`vendor_user_id` (company null = individual), `total`, `discount`, `delivery_date`, `payment_terms`, `pledge`, `pledge_amount`, `middleman` (off|on|escrow), `status`, `version`, `pdf_path`, `created_by`, `signed_at`, `completed_at`, `cancelled_at`.
- items (name, description, quantity, unit, unit_price, amount), steps (position, label, percent, amount, status pending|funded|requested|released|refunded|frozen, requested_at, auto_release_at, released_at, note), signatures (side, user_id, registry_number, verified_name, version, signed_at; unique contract+side), events (user_id, type, from_status, to_status, note), disputes (opened_by, side, reason, status open|decided, resolution release|refund, decision, decided_by, decided_at), ratings (contract_id, side, rater_user_id, rated_company_id, rated_user_id, stars, comment; unique contract+side), market_fees (kind unique, mode fixed|percent, amount) seeded with the defaults, payments (user_id, company_id, kind posting|bidding|contract|middleman|escrow, amount, status pending|paid|cancelled|refunded, driver, invoice_id, qr_text, qr_image, pay_url, subject_type/subject_id, contract_step_id, receipt_number, paid_at), escrow_entries (contract_id, contract_step_id, payment_id, type held|released|refunded|frozen|unfrozen, amount, user_id, note).
- Test: `test_a_contract_holds_items_steps_and_parties` (relations, number format, fee defaults seeded).

### Task 2: Fee settings

`App\Support\Market\Fees`: `quote(string $kind, float $value = 0): int`, `all(): array`, `update(array)`. `FeeController`: `GET /api/market/fees` (anyone signed in — shown before paying), `PUT /api/market/fees` (admin). Test `FeesTest`: percent of value rounds to whole ₮; fixed ignores value; non-admin PUT 403; bad mode 422.

### Task 3: Payments core + fake provider

`PaymentDriver` (`createInvoice(Payment): array{invoice_id, qr_text?, qr_image?, pay_url?}`, `isPaid(Payment): bool`), `FakePaymentDriver` (pay page `/market/pay/fake/{payment}` sets a cache flag; 404 in production), `Payments`: `invoice(User, ?Company, kind, amount, Model subject, ?ContractStep): Payment` (0₮ → paid at once), `refresh(Payment): Payment` (asks driver, `markPaid` once under a row lock), `markPaid` calls `Contracts::paymentPaid($payment)`. Routes: `GET /api/market/payments` (mine), `GET /api/market/payments/{payment}` (re-checks), `GET /api/market/payments/{payment}/receipt` (PDF), `POST /market/payments/{payment}/callback` (web, re-checks with the driver; never trusts the body). Test `PaymentsTest`: invoice → fake pay → refresh marks paid once (hook once); receipt only for the payer; other user 403; callback paid twice applies once.

### Task 4: QPay driver

`QPayDriver` via `Http` (`/v2/auth/token` basic auth → bearer, cached; `/v2/invoice`; `/v2/payment/check` paid when `paid_amount >= amount`). Config `market.payments.qpay.{url,client_id,client_secret,invoice_code}` from `QPAY_*` env. Test `QPayDriverTest` with `Http::fake`: invoice maps fields; check paid/unpaid; token reused.

### Task 5: Contracts: draft, edit, cancel, read

`Contracts`: `draft(User by, array data): Contract` (buyer side = by, as individual or `buyer_company_id` with sign right; vendor by registry number: 7 digits → registered market company, else a verified person; not yourself), `update(User, Contract, array)`, `cancel(User, Contract, ?reason)`, `sideOf(?User, Contract): ?string`, `show(Contract, User): array`, `listFor(User)`. Items/steps replace on save; total = Σ(quantity×unit_price) − discount; step percents must sum to 100. Events + notifications to the other side's signers. Routes under `/api/market/contracts`. Test `ContractDraftTest`: draft with company buyer; other company 403 (read & write); edit bumps version and returns to draft; percents ≠ 100 → 422; cancel.

### Task 6: Signing with DAN

`Verification::start(User, ?array purpose)`; callback with purpose `sign` → `Contracts::completeSigning(User, contractId, version, citizen)` → redirect `/market/contracts/{id}?signed=1` (or `sign_error`). Both signed → PDF + fees invoice (buyer) → `awaiting_payment`, or `active` when 0₮. `paymentPaid` for contract/middleman payments → `active`. Test `ContractSigningTest`: both sign → PDF + awaiting_payment → pay → active; old-version signature rejected after an edit; signing in as another person refused; member without sign 403.

### Task 7: Status flow and ratings

`start`, `deliver` (vendor), `confirm`, `reject(reason)` (buyer), `rate(User, Contract, stars, comment)` after completion, once per side; `ratingsFor(company|user)`. Test `ContractFlowTest`.

### Task 8: Escrow steps and disputes

`Escrow`: `fund(User, ContractStep): Payment` (buyer, escrow contracts, step pending), `paymentPaid` → funded + held entry, `request(User, step)` (vendor; funded → requested, auto_release_at = +7 days), `release(User, step)` / `rejectRelease(User, step, reason)` (buyer), `autoRelease(): int` (command `market:release-due`, hourly), `openDispute(User, Contract, reason)` (middleman on/escrow; freezes funded/requested steps), `decide(User admin, ContractDispute, resolution, decision)` (release → released, refund → refunded + payment refunded). Admin routes `GET /api/market/disputes`, `POST /api/market/disputes/{dispute}/decide`. Test `EscrowTest`: fund→request→confirm; auto-release after 7 days; frozen step never auto-releases; staff refund; non-escrow fund 422.

### Task 9: Frontend — contracts and payments

`marketApi` additions; pages `ContractsPage` (`/market/contracts`, list + New contract), `ContractPage` (`/market/contracts/:id`: parties, items/terms/steps editor in draft, sign, status actions, steps & escrow actions, timeline, rating, PDF), `PaymentsPage` (`/market/payments`, receipts), `PayModal` (QR or link, re-check). Nav: Contracts (File06), Payments (CreditCard02). Browser check of the full path with the fake drivers.

### Task 10: Frontend — staff pages

`MarketFeesPage` (`/admin/market-fees`), `MarketDisputesPage` (`/admin/market-disputes`). Translations; browser check; memory update.
