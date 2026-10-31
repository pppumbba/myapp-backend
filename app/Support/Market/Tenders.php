<?php

namespace App\Support\Market;

use App\Models\Bid;
use App\Models\BidLine;
use App\Models\CategoryFollow;
use App\Models\Company;
use App\Models\Content;
use App\Models\Payment;
use App\Models\Tender;
use App\Models\TenderApplication;
use App\Models\TenderEvent;
use App\Models\TenderFile;
use App\Models\TenderItem;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tenders: drafting, publishing, reading (with sealed bids kept sealed), prequalification,
 * closing and cancelling. Bids, evaluation, negotiation and awards have their own classes.
 */
class Tenders
{
    public const AWARD = ['lowest_price', 'fastest', 'highest_quality', 'best_value'];

    public const VISIBILITY = ['sealed', 'open'];

    public const SPLIT = ['none', 'item', 'quantity'];

    public const PROCEDURE = ['single', 'negotiated'];

    public const MIDDLEMAN = ['off', 'on', 'escrow'];

    public const OPERATORS = ['gte', 'lte', 'eq'];

    /** Statuses a tender may be cancelled from: anything before the award. */
    public const CANCELLABLE = ['draft', 'open', 'closed', 'evaluating', 'negotiating'];

    public const FILE_TYPES = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];

    public const FILE_MAX_BYTES = 10 * 1024 * 1024;

    /**
     * The rule with every setting filled in: what the poster chose, defaults for the rest.
     *
     * @param  array<string, mixed>  $rule
     * @return array{award: string, weights: array{price: int, lead: int, quality: int}, visibility: string, partial: bool, split: string, irrevocable: bool, valid_until: ?string, security: float, procedure: string, prequalification: bool, middleman: string}
     */
    public static function rule(array $rule): array
    {
        $pick = fn (string $key, array $options) => in_array($rule[$key] ?? null, $options, true) ? $rule[$key] : $options[0];
        $weights = (array) ($rule['weights'] ?? []);

        return [
            'award' => $pick('award', self::AWARD),
            'weights' => [
                'price' => (int) ($weights['price'] ?? 60),
                'lead' => (int) ($weights['lead'] ?? 20),
                'quality' => (int) ($weights['quality'] ?? 20),
            ],
            'visibility' => $pick('visibility', self::VISIBILITY),
            'partial' => (bool) ($rule['partial'] ?? false),
            'split' => $pick('split', self::SPLIT),
            'irrevocable' => (bool) ($rule['irrevocable'] ?? true),
            'valid_until' => $rule['valid_until'] ?? null,
            'security' => round((float) ($rule['security'] ?? 0), 2),
            'procedure' => $pick('procedure', self::PROCEDURE),
            'prequalification' => (bool) ($rule['prequalification'] ?? false),
            'middleman' => $pick('middleman', self::MIDDLEMAN),
        ];
    }

    /**
     * A verified person drafts a tender, as themselves or for a company they may sign for.
     *
     * @param  array<string, mixed>  $data
     */
    public static function draft(User $by, array $data): Tender
    {
        abort_unless($by->isVerified(), 403, __('Verify yourself with DAN first.'));
        $company = null;
        if (! empty($data['company_id'])) {
            $company = Company::findOrFail($data['company_id']);
            abort_unless(MarketAccess::canActFor($by, $company, 'sign'), 403, __('You may not sign for this company.'));
        }
        self::check($data);

        return DB::transaction(function () use ($by, $data, $company) {
            $tender = Tender::create([
                'title' => $data['title'],
                'type' => $data['type'] ?? 'goods',
                'description' => $data['description'] ?? null,
                'organizer_company_id' => $company?->id,
                'organizer_user_id' => $company ? null : $by->id,
                'created_by' => $by->id,
                'category_id' => $data['category_id'] ?? null,
                'incoterm' => $data['incoterm'] ?? null,
                'starts_at' => $data['starts_at'],
                'closes_at' => $data['closes_at'],
                'status' => 'draft',
                'rule' => $data['rule'] ?? [],
            ]);
            self::fillItems($tender, $data['items']);
            self::log($tender, $by, 'created');

            return $tender->fresh();
        });
    }

    /**
     * The poster changes a draft. Items, when sent, replace the old ones with their specs.
     *
     * @param  array<string, mixed>  $data
     */
    public static function update(User $by, Tender $tender, array $data): Tender
    {
        self::requirePoster($by, $tender);
        abort_unless($tender->status === 'draft', 422, __('Only a draft can be changed.'));
        self::check($data + ['starts_at' => $tender->starts_at, 'closes_at' => $tender->closes_at]);

        return DB::transaction(function () use ($by, $tender, $data) {
            $tender->update(array_intersect_key($data, array_flip(['title', 'type', 'description', 'category_id', 'incoterm', 'starts_at', 'closes_at', 'rule'])));
            if (array_key_exists('items', $data)) {
                $tender->items()->delete();
                self::fillItems($tender, $data['items']);
            }
            self::log($tender, $by, 'edited');

            return $tender->fresh();
        });
    }

    /**
     * The poster calls the tender off before the award: open fee invoices are cancelled, bids
     * withdrawn, bid securities refunded, and bidders told.
     */
    public static function cancel(User $by, Tender $tender, ?string $reason): Tender
    {
        self::requirePoster($by, $tender);
        abort_unless(in_array($tender->status, self::CANCELLABLE, true), 422, __('This tender can no longer be cancelled.'));

        return DB::transaction(function () use ($by, $tender, $reason) {
            $bidders = self::bidders($tender);
            $tender->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $tender->bids()->whereNotIn('status', ['withdrawn', 'superseded'])->update(['status' => 'withdrawn']);
            self::payments($tender)->where('status', 'pending')->update(['status' => 'cancelled']);
            self::refundSecurities($tender);
            self::log($tender, $by, 'cancelled', $reason);
            foreach ($bidders as $user) {
                MarketNotify::send($user, __('Tender cancelled: :title', ['title' => $tender->title]), $reason, self::link($tender), 'warning');
            }

            return $tender->fresh();
        });
    }

    /**
     * The poster publishes a draft: the posting fee is invoiced (the same open invoice when asked
     * again), and the tender opens once it is paid — at once when the fee is nothing.
     *
     * @return array{tender: Tender, payment: Payment}
     */
    public static function publish(User $by, Tender $tender): array
    {
        self::requirePoster($by, $tender);
        abort_unless($tender->status === 'draft', 422, __('This tender is already published.'));
        abort_if($tender->closes_at === null || $tender->closes_at->isPast(), 422, __('The close date must be in the future.'));

        $payment = Payment::where('subject_type', $tender->getMorphClass())->where('subject_id', $tender->id)
            ->where('kind', 'posting')->where('status', 'pending')->first();
        if ($payment) {
            $payment = Payments::refresh($payment);
        } else {
            $payment = Payments::invoice($by, $tender->organizerCompany, 'posting', Fees::quote('posting'), $tender);
        }

        return ['tender' => $tender->fresh(), 'payment' => $payment];
    }

    /** The posting fee is paid: the tender opens and its category's followers hear of it. */
    public static function paymentPaid(Payment $payment): void
    {
        $tender = Tender::find($payment->subject_id);
        if (! $tender || $tender->status !== 'draft') {
            return;
        }
        $tender->update(['status' => 'open', 'published_at' => now()]);
        self::log($tender, $payment->user, 'published');

        if ($tender->category_id) {
            $posters = self::posters($tender);
            $followers = User::whereIn('id', CategoryFollow::where('category_id', $tender->category_id)->pluck('user_id'))->get();
            foreach ($followers->reject(fn (User $u) => in_array($u->id, $posters, true)) as $user) {
                MarketNotify::send($user, __('New tender: :title', ['title' => $tender->title]), __('Closes :date.', ['date' => $tender->closes_at->toDateString()]), self::link($tender));
            }
        }
    }

    /**
     * The people who act for the organizer: the person, or the company's members who may sign.
     *
     * @return list<int>
     */
    public static function posters(Tender $tender): array
    {
        if ($tender->organizer_user_id) {
            return [$tender->organizer_user_id];
        }

        return $tender->organizerCompany->members()->where('can_sign', true)->pluck('user_id')->all();
    }

    /**
     * A bidder asks to take part in a prequalified tender, as themselves or for a company they
     * may bid for, with a note and papers. One open or approved request per party.
     *
     * @param  list<UploadedFile>  $files
     */
    public static function apply(User $by, Tender $tender, ?Company $company, ?string $note, array $files): TenderApplication
    {
        abort_unless($by->isVerified(), 403, __('Verify yourself with DAN first.'));
        abort_if(self::isPoster($by, $tender), 403, __('The organizer does not apply to their own tender.'));
        if ($company) {
            abort_unless(MarketAccess::canActFor($by, $company, 'bid'), 403, __('You may not bid for this company.'));
        }
        abort_unless($tender->status === 'open' && $tender->rule['prequalification'], 422, __('This tender takes no applications.'));
        abort_if(self::partyApplications($tender, $by, $company)->whereIn('status', ['pending', 'approved'])->exists(), 422, __('You have already applied.'));
        foreach ($files as $file) {
            self::checkFile($file);
        }

        return DB::transaction(function () use ($by, $tender, $company, $note, $files) {
            $contentIds = array_map(fn (UploadedFile $file) => Content::create([
                'physical_path' => $file->store('market/applications', 'local'),
                'file_name' => $file->getClientOriginalName(),
                'file_extension' => strtolower($file->getClientOriginalExtension()),
                'file_size' => $file->getSize(),
                'is_public' => false,
                'created_by' => $by->id,
            ])->id, $files);
            $application = TenderApplication::create([
                'tender_id' => $tender->id,
                'company_id' => $company?->id,
                'user_id' => $company ? null : $by->id,
                'applicant_user_id' => $by->id,
                'note' => $note,
                'content_ids' => $contentIds,
                'status' => 'pending',
            ]);
            self::log($tender, $by, 'applied');
            foreach (User::whereIn('id', self::posters($tender))->get() as $user) {
                MarketNotify::send($user, __('New application: :title', ['title' => $tender->title]), $company?->name ?? $by->verified_name, self::link($tender));
            }

            return $application;
        });
    }

    /** The poster approves or refuses an application; the applicant hears either way. */
    public static function decideApplication(User $by, TenderApplication $application, bool $approve, ?string $reason): TenderApplication
    {
        $tender = $application->tender;
        self::requirePoster($by, $tender);
        abort_unless($application->status === 'pending', 422, __('This application is already decided.'));

        $application->update([
            'status' => $approve ? 'approved' : 'refused',
            'reason' => $reason,
            'decided_by' => $by->id,
            'decided_at' => now(),
        ]);
        self::log($tender, $by, $approve ? 'application_approved' : 'application_refused', $reason);
        MarketNotify::send(
            User::findOrFail($application->applicant_user_id),
            $approve ? __('You may bid on :title', ['title' => $tender->title]) : __('Application refused: :title', ['title' => $tender->title]),
            $reason,
            self::link($tender),
            $approve ? 'success' : 'warning',
        );

        return $application->fresh();
    }

    /** Whether this party may bid: anyone without prequalification, else only an approved party. */
    public static function isQualified(Tender $tender, User $user, ?Company $company): bool
    {
        return ! $tender->rule['prequalification']
            || self::partyApplications($tender, $user, $company)->where('status', 'approved')->exists();
    }

    /**
     * Applications as the poster reads them, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function applications(Tender $tender): array
    {
        $applications = $tender->applications()->with('company', 'user')->latest('id')->get();
        $contents = Content::whereIn('id', $applications->pluck('content_ids')->flatten()->filter())->get()->keyBy('id');

        return $applications->map(fn (TenderApplication $a) => self::showApplication($a, $contents))->all();
    }

    /**
     * @param  Collection<int, Content>  $contents
     * @return array<string, mixed>
     */
    public static function showApplication(TenderApplication $application, $contents): array
    {
        return [
            'id' => $application->id,
            'status' => $application->status,
            'party' => $application->company_id
                ? ['type' => 'company', 'id' => $application->company_id, 'name' => $application->company?->name, 'registry_number' => $application->company?->registry_number]
                : ['type' => 'person', 'id' => $application->user_id, 'name' => $application->user?->verified_name, 'registry_number' => null],
            'note' => $application->note,
            'reason' => $application->reason,
            'files' => collect($application->content_ids ?? [])->map(fn ($id) => $contents->get($id))->filter()
                ->map(fn (Content $c) => ['id' => $c->id, 'name' => $c->file_name, 'size' => (int) $c->file_size])->values()->all(),
            'created_at' => $application->created_at,
            'decided_at' => $application->decided_at,
        ];
    }

    /** Whether the user reads an application's papers: the poster, or whoever applied for that party. */
    public static function canReadApplication(?User $user, TenderApplication $application): bool
    {
        if (! $user) {
            return false;
        }
        if (self::isPoster($user, $application->tender)) {
            return true;
        }

        return $application->company_id
            ? $user->companyMemberships()->where('company_id', $application->company_id)->exists()
            : $application->user_id === $user->id;
    }

    /**
     * The latest application by the user or one of their companies.
     *
     * @return array{id: int, status: string, reason: ?string, company_id: ?int}|null
     */
    private static function myApplication(Tender $tender, User $user): ?array
    {
        $companies = $user->companyMemberships()->pluck('company_id');
        $application = $tender->applications()->where(fn ($q) => $q->where('user_id', $user->id)->orWhereIn('company_id', $companies))->latest('id')->first();

        return $application ? ['id' => $application->id, 'status' => $application->status, 'reason' => $application->reason, 'company_id' => $application->company_id] : null;
    }

    /** The party's applications on this tender: the company's, or the person's own. */
    private static function partyApplications(Tender $tender, User $user, ?Company $company): HasMany
    {
        return $company
            ? $tender->applications()->where('company_id', $company->id)
            : $tender->applications()->whereNull('company_id')->where('user_id', $user->id);
    }

    /**
     * Closes open tenders past their close date (poster and bidders are told) and ends final
     * rounds past their date (back to evaluation). Returns how many tenders moved.
     */
    public static function closeDue(): int
    {
        $moved = 0;
        foreach (Tender::where('status', 'open')->where('closes_at', '<=', now())->get() as $tender) {
            $tender->update(['status' => 'closed']);
            self::log($tender, null, 'closed');
            $people = User::whereIn('id', self::posters($tender))->get()->merge(self::bidders($tender))->unique('id');
            foreach ($people as $user) {
                MarketNotify::send($user, __('Tender closed: :title', ['title' => $tender->title]), null, self::link($tender));
            }
            $moved++;
        }
        foreach (Tender::where('status', 'negotiating')->whereNotNull('final_round_closes_at')->where('final_round_closes_at', '<=', now())->get() as $tender) {
            $tender->update(['status' => 'evaluating']);
            self::log($tender, null, 'final_round_closed');
            Evaluation::apply($tender);
            foreach (User::whereIn('id', self::posters($tender))->get() as $user) {
                MarketNotify::send($user, __('Final offers are in: :title', ['title' => $tender->title]), null, self::link($tender));
            }
            $moved++;
        }

        return $moved;
    }

    /** The poster adds a document to the tender, or to one of its items. */
    public static function addFile(User $by, Tender $tender, ?TenderItem $item, UploadedFile $file): TenderFile
    {
        self::requirePoster($by, $tender);
        abort_unless(in_array($tender->status, ['draft', 'open'], true), 422, __('Documents can be added while the tender is a draft or open.'));
        abort_if($item && $item->tender_id !== $tender->id, 404);
        self::checkFile($file);

        $content = Content::create([
            'physical_path' => $file->store('market/tenders', 'local'),
            'file_name' => $file->getClientOriginalName(),
            'file_extension' => strtolower($file->getClientOriginalExtension()),
            'file_size' => $file->getSize(),
            'is_public' => false,
            'created_by' => $by->id,
        ]);

        return TenderFile::create(['tender_id' => $tender->id, 'tender_item_id' => $item?->id, 'content_id' => $content->id]);
    }

    /** Stops with 422 unless the upload is an allowed document type and size. */
    public static function checkFile(UploadedFile $file): void
    {
        abort_unless(
            $file->isValid() && in_array(strtolower($file->getClientOriginalExtension()), self::FILE_TYPES, true) && $file->getSize() <= self::FILE_MAX_BYTES,
            422,
            __('Send a PDF, image, Word or Excel file, up to 10 MB.'),
        );
    }

    /** Whether the user reads the tender's documents: the poster always, anyone signed in once published. */
    public static function canReadFiles(?User $user, Tender $tender): bool
    {
        return $user !== null && ($tender->status !== 'draft' || self::isPoster($user, $tender));
    }

    /** Whether the user acts for the organizer: the person, or a member who may sign for the company. */
    public static function isPoster(?User $user, Tender $tender): bool
    {
        if (! $user) {
            return false;
        }
        if ($tender->organizer_user_id) {
            return $tender->organizer_user_id === $user->id;
        }

        return MarketAccess::canActFor($user, $tender->organizerCompany, 'sign');
    }

    /** Stops with 403 unless the user acts for the organizer. */
    public static function requirePoster(?User $user, Tender $tender): void
    {
        abort_unless(self::isPoster($user, $tender), 403, __('Only the organizer can do this.'));
    }

    /** Stops with 404 when the user may not see the tender at all: a draft is the poster's alone. */
    public static function requireVisible(?User $user, Tender $tender): void
    {
        abort_if($tender->status === 'draft' && ! self::isPoster($user, $tender), 404);
    }

    /** Bids that count: sent and not replaced or withdrawn. */
    public const CURRENT_BIDS = ['submitted', 'ruled_out'];

    /**
     * Open tenders anyone signed in may bid on, newest first.
     *
     * @param  array{q?: ?string, category_id?: ?int, type?: ?string}  $filters
     */
    public static function listOpen(array $filters): LengthAwarePaginator
    {
        $query = Tender::where('status', 'open')
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(fn ($w) => $w->where('title', 'like', "%{$text}%")
                ->orWhere('code', 'like', "%{$text}%")->orWhere('description', 'like', "%{$text}%")))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type));

        return self::page($query->orderByDesc('published_at')->orderByDesc('id'));
    }

    /** Tenders the user posted: as themselves, or for a company they may sign for. */
    public static function listMine(User $user): LengthAwarePaginator
    {
        $companies = $user->companyMemberships()->where('can_sign', true)->pluck('company_id');

        return self::page(Tender::where(fn ($q) => $q->where('organizer_user_id', $user->id)->orWhereIn('organizer_company_id', $companies))
            ->orderByDesc('updated_at')->orderByDesc('id'));
    }

    /**
     * The user's own bids on this tender: theirs, or their companies' (any member reads them).
     */
    public static function ownBids(User $user, Tender $tender): Builder
    {
        $companies = $user->companyMemberships()->pluck('company_id');

        return $tender->bids()->getQuery()->where(fn ($q) => $q->where('user_id', $user->id)->orWhereIn('company_id', $companies));
    }

    /**
     * The tender as this user sees it. Other parties' bids are never in it: the poster gets a
     * count, and open visibility the lowest price per item, without who offered it.
     *
     * @return array<string, mixed>
     */
    public static function show(Tender $tender, User $user): array
    {
        $tender->loadMissing('items.specs', 'files.content', 'category', 'organizerCompany', 'organizerUser');
        $poster = self::isPoster($user, $tender);
        $files = $tender->files->groupBy(fn (TenderFile $f) => $f->tender_item_id ?? 0);
        $mine = $poster ? null : self::ownBids($user, $tender)->whereNotIn('status', ['superseded'])->latest('id')->first();
        $best = $tender->rule['visibility'] === 'open' && $tender->status === 'open'
            ? BidLine::whereIn('bid_id', $tender->bids()->where('status', 'submitted')->select('id'))->groupBy('tender_item_id')->selectRaw('tender_item_id, min(price) as best')->pluck('best', 'tender_item_id')
            : collect();
        $role = $poster ? 'poster' : ($mine ? 'bidder' : 'visitor');

        return [
            'id' => $tender->id,
            'code' => $tender->code,
            'title' => $tender->title,
            'type' => $tender->type,
            'description' => $tender->description,
            'status' => $tender->status,
            'organizer' => self::organizer($tender),
            'category' => $tender->category ? ['id' => $tender->category->id, 'name' => $tender->category->name] : null,
            'incoterm' => $tender->incoterm,
            'starts_at' => $tender->starts_at,
            'closes_at' => $tender->closes_at,
            'final_round_closes_at' => $tender->final_round_closes_at,
            'round' => $tender->round,
            'rule' => $tender->rule,
            'role' => $role,
            'bid_count' => $poster ? $tender->bids()->whereIn('status', self::CURRENT_BIDS)->count() : null,
            'my_application' => $poster ? null : self::myApplication($tender, $user),
            'my_bid' => $mine ? ['id' => $mine->id, 'status' => $mine->status, 'total' => (float) $mine->total, 'round' => $mine->round, 'submitted_at' => $mine->submitted_at, 'shortlisted' => (bool) $mine->shortlisted] : null,
            'items' => $tender->items->map(fn (TenderItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => $item->quantity === null ? null : (float) $item->quantity,
                'unit' => $item->unit,
                'category_id' => $item->category_id,
                'description' => $item->description,
                'budget' => $poster || $item->show_budget ? ($item->budget === null ? null : (float) $item->budget) : null,
                'show_budget' => $item->show_budget,
                'best' => isset($best[$item->id]) ? (float) $best[$item->id] : null,
                'specs' => $item->specs->map(fn ($s) => [
                    'id' => $s->id, 'label' => $s->label, 'kind' => $s->kind, 'operator' => $s->operator,
                    'value' => $s->value, 'unit' => $s->unit, 'must' => $s->must,
                ])->all(),
                'files' => self::fileList($files->get($item->id, collect())),
            ])->all(),
            'files' => self::fileList($files->get(0, collect())),
            'cancel_reason' => $tender->cancel_reason,
            'awards' => $poster && in_array($tender->status, ['awarded', 'contracted'], true) ? Awards::of($tender) : null,
            'published_at' => $tender->published_at,
            'can' => [
                'edit' => $poster && $tender->status === 'draft',
                'publish' => $poster && $tender->status === 'draft',
                'cancel' => $poster && in_array($tender->status, self::CANCELLABLE, true),
            ],
        ];
    }

    /**
     * The organizer as a party: a company, or a person.
     *
     * @return array{type: string, id: int, name: ?string, registry_number: ?string}
     */
    public static function organizer(Tender $tender): array
    {
        return $tender->organizer_company_id
            ? ['type' => 'company', 'id' => $tender->organizer_company_id, 'name' => $tender->organizerCompany?->name, 'registry_number' => $tender->organizerCompany?->registry_number]
            : ['type' => 'person', 'id' => $tender->organizer_user_id, 'name' => $tender->organizerUser?->verified_name, 'registry_number' => $tender->organizerUser?->registry_number];
    }

    /** Writes a line of the tender's history. */
    public static function log(Tender $tender, ?User $by, string $type, ?string $note = null, ?array $data = null): void
    {
        TenderEvent::create(['tender_id' => $tender->id, 'user_id' => $by?->id, 'type' => $type, 'note' => $note, 'data' => $data, 'created_at' => now()]);
    }

    /** Where the tender opens in the market app. */
    public static function link(Tender $tender): string
    {
        return "/market/tenders/{$tender->id}";
    }

    /**
     * Everyone who bid or applied: the people who sent them.
     *
     * @return list<User>
     */
    public static function bidders(Tender $tender): array
    {
        $ids = $tender->bids()->pluck('submitted_by')->merge($tender->applications()->pluck('applicant_user_id'))->unique();

        return User::whereIn('id', $ids)->get()->all();
    }

    /** Fee and security payments on this tender or its bids. */
    public static function payments(Tender $tender): Builder
    {
        $bids = $tender->bids()->pluck('id');

        return Payment::where(fn ($q) => $q->where(fn ($t) => $t->where('subject_type', $tender->getMorphClass())->where('subject_id', $tender->id))
            ->orWhere(fn ($b) => $b->where('subject_type', (new Bid)->getMorphClass())->whereIn('subject_id', $bids)));
    }

    /** Bid securities go back to the bidders (test money until a licensed partner holds them). */
    public static function refundSecurities(Tender $tender): void
    {
        self::payments($tender)->where('kind', 'security')->where('status', 'paid')->update(['status' => 'refunded']);
    }

    /**
     * Cross-field checks the request rules can't make: dates, weights, split, numeric specs.
     *
     * @param  array<string, mixed>  $data
     */
    private static function check(array $data): void
    {
        $errors = [];
        if (! empty($data['starts_at']) && ! empty($data['closes_at']) && strtotime((string) $data['closes_at']) <= strtotime((string) $data['starts_at'])) {
            $errors['closes_at'] = __('The close date must be after the start date.');
        }
        if (! empty($data['closes_at']) && strtotime((string) $data['closes_at']) <= time()) {
            $errors['closes_at'] = __('The close date must be in the future.');
        }
        if (isset($data['rule'])) {
            $rule = self::rule((array) $data['rule']);
            if ($rule['award'] === 'best_value' && array_sum($rule['weights']) !== 100) {
                $errors['rule.weights'] = __('The weights for price, lead time and quality must add up to 100.');
            }
            if ($rule['split'] === 'quantity' && ! $rule['partial']) {
                $errors['rule.split'] = __('Splitting by quantity needs partial quantities allowed.');
            }
        }
        foreach ($data['items'] ?? [] as $i => $item) {
            foreach ($item['specs'] ?? [] as $j => $spec) {
                if (($spec['kind'] ?? 'text') === 'number' && (empty($spec['operator']) || ! is_numeric($spec['value'] ?? null))) {
                    $errors["items.{$i}.specs.{$j}.value"] = __('A number spec needs a comparison and a number.');
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Writes the items and their specs in the order sent. */
    private static function fillItems(Tender $tender, array $items): void
    {
        foreach (array_values($items) as $i => $data) {
            $item = $tender->items()->create([
                'position' => $i + 1,
                'name' => $data['name'],
                'quantity' => $data['quantity'] ?? null,
                'unit' => $data['unit'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'description' => $data['description'] ?? null,
                'budget' => $data['budget'] ?? null,
                'show_budget' => (bool) ($data['show_budget'] ?? false),
            ]);
            foreach (array_values($data['specs'] ?? []) as $j => $spec) {
                $number = ($spec['kind'] ?? 'text') === 'number';
                $item->specs()->create([
                    'position' => $j + 1,
                    'label' => $spec['label'],
                    'kind' => $number ? 'number' : 'text',
                    'operator' => $number ? $spec['operator'] : null,
                    'value' => $spec['value'] ?? null,
                    'unit' => $spec['unit'] ?? null,
                    'must' => (bool) ($spec['must'] ?? false),
                ]);
            }
        }
    }

    /** A page of tender summaries. */
    private static function page(Builder $query): LengthAwarePaginator
    {
        return $query->with('organizerCompany', 'organizerUser', 'category')->withCount('items')->paginate(20)
            ->through(fn (Tender $t) => [
                'id' => $t->id,
                'code' => $t->code,
                'title' => $t->title,
                'type' => $t->type,
                'status' => $t->status,
                'organizer' => self::organizer($t),
                'category' => $t->category?->name,
                'items_count' => $t->items_count,
                'visibility' => $t->rule['visibility'],
                'closes_at' => $t->closes_at,
                'updated_at' => $t->updated_at,
            ]);
    }

    /** @return list<array{id: int, name: string, size: int}> */
    private static function fileList($files): array
    {
        return $files->map(fn (TenderFile $f) => ['id' => $f->id, 'name' => $f->content?->file_name, 'size' => (int) $f->content?->file_size])->values()->all();
    }
}
