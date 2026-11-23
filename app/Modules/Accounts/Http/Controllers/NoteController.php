<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PartyRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\NoteAccounts;
use App\Modules\Accounts\Services\NoteLineBuilder;
use App\Modules\Accounts\Services\NotePosting;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Support\TradeCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ ডেবিট ও ক্রেডিট নোট — তিন ভাগ, নিজের ফর্ম (মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬: *"আমি ওকে করে দিলাম"*)।
 *
 * ⓘ ক্রেডিট নোট (CN, মূসক-৬.৭), গ্রাহকের ডেবিট নোট (DN, মূসক-৬.৮), সরবরাহকারীর ডেবিট নোট (DN)। চাবি একই
 * (`accounts.note.view/manage`); তালিকা [[NoteListController]]-এ, ছাপা [[NotePrintController]]-এ। ⓘ পুরনো দুই ঠিকানা
 * (`/notes`, `/notes/create`) দিক ধরে নতুন পাতায় যায় — বুকমার্ক ভাঙে না।
 */
class NoteController extends Controller implements HasMiddleware
{
    /** ⓘ কাগজের রং — ক্রেডিট নোট সবুজ, ডেবিট নোট গোলাপি, গ্রাহকের ডেবিট নোট অ্যাম্বার (নকশা v5) */
    public const TONES = [Note::TYPE_CN => 'green', Note::TYPE_DN => 'rose', Note::TYPE_CDN => 'amber'];

    public function __construct(
        private readonly NoteService $notes,
        private readonly PartyRegistry $parties,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.note.view', only: ['legacyIndex', 'show', 'bill']),
            new Middleware('can:accounts.note.manage', only: ['create', 'legacyCreate', 'store', 'confirm', 'cancel']),
        ];
    }

    /** ⭐ পুরনো তালিকার ঠিকানা — দিক ধরে নতুন পাতায় (`?direction=debit&kind=customer` → গ্রাহকের ডেবিট নোট) */
    public function legacyIndex(Request $request): RedirectResponse
    {
        $type = self::typeFor((string) $request->query('direction', Note::CREDIT), (string) $request->query('kind', ''));

        return redirect()->route('accounts.note.'.$type.'.index', $request->except(['direction', 'kind']), 301);
    }

    /** ⭐ পুরনো "নতুন নোট" ঠিকানা — দিক আর পক্ষের ধরন ধরে */
    public function legacyCreate(Request $request): RedirectResponse
    {
        $kind = (string) $request->query('kind', '');
        $type = self::typeFor((string) $request->query('direction', Note::CREDIT), $kind);
        $query = $request->except(['direction']);

        if (! in_array($kind, Note::TYPE_KINDS[$type], true)) {
            unset($query['kind']);
        }

        return redirect()->route('accounts.note.'.$type.'.create', $query, 301);
    }

    /** দিক + পক্ষের ধরন → ভাগ (পুরনো ঠিকানা আর পুরনো নোটের একই নিয়ম, [[Note::type()]]) */
    public static function typeFor(string $direction, string $kind): string
    {
        if ($direction !== Note::DEBIT) {
            return Note::TYPE_CN;
        }

        return $kind === Note::KIND_CUSTOMER ? Note::TYPE_CDN : Note::TYPE_DN;
    }

    /**
     * নতুন নোটের পর্দা — এক সারির মাথা, সারির ছক, ডানে সারাংশ (নকশা v5)।
     *
     * ⓘ পক্ষের ধরন বদলালে পাতা নতুন ধরনের পক্ষ নিয়ে আবার খোলে (`?kind=`); বিলের সারি আসে [[bill()]] থেকে।
     */
    public function create(Request $request): View
    {
        $type = $this->typeOf($request);
        $kinds = Note::TYPE_KINDS[$type];
        $kind = in_array((string) $request->query('kind'), $kinds, true) ? (string) $request->query('kind') : $kinds[0];
        $accounts = app(NoteAccounts::class);
        $catalog = app(TradeCatalog::class);
        $builder = app(NoteLineBuilder::class);
        $productsAllowed = $builder->productsAllowed($type, $kind);

        return view('accounts::note.create', [
            'menu' => $this->menu->forUser($request->user()),
            'type' => $type,
            'tone' => self::TONES[$type],
            'kinds' => array_map(fn (string $k) => ['key' => $k, 'label' => __('accounts::note.kind_'.$k),
                'url' => route('accounts.note.'.$type.'.create', ['kind' => $k])], $kinds),
            'kind' => $kind,
            'parties' => $accounts->partyOptions($kind),
            'partyId' => $request->integer('party_id') ?: null,
            'billNo' => (string) $request->query('bill', ''),
            'reasons' => Note::REASONS,
            'numberPreview' => app(NumberSeriesEngine::class)->upcoming(Note::TYPES[$type] === Note::CREDIT ? 'CN' : 'DN'),
            'approver' => $this->approverFor($type),
            'productsAllowed' => $productsAllowed,
            'products' => $productsAllowed ? $catalog->products() : [],
            'taxes' => $catalog->taxes(),
            'accounts' => $builder->accountChoices($type),
            'billUrl' => route('accounts.note.bill'),
            'storeUrl' => route('accounts.note.store'),
            'listUrl' => route('accounts.note.'.$type.'.index'),
            'now' => Carbon::now(),
            'binMissing' => self::companyBinMissing(),
            'narrationRequired' => (bool) app(SettingsService::class)->get('accounts.require_narration', true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // ⓘ পুরনো এক-অঙ্কের পথ — API আর পুরনো ফর্ম (দিক + অঙ্ক)
        if (! $request->has('note_type')) {
            return $this->storeOld($request);
        }

        $lines = $request->input('lines');

        if (! is_array($lines) && is_string($request->input('lines_json'))) {
            $lines = json_decode((string) $request->input('lines_json'), true);
        }

        $request->merge(['lines' => is_array($lines) ? array_values($lines) : []]);

        $data = $request->validate([
            'note_type' => ['required', Rule::in(array_keys(Note::TYPES))],
            'party_kind' => ['nullable', Rule::in(array_keys(Note::KINDS))],
            'party_id' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', Rule::in(Note::REASONS)],
            'against_no' => ['nullable', 'string', 'max:64'],
            'supplier_note_no' => ['nullable', 'string', 'max:64'],
            'supplier_note_date' => ['nullable', 'date', 'before_or_equal:today'],
            // ⭐ ন্যারেশন — ভাউচারের সেটিং মেনে (`accounts.require_narration`)
            'narration' => [(bool) app(SettingsService::class)->get('accounts.require_narration', true) ? 'required' : 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['array'],
            'submit' => ['nullable', 'string'],
        ]);

        // ⓘ তারিখ আজ, তালাবদ্ধ — নকশার "তারিখ · সময়" ঘর (পিছনের তারিখের পথ আলাদা নিয়মের)
        $data['trx_date'] = Carbon::today()->toDateString();

        $note = $this->notes->create($data);

        // ⭐ "অনুমোদনে পাঠান" — খসড়া বানিয়েই পাকা করার চেষ্টা: সই লাগলে অনুরোধ যায়, না লাগলে খাতায় ওঠে
        if ($request->input('submit') === 'send') {
            $note = $this->notes->confirm($note);

            return redirect()->route('accounts.note.show', $note)
                ->with('saved', $note->isDraft() ? __('accounts::note.awaiting_signature') : __('accounts::note.confirmed'));
        }

        return redirect()->route('accounts.note.show', $note)->with('saved', __('accounts::note.saved'));
    }

    /** ⓘ পুরনো এক-অঙ্কের ফর্ম আর API — আগের যাচাই হুবহু */
    private function storeOld(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(Note::DIRECTIONS)],
            'party_kind' => ['nullable', Rule::in(array_keys(Note::KINDS))],
            'party_id' => ['required', 'integer', 'min:1'],
            'control_account_id' => ['nullable', 'integer', 'min:1'],
            'other_account_id' => ['nullable', 'integer', 'min:1'],
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', Rule::in(Note::REASONS)],
            'against_no' => ['nullable', 'string', 'max:64'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        unset($data['party_type']);

        $note = $this->notes->create($data);

        return redirect()->route('accounts.note.show', $note)->with('saved', __('accounts::note.saved'));
    }

    /**
     * ⭐ "⇣ মূল বিল থেকে আনুন" — এই পক্ষের এই নম্বরের পাকা বিল আর তার সারি (JSON)।
     */
    public function bill(Request $request): JsonResponse
    {
        $type = (string) $request->query('type', '');
        $kind = (string) $request->query('kind', '');
        $found = $this->notes->billFor($type, $kind, $request->integer('party_id'), (string) $request->query('no', ''));

        if ($found === null) {
            return response()->json(['message' => __('accounts::note.against_not_found', ['no' => (string) $request->query('no', '')])], 404);
        }

        return response()->json($found);
    }

    public function show(Request $request, Note $note): View
    {
        $note->load('lines.account');
        $pair = [[$note->party_type, (int) $note->party_id]];
        $key = $note->party_type.':'.$note->party_id;
        $accounts = app(NoteAccounts::class)->of($note);
        $target = $this->notes->targetOf($note);

        return view('accounts::note.show', [
            'menu' => $this->menu->forUser($request->user()),
            'note' => $note,
            'type' => $note->type(),
            'tone' => self::TONES[$note->type()],
            'partyName' => $this->parties->labelsOf($pair)[$key] ?? '—',
            'partyRoute' => $this->parties->routesOf($pair)[$key] ?? null,
            'accounts' => $accounts,
            'postings' => $this->postingPreview($note, $accounts['control']),
            'billIssuedAt' => $target instanceof \App\Core\Contracts\NoteTargetLines ? $target->noteTargetIssuedAt() : null,
            'returnLinks' => $this->returnLinks($note),
            'approver' => $this->approverFor($note->type()),
            'binMissing' => self::companyBinMissing(),
            'listUrl' => route('accounts.note.'.$note->type().'.index'),
        ]);
    }

    public function confirm(Note $note): RedirectResponse
    {
        $note = $this->notes->confirm($note);

        // ⓘ সইয়ের জন্য থামলে নোটটা খসড়াই থাকে — "পাকা হলো" বলা মিথ্যা হত ([[AccountsSignature]])
        return back()->with('saved', $note->isDraft() ? __('accounts::note.awaiting_signature') : __('accounts::note.confirmed'));
    }

    public function cancel(Request $request, Note $note): RedirectResponse
    {
        $data = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:500'],
        ]);

        // ⭐ পাকা নোট — উল্টো কাগজ (বাতিল-নোট), নিজের নম্বরে (মালিকের সংস্করণ ২, ৪ অক্টোবর ২০২৬); খসড়া আগের মতো
        if ($note->isConfirmed()) {
            $paper = app(\App\Modules\Accounts\Services\AccountsReversalService::class)
                ->reverseNote($note, $request->user(), $data['cancel_reason']);

            return back()->with('saved', __('accounts::reversal.saved', ['no' => $note->document_no, 'rev' => $paper->document_no]));
        }

        $this->notes->cancel($note, $data['cancel_reason']);

        return back()->with('saved', __('accounts::note.cancelled'));
    }

    /**
     * ⓘ অনুমোদনকারী — অনুমোদনের ছক থেকে, কেবল পড়ার জন্য (কোনো বাছাই নয়)। ক্রেডিট নোট মালিকের ছাড়ের ছকে।
     *
     * @return array{who: string, levels: int}|null
     */
    private function approverFor(string $type): ?array
    {
        $engine = app(ApprovalEngine::class);

        return $type === Note::TYPE_CN
            ? $engine->firstSigner(NoteService::DISCOUNT_MODULE, NoteService::DISCOUNT_ACTION, null, class_basename(Note::class))
            : $engine->firstSigner(AccountsSignature::MODULE, AccountsSignature::NOTE, null, class_basename(Note::class));
    }

    /**
     * ⭐ "খাতায় যা উঠবে" — নোট নিজে যা তোলে, খাতের কোড আর নামসহ।
     *
     * @return list<array{code: string, name: string, party: bool, debit: string, credit: string}>
     */
    private function postingPreview(Note $note, \App\Modules\Accounts\Models\Account $control): array
    {
        if ($note->lines->isEmpty()) {
            return [];
        }

        $rows = app(NotePosting::class)->lines($note, $control);
        $names = \App\Modules\Accounts\Models\Account::query()->whereIn('id', array_column($rows, 'account_id'))->get()->keyBy('id');

        return array_map(fn (array $r) => [
            'code' => (string) ($names[$r['account_id']]->code ?? ''),
            'name' => (string) ($names[$r['account_id']]?->name() ?? ''),
            'party' => isset($r['party_id']),
            'debit' => (string) ($r['debit'] ?? '0'),
            'credit' => (string) ($r['credit'] ?? '0'),
        ], $rows);
    }

    /** @return list<array{no: string, url: string|null}> */
    private function returnLinks(Note $note): array
    {
        $map = app(\App\Core\Engines\Drill\DrillResolver::class)->map();

        return $note->lines->whereNotNull('return_id')
            ->unique(fn ($l) => $l->return_type.':'.$l->return_id)
            ->map(function ($l) use ($map) {
                $class = $map[(string) $l->return_type] ?? null;
                $paper = is_string($class) ? $class::query()->withoutGlobalScope('user-branch')->find($l->return_id) : null;
                [$route, $params] = $paper instanceof \App\Core\Contracts\Drillable ? $paper->drillRoute() : [null, []];

                return ['no' => (string) $l->return_no, 'url' => $route !== null && \Illuminate\Support\Facades\Route::has($route) ? route($route, $params) : null];
            })->values()->all();
    }

    private function typeOf(Request $request): string
    {
        $type = (string) $request->route('type', Note::TYPE_CN);

        return array_key_exists($type, Note::TYPES) ? $type : Note::TYPE_CN;
    }

    /**
     * ⛔ কোম্পানির BIN নেই — মূসক-৬.৭/৬.৮-এ নিজের BIN বাধ্যতামূলক (মূসক আইন ২০১২, ধারা ৫২(১))। ⓘ সতর্কতা কেবল, থামায় না
     * (মালিকের নিয়ম "সতর্কতা কাজ থামায় না"); দিন শেষের তালিকাতেও ওঠে ([[DayChecklist]])। লাইভে ADI-র BIN খালি।
     */
    public static function companyBinMissing(): bool
    {
        return blank(Company::query()->whereKey(CompanyContext::id())->value('bin'));
    }
}
