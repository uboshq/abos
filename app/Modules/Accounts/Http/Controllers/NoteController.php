<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Concerns\GrandTotals;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PartyRegistry;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\NoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ডেবিট ও ক্রেডিট নোট — মানচিত্র §৭।
 *
 * ── ⚠️ একটা পর্দা, দুইটা দিক ────────────────────────────────────────
 * ট্যাব দিয়ে ভাগ, আলাদা দুইটা পর্দা নয়। ⓘ কাজটা এক — "টাকার অঙ্কটা
 * ভুল ছিল, শোধরাও" — কেবল কাকে দেওয়া হচ্ছে সেটা আলাদা। ⛔ দুইটা পর্দা
 * বানালে একদিন একটায় ভ্যাটের ঘর যোগ হত, অন্যটায় না।
 */
class NoteController extends Controller implements HasMiddleware
{
    use GrandTotals;
    use SortsLists;

    public function __construct(
        private readonly NoteService $notes,
        private readonly PartyRegistry $parties,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.note.view', only: ['index', 'show']),
            new Middleware('can:accounts.note.manage', only: ['create', 'store', 'confirm', 'cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $direction = in_array($request->query('direction'), Note::DIRECTIONS, true)
            ? (string) $request->query('direction')
            : Note::CREDIT;

        /* ⭐ পক্ষের নাম, কোড আর পয়েন্ট দিয়েও খোঁজা — মালিক, ৩ অক্টোবর ২০২৬ ([[PartyRegistry::matching()]]) */
        $parties = $request->filled('q') ? $this->parties->matching((string) $request->query('q'), $this->parties->types()) : [];

        $list = Note::query()
            ->ofDirection($direction)
            ->when($request->query('q'), fn ($q, $term) => $q
                ->where(function ($w) use ($term, $parties) {
                    $w->where('document_no', 'like', "%{$term}%")
                        ->orWhere('against_no', 'like', "%{$term}%")
                        ->orWhere('narration', 'like', "%{$term}%");

                    foreach ($parties as $type => $ids) {
                        $w->orWhere(fn ($p) => $p->where('party_type', $type)->whereIn('party_id', $ids));
                    }
                }));

        $this->applySort($list, $request, $this->sorts($list));

        // ⭐ সর্বমোট — ছাঁকা তালিকার সব পাতা মিলে ([[GrandTotals]]); পাতা ভাঙার আগে, কারণ paginate() কোয়েরিতে সীমা বসায়
        $grand = $this->grandTotals($list, ['total' => 't.total']);
        $rows = $list->paginate(50)->withQueryString();

        /*
         * ⓘ পক্ষের নাম একবারে — সারি প্রতি একটা প্রশ্ন করলে পঞ্চাশ সারির
         * পাতায় পঞ্চাশটা হত ([[PartyRegistry::labelsOf()]])।
         */
        $pairs = collect($rows->items())->map(fn (Note $n) => [$n->party_type, (int) $n->party_id]);

        return view('accounts::note.index', [
            'menu' => $this->menu->forUser($request->user()),
            'direction' => $direction,
            'rows' => $rows,
            'grand' => $grand,
            'names' => $this->parties->labelsOf($pairs),
            'places' => $this->parties->placesOf($pairs),
            'sortOptions' => $this->sortLabels(),

            // ⭐ নামটা যেন তাঁর নিজের পাতায় নিয়ে যায় — মালিকের নিয়ম
            'routes' => $this->parties->routesOf($pairs),
            'counts' => [
                Note::CREDIT => Note::query()->ofDirection(Note::CREDIT)->count(),
                Note::DEBIT => Note::query()->ofDirection(Note::DEBIT)->count(),
            ],
        ]);
    }

    /**
     * সাজানো — তারিখ, অঙ্ক, পক্ষ, পয়েন্ট।
     *
     * ⓘ পক্ষ আর পয়েন্টের নাম অন্য মডিউলে, তাই সেগুলো দিয়ে সাজাতে ছাঁকা তালিকার সারিগুলোর নাম একবারে এনে ক্রম
     * বানানো হয় ([[PartyRegistry::labelsOf()]], [[placesOf()]]) — নোট অল্প, প্রতিটা পক্ষে আলাদা জোড় নয়।
     *
     * @return array<string, \Closure>
     */
    private function sorts(\Illuminate\Database\Eloquent\Builder $list): array
    {
        $byNames = function ($query, bool $places) use ($list) {
            $rows = (clone $list)->get(['id', 'party_type', 'party_id']);
            $pairs = $rows->map(fn (Note $n) => [$n->party_type, (int) $n->party_id]);
            $names = $places ? $this->parties->placesOf($pairs) : $this->parties->labelsOf($pairs);

            $ordered = $rows
                ->sortBy(fn (Note $n) => mb_strtolower($names[$n->party_type.':'.$n->party_id] ?? "\u{FFFF}"))
                ->pluck('id')->all();

            $ordered === []
                ? $query->orderByDesc('id')
                : $query->orderByRaw('FIELD(id, '.implode(',', array_map('intval', $ordered)).')');
        };

        return [
            'latest' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'amount' => fn ($q) => $q->orderByDesc('total')->orderByDesc('id'),
            'party' => fn ($q) => $byNames($q, false),
            'point' => fn ($q) => $byNames($q, true),
        ];
    }

    /** @return array<string, string> */
    private function sortLabels(): array
    {
        return [
            'latest' => __('accounts::sort.latest'),
            'oldest' => __('accounts::sort.oldest'),
            'amount' => __('accounts::sort.amount'),
            'party' => __('accounts::sort.party'),
            'point' => __('accounts::sort.point'),
        ];
    }

    public function create(Request $request): View
    {
        $direction = in_array($request->query('direction'), Note::DIRECTIONS, true)
            ? (string) $request->query('direction')
            : Note::CREDIT;

        return view('accounts::note.create', [
            'menu' => $this->menu->forUser($request->user()),
            'direction' => $direction,

            /*
             * ⚠️ ক্রেডিট নোট যায় গ্রাহকের কাছে, ডেবিট নোট সরবরাহকারীর —
             * তাই পক্ষের তালিকাটাও দিক ধরে ছাঁকা। ⓘ না ছাঁকলে কেউ
             * গ্রাহকের নামে ডেবিট নোট কেটে ফেলতেন, আর সেটা বইয়ে
             * প্রদেয়তে গিয়ে বসত — যেখানে গ্রাহকের কোনো জায়গা নেই।
             */
            'parties' => collect($this->parties->forPicker())
                ->firstWhere('type', $direction === Note::CREDIT ? 'customer' : 'supplier')['options'] ?? [],
            'reasons' => Note::REASONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(Note::DIRECTIONS)],
            'party_id' => ['required', 'integer', 'min:1'],
            /* ⛔ কাল-পরশুর তারিখে নোট কাটা যায় না — বই ভবিষ্যৎ চেনে না */
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', Rule::in(Note::REASONS)],
            'against_no' => ['nullable', 'string', 'max:64'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        // ⓘ পক্ষের ধরনটা দিক থেকেই আসে, ফর্ম থেকে নয় — নাহলে বদলে পাঠানো যেত
        $data['party_type'] = $data['direction'] === Note::CREDIT ? 'customer' : 'supplier';

        $note = $this->notes->create($data);

        return redirect()
            ->route('accounts.note.show', $note)
            ->with('saved', __('accounts::note.saved'));
    }

    public function show(Request $request, Note $note): View
    {
        return view('accounts::note.show', [
            'menu' => $this->menu->forUser($request->user()),
            'note' => $note,
            'partyName' => $this->parties->labelsOf([[$note->party_type, (int) $note->party_id]])
                [$note->party_type.':'.$note->party_id] ?? '—',
            'partyRoute' => $this->parties->routesOf([[$note->party_type, (int) $note->party_id]])
                [$note->party_type.':'.$note->party_id] ?? null,
        ]);
    }

    public function confirm(Note $note): RedirectResponse
    {
        $this->notes->confirm($note);

        return back()->with('saved', __('accounts::note.confirmed'));
    }

    public function cancel(Request $request, Note $note): RedirectResponse
    {
        $data = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:500'],
        ]);

        $this->notes->cancel($note, $data['cancel_reason']);

        return back()->with('saved', __('accounts::note.cancelled'));
    }
}
