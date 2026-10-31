# Market tenders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Part 3 of the tender marketplace: posting tenders with items and specs, every rule setting, prequalification, sealed or open bidding, evaluation, negotiation, award → contract.

**Architecture:** Logic in `app/Support/Market/{Tenders,Bids,Evaluation,Negotiation,Awards,Categories}.php` with thin controllers in `app/Http/Controllers/Api/V1/Market`. Per-party access and sealing are enforced in these services, never by the general meta endpoints. Categories and their spec templates are a datagrid + form meta pair (back office). Every page is a screen meta in the Tender market app (`MarketAppSeeder`), shown under the `ScreenApp` layout route.

**Tech Stack:** Laravel 13, PHPUnit, MariaDB/SQLite; React 19, Untitled UI, react-router 7.

**Spec:** `docs/superpowers/specs/2026-10-29-tender-marketplace-design.md` §6.2, §6.5, §6.6, §7, §8 part 3.

## Global Constraints

- No commits (owner: "native, no commits"). `vendor/bin/pint --dirty --format agent` after PHP changes; tests via `php artisan test --compact`.
- Sealed bids: no one (poster, staff, other bidders) reads another party's bid before the close date — enforced in the backend.
- Only verified people act; a company acts only through members with the right (`bid` to bid, `sign` to post/award).
- One company's bids and documents can't be read by another company.
- Market pages are screen metas in the Tender market app, never the main sidebar ([[market-meta-app]]).
- Fees: posting fee (poster, on publishing), bidding fee (bidder, on submitting, never refunded). Tender contracts carry no contract fee; the middleman fee is the poster's (already in `Contracts::feesDue`).
- UI strings through `t()` + `src/i18n/mn.json`; backend messages through `__()` + `lang/mn.json`.

## Defaults (spec §9: configurable, listed here)

`config/market.php` → `tenders`: code format `TND-{yymmdd}-{seq:3}`; max items 200; max specs per item 50; bid validity default = close date + 30 days; final-offer (BAFO) round default 3 days; close check every minute (`market:close-tenders`); files up to 10 MB, pdf/jpg/jpeg/png/doc/docx/xls/xlsx.

## Calls made in this plan

- Posting a tender for a company needs the `sign` right (it commits the company to buy); bidding needs `bid`; awarding needs `sign`.
- "Open" visibility shows bidders the lowest current price per item, never who offered it.
- Bid security is a `security` payment held in test mode; every security is refunded when the tender is contracted or cancelled. Forfeiting one is a staff action, left for later.
- "Matching tender" notifications go to people who follow the tender's category.
- Quality score: must-haves gate only; nice-to-haves meets = 1, exceeds = 1.2, doesn't meet = 0, averaged ×100; no nice-to-haves → 100.
- Best value = weighted sum of price score (lowest total ÷ this total), lead score (fastest ÷ this) and quality score (this ÷ best), weights from the rule (sum 100).
- Tender becomes `contracted` when every contract drafted from its award is in force.

## Review Focus

1. A poster or admin calling the tender/bid read endpoints before close must get no prices, quantities, lead times, spec answers or datasheets of any bid (only a count).
2. A bidder must never read another bidder's bid, before or after close; after close the poster reads all.
3. A bid on a prequalified tender from an unapproved bidder, after close, before start, or from a member without `bid` is refused.
4. Irrevocable bids can't be withdrawn before their validity date; revocable ones can until close.
5. Numeric must-have checks: ≥ / ≤ / = with decimals and blank offered values (blank fails).

---

### Task 1: Tables and models

**Files:** Create migration `database/migrations/2026_12_02_000000_create_market_tender_tables.php`; models `MarketCategory, MarketCategorySpec, Tender, TenderItem, TenderSpec, TenderFile, TenderApplication, Bid, BidLine, BidSpec, TenderMessage, TenderAward, TenderEvent, CategoryFollow`; `database/factories/TenderFactory.php`; `Payment::KINDS` gains `security`. Test `tests/Feature/Market/TenderModelsTest.php`.

Tables (all with timestamps unless noted):
- `market_categories` (name, parent_id null, is_active); `market_category_specs` (category_id, position, label, kind text|number, operator null|gte|lte|eq, value null, unit null, must bool).
- `tenders` (code unique, title, type goods|services|works, description, organizer_company_id null, organizer_user_id null, created_by, category_id null, incoterm null, starts_at, closes_at, status draft|open|closed|evaluating|negotiating|awarded|contracted|cancelled, rule json, round int default 1, final_round_closes_at null, published_at, cancelled_at, cancel_reason).
- `tender_items` (tender_id, position, name, quantity null, unit null, category_id null, description, budget null, show_budget bool).
- `tender_specs` (tender_item_id, position, label, kind, operator, value, unit, must).
- `tender_files` (tender_id, tender_item_id null, content_id) — the tender's documents.
- `tender_applications` (tender_id, company_id null, user_id, applicant_user_id, note, content_ids json, status pending|approved|rejected, reason, decided_by, decided_at).
- `bids` (tender_id, company_id null, user_id null, submitted_by, round, status draft|pending_fee|submitted|withdrawn|superseded|ruled_out, valid_until, total, lead_time_days, quality_score null, ruled_out_reason null, overturned bool, shortlisted bool, submitted_at).
- `bid_lines` (bid_id, tender_item_id, price, quantity, lead_time_days, amount).
- `bid_specs` (bid_id, tender_spec_id, value null, claim meets|exceeds|fails, content_id null, override_claim null, override_by null, override_note null).
- `tender_messages` (tender_id, bid_id, user_id, side poster|bidder, body).
- `tender_awards` (tender_id, bid_id, tender_item_id null, quantity null, amount, contract_id null).
- `tender_events` (tender_id, user_id null, type, note, data json, created_at only).
- `category_follows` (user_id, category_id; unique).

- [ ] Test `test_a_tender_gets_a_daily_code_and_its_parts` (create via factory → code matches `/^TND-\d{6}-001$/`, second same day `-002`; items/specs/bids relations ordered by position) — FAIL then implement (code set in `Tender::booted` created hook, counting the day's tenders under lock) — PASS.

### Task 2: Categories as metas

**Files:** `database/seeders/MarketAppSeeder.php` (grid + form metas `market_categories_grid`, `market_category_form`, detail `market_category_spec_form` over `market_category_specs`, put under Back office as "Categories"); `app/Support/Market/Categories.php` (`all(): array` active categories with `specs`), `CategoryController` (`GET /api/market/categories`, `POST/DELETE /api/market/categories/{category}/follow`). Test `tests/Feature/Market/CategoriesTest.php`.

- [ ] `test_categories_are_edited_through_metas_by_admins` (seeder makes the three metas, the grid's table is `market_categories`, the app menu has Back office → Categories; a plain user gets 403 on the grid's data).
- [ ] `test_anyone_signed_in_reads_categories_with_templates_and_follows_one`.

### Task 3: Draft, edit, cancel and files

**Files:** `app/Support/Market/Tenders.php`: `draft(User $by, array $data): Tender`, `update(User $by, Tender $t, array $data): Tender` (draft only), `cancel(User $by, Tender $t, ?string $reason): Tender` (before awarded; cancels pending fee payments, withdraws bids, refunds securities, notifies bidders), `addFile(User $by, Tender $t, ?TenderItem $item, UploadedFile $f): TenderFile`, `requirePoster(User, Tender): void`, `isPoster(?User, Tender): bool`, `log(...)`; rule validation (`rule()` defaults: award lowest_price, weights {price 60, lead 20, quality 20}, visibility sealed, partial false, split none|item|quantity, irrevocable true, valid_until null, security 0, procedure single|negotiated, prequalification false, middleman off|on|escrow). `TenderController` store/update/cancel/file upload/download. `Contents::pruneStale` skips `tender_files`, `bid_specs`, `tender_applications` contents.
- [ ] `test_a_verified_person_or_signer_drafts_a_tender_with_items_and_specs`, `test_only_the_poster_edits_a_draft`, `test_rule_is_validated` (weights must sum 100 for best_value; closes_at after starts_at; split quantity needs partial), `test_documents_upload_and_only_parties_download` — each FAIL → PASS.

### Task 4: Publish, posting fee, followers

**Files:** `Tenders::publish(User, Tender): array{tender, payment}` (posting fee invoice to the poster; paid → `open`, `published_at`, notify category followers), `Tenders::paymentPaid(Payment)`; `Payments::markPaid` dispatches `posting` → Tenders, `bidding` → Bids, `security` → Bids. Routes `POST /api/market/tenders/{tender}/publish`.
- [ ] `test_publishing_invoices_the_posting_fee_and_opens_when_paid`, `test_followers_hear_of_a_new_tender`.

### Task 5: Reading, with sealing

**Files:** `Tenders::listOpen(User, filters)` (open, q on code/title/description, category, type, paginated 20), `Tenders::listMine(User)` (posted by me or my companies), `Tenders::show(Tender, User): array` with `role` poster|bidder|visitor, items (budget only when show_budget or poster), specs, files, rule, counts {bids, applications}, `best` per item for open visibility, `can{edit,publish,cancel,apply,bid,evaluate,negotiate,award}`, my application, my bid summary; `ScreenController` unaffected.
- [ ] `test_sealed_bids_show_only_a_count_to_the_poster_and_admins_before_close`, `test_open_visibility_shows_the_lowest_price_per_item_only`, `test_budget_is_hidden_unless_shown`, `test_drafts_are_seen_only_by_the_poster`.

### Task 6: Prequalification

**Files:** `Tenders::apply(User, Tender, ?Company, note, files[])`, `decideApplication(User, TenderApplication, bool, ?reason)`; routes `POST tenders/{t}/applications`, `POST tender-applications/{a}/decide`, `GET tenders/{t}/applications` (poster only; documents downloadable by poster and applicant).
- [ ] `test_bidders_apply_and_the_poster_decides`, `test_unapproved_bidders_cannot_bid_on_a_prequalified_tender`.

### Task 7: Bidding

**Files:** `app/Support/Market/Bids.php`: `submit(User, Tender, ?Company, array $data): array{bid, payments}` (lines per item: price, quantity (= item quantity unless partial), lead time; specs: value, claim, datasheet; open only between starts_at and closes_at; prequalified; a new bid supersedes my earlier one this round; bidding fee + security invoices → `submitted` when both paid), `withdraw(User, Bid)`, `paymentPaid`, `mine(User)`, `show(Bid, User)` (bidder, or poster after close), `requireBidder`. `BidController`.
- [ ] `test_a_bid_is_submitted_once_its_fee_is_paid`, `test_bidding_is_refused_outside_the_window_or_without_the_right`, `test_a_new_bid_supersedes_the_old_one`, `test_irrevocable_bids_cannot_be_withdrawn_before_validity`, `test_a_bidder_never_reads_another_bid`.

### Task 8: Closing and evaluation

**Files:** `Tenders::closeDue(): int` (`market:close-tenders` every minute: open → closed past closes_at, notify poster + bidders; negotiating final round past final_round_closes_at → evaluating); `Evaluation.php`: `start(User, Tender)` (closed → evaluating), `of(Tender): array` (per current bid: compliance per spec — numeric check of offered value vs operator, claim, override; ruled out when a must-have fails unless overturned; quality score; totals; lead time; ranking by the rule; per-item best), `overrideClaim(User, BidSpec, claim, note)`, `overturn(User, Bid, bool, note)` — both logged in `tender_events`. Routes under `tenders/{t}/evaluation`.
- [ ] `test_closing_due_tenders`, `test_numeric_must_haves_are_checked` (≥ ≤ = with decimals, blank fails), `test_quality_scores_and_rankings_follow_the_rule` (lowest price, fastest, highest quality, best value), `test_overrides_and_overturns_are_logged`.

### Task 9: Negotiation

**Files:** `Negotiation.php`: `shortlist(User, Tender, bidIds)` (evaluating → negotiating, procedure negotiated only), `message(User, Bid, body)` (poster ↔ that bidder only), `messages(User, Bid)`, `revise(User, Bid, data)` (shortlisted bidder, new bid with round+1, no new fee), `finalRound(User, Tender, ?closesAt)` (BAFO, default +3 days), `finish(User, Tender)` (→ evaluating).
- [ ] `test_negotiation_rounds_and_final_offers`, `test_messages_are_private_to_poster_and_bidder`.

### Task 10: Award → contracts

**Files:** `Awards.php`: `award(User, Tender, array $lines)` (whole: one bid; item: a bid per item; quantity: bid+quantity per item, quantities ≤ item quantity and ≤ offered), each winner gets a contract via `Contracts::draft` (source tender, source_id, items from awarded lines, middleman from rule; buyer = organizer); `tender_awards.contract_id`; status awarded; losers + winners notified; `contractInForce(Contract)` hook from Contracts when a tender contract goes active → contracted when all are; securities refunded on contracted/cancelled.
- [ ] `test_awarding_drafts_a_contract_per_winner`, `test_split_awards_by_item_and_quantity`, `test_the_tender_is_contracted_when_its_contracts_are_in_force`.

### Task 11: Frontend — browse, post, bid

**Files:** `src/lib/tenderApi.ts`; `src/pages/market/tenders/{TendersPage,MyTendersPage,TenderEditor,NewTenderPage,TenderPage,BidEditor,MyBidsPage,tenderLabels}.tsx`; routes `/market/tenders`, `/market/tenders/mine`, `/market/tenders/new`, `/market/tenders/:id`, `/market/tenders/:id/edit`, `/market/tenders/:id/bid`, `/market/bids` under ScreenApp; screen metas + menu items (Tenders, My tenders, My bids) in MarketAppSeeder. Gate: `npx tsc --noEmit -p .` + browser.

### Task 12: Frontend — evaluation, negotiation, award; memory

**Files:** `src/pages/market/tenders/{EvaluationPage,NegotiationPanel,AwardModal}.tsx`, route `/market/tenders/:id/evaluation`; translations; browser check of the whole flow with fake drivers; memory file `tender-market-tenders.md` + index line.
