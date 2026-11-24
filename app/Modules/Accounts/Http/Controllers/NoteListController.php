<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Concerns\GrandTotals;
use App\Core\Concerns\SortsLists;
use App\Core\ListFilter\ChoiceFacet;
use App\Core\ListFilter\DateRangeFacet;
use App\Core\ListFilter\FilterSet;
use App\Core\ListFilter\Panel;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PartyRegistry;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\NoteLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * ⭐ নোটের তিন তালিকা — ক্রেডিট নোট (`cn`), গ্রাহকের ডেবিট নোট (`cdn`), সরবরাহকারীর ডেবিট নোট (`dn`); মালিকের নকশা v5,
 * ২৩ নভেম্বর ২০২৬। ⓘ ভাগটা রুটের ডিফল্ট (`type`) — প্রতিটা ভাগ নিজের মেনু-সারি, ট্যাব নেই।
 *
 * ⛔ মোটে কেবল পাকা নোট (নিশ্চিত/বন্ধ) — খসড়া আর অনুমোদনের অপেক্ষা আলাদা লাইনে, সংখ্যা আর টাকা ([[CollectionController::index()]]-এর
 * একই ছাঁচ)। টাকা = নোটের সর্বমোট: নিজের সারি + ফেরতের কাগজের ভাগ ([[Note::grandTotal()]])।
 * ⓘ ছাঁকনি ডান পাশে, কেন্দ্রীয় ইঞ্জিনে ([[FilterSet]], [[Panel]]) — অবস্থা, তারিখ, পক্ষ, কারণ।
 */
final class NoteListController extends Controller implements HasMiddleware
{
    use GrandTotals;
    use SortsLists;

    /** ⓘ নোটের সর্বমোট — যোগফলের SQL */
    private const MONEY = 'acc_notes.total + acc_notes.returned_total';

    public function __construct(
        private readonly PartyRegistry $parties,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.note.view')];
    }

    public function __invoke(Request $request): View
    {
        $type = (string) $request->route('type');
        abort_unless(array_key_exists($type, Note::TYPES), 404);

        $base = fn () => $this->listed($request, $type);
        $filters = $this->filters($base, $type);
        $query = $base();
        $filters->apply($query, $request);

        $this->applySort($query, $request, $this->sorts($query));

        /*
         * ⛔ মোট কেবল পাকা কাগজের — খসড়া, অপেক্ষমাণ, বাতিল মোটে নয় (fe ২৪ অক্টোবর ২০২৬-এর নিয়ম)। ⓘ অপেক্ষমাণ = খসড়া + অনুমোদনের
         * খোলা সারি; বাকি খসড়া আলাদা লাইনে।
         */
        $totalled = (clone $query)->reorder();
        $posted = (clone $totalled)->whereIn('acc_notes.status', DocumentStatus::POSTED);
        $drafts = (clone $totalled)->where('acc_notes.status', DocumentStatus::DRAFT);
        $waiting = (clone $drafts)->whereExists($this->signing());
        $plain = (clone $drafts)->whereNotExists($this->signing());

        $grand = $this->grandTotals(clone $posted, ['grand_total' => 't.total + t.returned_total']);
        $rows = $query->paginate(\App\Core\Support\PerPage::of())->withQueryString();

        $pairs = collect($rows->items())->map(fn (Note $n) => [$n->party_type, (int) $n->party_id]);
        $ids = collect($rows->items())->map(fn (Note $n) => (int) $n->id)->all();

        return view('accounts::note.index', [
            'menu' => $this->menu->forUser($request->user()),
            'type' => $type,
            'rows' => $rows,
            'grand' => $grand,
            'totals' => [
                'rows' => (clone $totalled)->count(),
                'money' => (string) ($grand['grand_total'] ?? '0'),
                'drafts' => ['count' => (clone $plain)->count(), 'money' => $this->sum($plain)],
                'waiting' => ['count' => (clone $waiting)->count(), 'money' => $this->sum($waiting)],
            ],
            'names' => $this->parties->labelsOf($pairs),
            'places' => $this->parties->placesOf($pairs),
            'routes' => $this->parties->routesOf($pairs),
            'returns' => $this->returnsOf($ids),
            'waitingIds' => $ids === [] ? []
                : (clone $waiting)->whereIn('acc_notes.id', $ids)->pluck('acc_notes.id')->map(fn ($id) => (int) $id)->all(),
            'filters' => Panel::of($filters, $base, $request),
            'sortOptions' => $this->sortLabels(),
            'q' => $request->query('q'),
        ]);
    }

    /**
     * তালিকার নিজের কোয়েরি — ভাগ আর খোঁজা, ছাঁকনি ছাড়া (প্যানেলের সংখ্যা এখান থেকেই)।
     *
     * @return Builder<Note>
     */
    private function listed(Request $request, string $type): Builder
    {
        $term = trim((string) $request->query('q', ''));
        /* ⭐ পক্ষের নাম, কোড আর পয়েন্ট দিয়েও খোঁজা ([[PartyRegistry::matching()]]) */
        $parties = $term !== '' ? $this->parties->matching($term, $this->parties->types()) : [];

        return Note::query()
            ->where('acc_notes.note_type', $type)
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term, $parties) {
                $w->where('acc_notes.document_no', 'like', "%{$term}%")
                    ->orWhere('acc_notes.against_no', 'like', "%{$term}%")
                    ->orWhere('acc_notes.supplier_note_no', 'like', "%{$term}%")
                    ->orWhere('acc_notes.narration', 'like', "%{$term}%")
                    // ⓘ ফেরতের কাগজের নম্বর (SR/PR) দিয়েও
                    ->orWhereIn('acc_notes.id', NoteLine::query()->where('return_no', 'like', "%{$term}%")->select('note_id'));

                foreach ($parties as $partyType => $partyIds) {
                    $w->orWhere(fn ($p) => $p->where('acc_notes.party_type', $partyType)->whereIn('acc_notes.party_id', $partyIds));
                }
            }));
    }

    /** ⓘ অনুমোদনের খোলা সারি আছে এমন খসড়া — "অপেক্ষমাণ" */
    private function signing(): \Closure
    {
        $morph = (new Note)->getMorphClass();

        return fn ($q) => $q->select(DB::raw(1))->from('approvals')
            ->where('approvals.approvable_type', $morph)
            ->whereColumn('approvals.approvable_id', 'acc_notes.id')
            ->where('approvals.status', Approval::PENDING);
    }

    private function sum(Builder $query): string
    {
        return (string) ((clone $query)->sum(DB::raw(self::MONEY)) ?? '0');
    }

    /**
     * ⓘ নোটপ্রতি যুক্ত ফেরতের কাগজ (SR/PR) — এক কোয়েরিতে, পাতার সারিগুলোর।
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    private function returnsOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (NoteLine::query()->whereIn('note_id', $ids)->whereNotNull('return_no')->where('return_no', '<>', '')
            ->orderBy('line_no')->get(['note_id', 'return_no']) as $line) {
            $out[(int) $line->note_id][(string) $line->return_no] = true;
        }

        return array_map(fn (array $nos) => array_map('strval', array_keys($nos)), $out);
    }

    /**
     * ছাঁকনি — অবস্থা, তারিখ, পক্ষ, কারণ।
     *
     * @param  \Closure(): Builder  $base
     */
    private function filters(\Closure $base, string $type): FilterSet
    {
        $stages = [
            'posted' => ['label' => __('accounts::note_list.stage_posted'), 'tone' => 'green',
                'apply' => fn (Builder $q) => $q->whereIn('acc_notes.status', DocumentStatus::POSTED)],
            'waiting' => ['label' => __('accounts::note_list.stage_waiting'), 'tone' => 'amber',
                'apply' => fn (Builder $q) => $q->where('acc_notes.status', DocumentStatus::DRAFT)->whereExists($this->signing())],
            'draft' => ['label' => __('accounts::note_list.stage_draft'), 'tone' => 'slate',
                'apply' => fn (Builder $q) => $q->where('acc_notes.status', DocumentStatus::DRAFT)->whereNotExists($this->signing())],
            'cancelled' => ['label' => __('accounts::note_list.stage_cancelled'), 'tone' => 'red',
                'apply' => fn (Builder $q) => $q->where('acc_notes.status', DocumentStatus::CANCELLED)],
        ];

        $reasons = [];

        foreach (Note::REASONS as $reason) {
            $reasons[$reason] = ['label' => __('accounts::note.reason_'.$reason),
                'apply' => fn (Builder $q) => $q->where('acc_notes.reason', $reason)];
        }

        return new FilterSet([
            (new ChoiceFacet('stage', __('accounts::note_list.filter_stage'), $stages))->look('blue', '●'),
            (new DateRangeFacet('date', __('accounts::note_list.filter_date'), function (Builder $q, ?string $from, ?string $to): void {
                if ($from !== null) {
                    $q->where('acc_notes.trx_date', '>=', $from);
                }
                if ($to !== null) {
                    $q->where('acc_notes.trx_date', '<=', $to);
                }
            }, fromKey: 'from', toKey: 'to'))->look('sky', '📅')->onTheBar(),
            (new ChoiceFacet('party', __('accounts::note_list.filter_party_'.$type), self::partyOptions($this->parties, $base, 'acc_notes')))->look('teal', '◈'),
            (new ChoiceFacet('reason', __('accounts::note_list.filter_reason'), $reasons))->look('violet', '✎'),
        ]);
    }

    /**
     * ⓘ পক্ষের ঘর — এই তালিকায় যাঁদের কাগজ আছে কেবল তাঁরা, নামে সাজানো (চাবি `ধরন:আইডি`)। ভাউচারের তালিকাও এটাই ডাকে।
     *
     * @param  \Closure(): Builder  $base
     * @return array<string, array{label: string, apply: \Closure(Builder): mixed}>
     */
    public static function partyOptions(PartyRegistry $parties, \Closure $base, string $table): array
    {
        $pairs = $base()->reorder()->toBase()->select($table.'.party_type', $table.'.party_id')->distinct()->get()
            ->map(fn ($r) => [(string) $r->party_type, (int) $r->party_id]);
        $names = $parties->labelsOf($pairs);
        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        $options = [];

        foreach ($names as $key => $label) {
            [$partyType, $partyId] = explode(':', (string) $key, 2) + [1 => '0'];
            $options[(string) $key] = ['label' => (string) $label,
                'apply' => fn (Builder $q) => $q->where($table.'.party_type', $partyType)->where($table.'.party_id', (int) $partyId)];
        }

        return $options;
    }

    /**
     * সাজানো — তারিখ, অঙ্ক, পক্ষ, পয়েন্ট (আগের তালিকার একই নিয়ম)।
     *
     * @return array<string, \Closure>
     */
    private function sorts(Builder $list): array
    {
        $byNames = function ($query, bool $places) use ($list) {
            $rows = (clone $list)->reorder()->get(['acc_notes.id', 'acc_notes.party_type', 'acc_notes.party_id']);
            $pairs = $rows->map(fn (Note $n) => [$n->party_type, (int) $n->party_id]);
            $names = $places ? $this->parties->placesOf($pairs) : $this->parties->labelsOf($pairs);

            $ordered = $rows
                ->sortBy(fn (Note $n) => mb_strtolower($names[$n->party_type.':'.$n->party_id] ?? "\u{FFFF}"))
                ->pluck('id')->all();

            $ordered === []
                ? $query->orderByDesc('acc_notes.id')
                : $query->orderByRaw('FIELD(acc_notes.id, '.implode(',', array_map('intval', $ordered)).')');
        };

        return [
            'latest' => fn ($q) => $q->orderByDesc('acc_notes.trx_date')->orderByDesc('acc_notes.id'),
            'oldest' => fn ($q) => $q->orderBy('acc_notes.trx_date')->orderBy('acc_notes.id'),
            'amount' => fn ($q) => $q->orderByRaw('('.self::MONEY.') DESC')->orderByDesc('acc_notes.id'),
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
}
