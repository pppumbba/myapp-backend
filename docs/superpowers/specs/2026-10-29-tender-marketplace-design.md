# Tender marketplace: design

Date: 2026-10-29. Status: approved by the owner on 2026-10-29.

Marks used below: **(owner)** = the owner said this. **(call)** = Claude's call, open to change. Anything unmarked follows directly from an owner decision.

## 1. Intent

Turn the platform's apps into products that the public uses, starting with a **Tenders** marketplace for general services and local (Mongolian) transactions:

- Anyone can post a tender, and verified people and companies bid on it. **(owner)**
- Vendors list their services and products. Buyers compare vendors and either sign a contract directly or send a proposal and negotiate. **(owner)**
- The platform can act as the **middleman** between the two sides. It may also hold the money (escrow), and that choice belongs to the poster or buyer on each deal. **(owner)**
- The platform earns from fees: on posting a tender, on each bid, on a marketplace contract, and on the middleman service. **(owner)**

The marketplace is used through **its own mobile app and a website that work the same way**. **(owner)** The builder tools (metas, Studio, query builder and so on) stay on the existing admin web app and never appear in the mobile app. **(owner)**

Tenders is the first app of a general idea: **each app meta can be shipped as its own mobile app**. All of them share one login, one user profile and one notification system. **(owner)**

## 2. Goals

1. Verified identities. Individuals verify through **DAN**, and companies are checked through **ХУР**. **(owner)** Every bid, signature and status change traces back to a real, verified person.
2. Tenders are flexible enough to cover real public and private procurement: the award rule, sealed or open bidding, prequalification, partial and split awards, bid validity, and negotiated rounds.
3. Bids can be compared fairly and mostly automatically, by checking each bid against structured item specs, with must-haves and nice-to-haves.
4. A marketplace with vendor listings, search, side-by-side comparison, and proposals with counter-offers.
5. One contract model shared by tenders and the marketplace, signed in the app, with status tracking through to completion and ratings at the end.
6. Fee collection works from day one. Escrow is designed in now and switched on once a licensed partner is in place.
7. The same features on web, Android and iOS, from one codebase.

## 3. Non-goals (for this project)

- Building or administering apps from a phone. **(owner)**
- Automatic penalties or dispute rulings. Problems are handled under the contract's own terms. **(owner)** The app records what happened, and with the middleman service on, the team decides disputes by hand.
- Foreign currencies, cross-border trade and foreign companies. Everything is local and in ₮ only. **(owner: "local transactions")** **(call)**
- Server-side rendering and SEO of listings. **(call)** These can be added later.
- Holding money in the platform's own bank account. Escrow only goes through a licensed partner (see §9).

## 4. Anti-goals (what would make this a failure even if it works)

**(call)**: these are inferred and need the owner's confirmation.

- Anyone, including the poster and the platform's staff, sees a sealed bid before the close date.
- An unverified person, or someone without authority to act for a company, posts, bids or signs.
- Held money moves without the buyer's confirmation, the auto-confirm timeout, or a staff decision on a dispute.
- One company's bids, contracts or documents can be read by another company.
- Phone users get a cut-down product, with features that exist only on the web.

## 5. Constraints

- **Existing platform:** Laravel 13 + Sanctum bearer tokens, a React 19 + Vite + Untitled UI front end, and MySQL/MariaDB (MariaDB 10.4 in development). The metadata engine (forms, grids, workspaces, matrices, process flows, statuses, autonumber, files, notifications, PDF documents, public metas) already exists. Build on it, don't replace it.
- **Access model gap:** today, access is per meta through roles. Anyone who can view a grid sees every row. A marketplace needs **per-record, per-party** access: my bids, my company's contracts, sealed until close. This has to be enforced in the backend, never by hiding things in the UI.
- **DAN and ХУР** need a signed access agreement with the government agency that runs them, a fixed server IP and their certificates. Until that agreement exists, both run against a **fake driver** with test people and companies.
- **Payments:** fees need a merchant account with a provider (QPay is the default choice **(call)**). Escrow needs a licensed partner (a bank or payment company) and may need registration with the Financial Regulatory Commission. Until then, escrow runs with test money only.
- **iOS builds** need a Mac. The owner is on Windows, so iOS builds use a cloud build service. Store accounts: Google Play $25 once, Apple $99 per year.
- **The backend must be live on HTTPS** (the Oracle Cloud plan) before the mobile apps can work.
- **Network:** GitHub is blocked on the development network, so dependencies and CI must not rely on it from there.

## 6. The product, part by part

### 6.1 Accounts & verification

- One account per person, the same `users` table as the platform. Anyone can sign up and browse. Posting, bidding, proposals and signing need verification.
- **Individual:** verifies by signing in with DAN. The app stores the verified name and registry number (регистрийн дугаар) and shows a "verified" badge. A registry number can belong to only one account.
- **Company:** a verified individual enters a company registry number. The app looks it up in ХУР (name, active or liquidated, director) and creates the company profile. **(owner)**
- **Representation:** the person acting for the company does not have to be the director. **(owner)** They become a representative in either of two ways **(owner: both)**:
  1. **Director approves:** the director receives a link, signs in with DAN, and the app checks that their registry number matches the director in ХУР. The director then approves, and the change takes effect at once.
  2. **Power of attorney (итгэмжлэл):** the person uploads the signed and stamped letter. Staff approve or reject it, with a reason, from a review queue.
- A verified representative can invite colleagues and give each one rights: **browse / bid & propose / sign contracts / manage members**. **(call)**
- Companies are re-checked against ХУР from time to time. A liquidated company is frozen and keeps read-only access to its history. **(call)**

### 6.2 Tenders

**Tender (header)** **(owner)**: name, code (autonumber, e.g. `TND-261029-001` **(call)**), organizer (the poster: an individual, or a company acting through its representative), type (goods / services / works **(call)**), rule (see below), description, start date, close date, status, incoterm (for goods).

Status flow **(call)**: Draft → Open → Closed → Evaluating → (Negotiating) → Awarded → Contracted, or Cancelled at any point before Awarded.

**Items** (lines) **(owner)**: item name, quantity (optional), unit **(call)**, item category, description, budget (optional; hidden from bidders unless the poster shows it **(call)**), specs, documents (multifile attachments).

**Specs** **(owner)**: each item carries specification lines, for example "Display: 15.6-inch Full HD" or "Memory: 8GB DDR4".
- Each spec is either a **must-have** or a **nice-to-have**. **(owner)**
- A spec can be plain text or a number with a comparison (≥, ≤ or =) and a unit. Numeric specs are checked automatically. **(call)**
- Each item category has a spec template that the poster can edit. **(call)**

**Rule: settings the poster combines** **(owner list, grouped by call)**:

| Setting | Options |
|---|---|
| Award criteria | Lowest price · Fastest lead time · Highest quality · Best value (the poster sets weights for price, lead time and quality) |
| Visibility | **Sealed**: no one sees any bid until the close date. **Open**: bidders see the best current price and can bid again **(call)** |
| Quantities | Allow partial quantities · Allow a split award per item or per quantity **(call)** |
| Bid irrevocability | Bids stay binding until a validity date. Optional **bid security** (a deposit, which needs escrow) **(call)** |
| Procedure | **Single round**, or **negotiated / competitive dialogue**: shortlist, then message rounds with revised offers, then best-and-final offers |
| Prequalification | Off, or on: bidders apply with documents and the poster approves them before they can bid |
| Middleman | Off · On, money not held · On, money held (escrow) **(owner)** |

**Bids:**
- For each item, a bid gives the price, quantity offered (when partial quantities are allowed) and lead time.
- For each spec, the bid gives the offered value and a claim of **meets / exceeds / doesn't meet**, with a datasheet attached where needed.
- Bids can't be withdrawn before their validity date when irrevocability is on.

**Evaluation:**
- A bid that fails a must-have is ruled out automatically. The poster can review the claim and overturn the result. **(owner: "yes of course")**
- The quality score is worked out from the spec answers (proposed: meets = 1, exceeds = 1.2, doesn't meet = 0 on nice-to-haves, averaged) **(call)**. The poster can override any single answer, and every override is logged.
- The award is ranked by the chosen criteria. The poster confirms the winner, or the winners when the award is split. The comparison view reuses the existing matrix (bidder × item) idea.

### 6.3 Marketplace

**Vendor listing** **(owner)**: company profile, services and products (each with price, category, specs and photos), ratings, category, location, and terms (delivery, payment, warranty). Price types are fixed, "from" or "on request". **(call)**

- **Search and filter:** category, location (aimag/city and district, or nationwide), price range, rating, verified only, and spec values. **(call)**
- **Comparison:** the buyer picks several vendors or listings and sees price, lead time, rating, terms and specs side by side, with the best value in each row highlighted. **(owner: comparison; layout is a call)**
- Vendors are told about new tenders in the categories they serve. **(call)**

**Proposal to buy** **(owner)**: the buyer sends a proposal; the vendor accepts, counters or declines; the two sides can keep countering; an accepted proposal becomes a contract.
- Each round is kept as history. **(call)**
- A proposal expires after a set time if no one answers (default 3 days), and the buyer can withdraw it until the vendor responds. **(call)**
- The middleman service is optional here too. **(owner)**

### 6.4 Contracts (shared by tenders and the marketplace)

**Contents** **(owner)**: parties, items, price, delivery date, payment terms, discount, pledge (барьцаа).
- A contract is prefilled from the winning bid or the accepted proposal. Either side can propose changes before signing. **(call)**
- A split award produces one contract per supplier. **(call)**
- Payment terms are a list of steps (e.g. 30% advance, 70% on delivery). When the platform holds the money, these steps are the release points. **(call)**

**Signing:** each party's authorized signer reviews the contract and confirms by signing in with DAN again. **(call)** The signed contract is stored as a PDF made with the existing document designer. A formal digital signature (тоон гарын үсэг) may be added later.

**After signing** **(owner)**:
- The vendor sets the status: In progress → Delivered / Done.
- The buyer confirms, which completes the contract, or rejects with a reason, which sends it back to the vendor.
- Problems are handled under the contract's own terms.
- **(call):**
  - With escrow, the buyer's confirmation releases the payment step. Without a reply, the step confirms itself after N days (default 7). A dispute freezes the money until staff decide.
  - Both sides rate each other after completion. Ratings appear on vendor listings, and only completed contracts can be rated.

### 6.5 Fees & payments

| Fee | Charged on | Paid by |
|---|---|---|
| Posting fee | Publishing a tender | Poster **(owner)** |
| Bidding fee | Submitting a bid | Bidder. **Not refunded** to bidders who lose **(owner)** |
| Contract fee | A marketplace contract | Buyer **(owner)** |
| Middleman fee | Turning the service on, with or without held money | Buyer (the poster, on a tender) **(owner)** |

- Staff set every amount in settings, as a fixed amount in ₮ or a percentage of the value. **(call)**
- Payments go through a provider interface: a QPay driver plus a fake driver for testing. **(call)**
- Each user has a payment history with receipts.
- The escrow ledger (held, released, refunded, frozen) is built on the same interface. It stays in test mode until a licensed partner is signed.

### 6.6 Notifications

The existing notification system, plus **push** to phones (FCM for Android, APNs for iOS). **(call)** Each mobile app shows only the notifications that belong to its own app meta. **(call)**

Events that send a notification:
- a new matching tender
- prequalification decisions
- a tender closing
- an award
- a proposal or counter-offer
- a contract waiting for a signature
- a status change
- a payment step
- verification decisions

### 6.7 Website and mobile apps

- **One codebase for web, Android and iOS. (owner: same on web and phone)** The marketplace screens are React. The phone apps wrap them with **Capacitor**, which adds push, the camera (for attachments) and biometric unlock. **(call)**
- The website at its own domain (e.g. `tender.<domain>.mn`) runs the full marketplace. **(owner)** Public information pages (home, how it works, fees, terms) are made with the existing **website builder**. **(call)**
- **"Mobile app" on metas:**
  - An app meta gets mobile settings: app name, bundle id, icon, splash, colours, and its store listing. **(owner: add mobile app to metas)**
  - A build script creates one Capacitor project per app meta from the shared code, so a new mobile app needs no new code.
  - This is stored as mobile settings on `app` metas, not as a separate meta type, so an app's menu and screens are written once. **(owner approved)**
- Signing in once on a phone and being signed in to the platform's other apps is wanted later, not in this project. **(call)**

## 7. How it fits the platform **(owner approved the split; details are calls)**

- **Backend:** a `Market` module with its own tables, services and policies:
  - verification
  - companies and members
  - tenders, items and specs
  - prequalification and bids
  - evaluations
  - listings and proposals
  - contracts and steps
  - fees, payments and the escrow ledger
  - ratings
  - DAN/ХУР drivers

  The rules that keep data safe (sealed bids, party-only access, money moves) are enforced in these services and policies, **not** through the general meta grid endpoints, whose access is per meta.
- **Where metas fit:**
  - The staff back office: review queues, categories and spec templates, fee settings, disputes. These are mostly grids and forms over the Market tables, admin-only.
  - Reports and dashboards for staff.
  - The app menu that ships as the mobile app. This decides which marketplace screens appear and in what order.
- **Marketplace screens:** new React screen types (e.g. `market_tenders`, `market_tender`, `market_bid`, `market_listings`, `market_compare`, `market_contract`). They can be placed in an app menu like any other meta screen. They reuse the existing components (forms, grids, matrix, file field, document PDF).
- The existing **Tenders demo** (bidder × item matrix) stays as the internal-procurement demo. The marketplace's comparison reuses its ideas, not its tables.

## 8. Delivery order **(owner agreed to the four parts; the split is a call)**

1. **Accounts & verification:** DAN/ХУР drivers (fake first), companies, representation in both ways, members and rights, staff review queue.
2. **Contracts, fees & payments core:** contract model, DAN-confirmed signing, PDF, status flow, ratings, fee settings, payment provider (fake + QPay). Escrow ledger in test mode.
3. **Tenders:** posting, items and specs, every rule setting, prequalification, bidding, evaluation, award → contract.
4. **Marketplace:** listings, search, comparison, proposals and counter-offers → contract.
5. **Mobile app on metas:** mobile config, Capacitor builds, push, store listings. The website domain goes live with the backend.

Each part gets its own step-by-step plan before it is built.

## 9. Left to the builder

- The exact table layout, indexes and API routes.
- The visual design of the marketplace screens. They should follow the platform's look, with the brand colour applied.
- Search technique (MySQL full-text is likely enough at the start).
- Default values for timeouts and limits not set above, chosen sensibly, made configurable, and listed in each part's plan.

## 10. Decisions taken after the first draft (2026-10-29)

- The split is approved: a dedicated Market module holds the marketplace logic, and metas drive the app menu, the staff back office and reports. "Mobile app" is a set of settings on app metas.
- The bidding fee is not refunded.
- The buyer pays the contract fee and the middleman fee.

No open questions remain. Calls marked **(call)** stand unless the owner changes them.
