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
use App\Modules\Accounts\Models\TradeVoucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * ⭐ স্টক ছাড়া বিক্রয় ভাউচার (SV) আর ক্রয় ভাউচার (PUV) — তালিকা (মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬)।
 *
 * ⛔ মোট কেবল পাকা ভাউচারের — খসড়া আর অনুমোদনের অপেক্ষা আলাদা লাইনে ([[NoteListController]], [[CollectionController]]-এর ছাঁচ)।
 * ⓘ টাকার ঘর = ভাউচারের মোট (`total`); PUV-তে উৎসে কর আর পক্ষের দেনা (`party_total`) আলাদা ঘরে।
 * ⓘ সুইচ বন্ধ কোম্পানিতে ঠিকানাটা বন্ধ ([[RefuseSwitchedOffScreens]]) — এখানে আবার দেখা হয় না।
 */
final class TradeVoucherListController extends Controller implements HasMiddleware
{
    use GrandTotals;
    use SortsLists;

    public function __construct(
        private readonly PartyRegistry $parties,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.report')];
    }

    public function __invoke(Request $request, string $kind): View
    {
        abort_unless(array_key_exists($kind, TradeVoucher::DOC_TYPES), 404);

        $base = fn () => $this->listed($request, $kind);
        $filters = $this->filters($base, $kind);
        $query = $base();
        $filters->apply($query, $request);

        $this->applySort($query, $request, [
            'latest' => fn ($q) => $q->orderByDesc('acc_trade_vouchers.trx_date')->orderByDesc('acc_trade_vouchers.id'),
            'oldest' => fn ($q) => $q->orderBy('acc_trade_vouchers.trx_date')->orderBy('acc_trade_vouchers.id'),
            'amount' => fn ($q) => $q->orderByDesc('acc_trade_vouchers.total')->orderByDesc('acc_trade_vouchers.id'),
            'due' => fn ($q) => $q->orderByRaw('acc_trade_vouchers.due_on IS NULL')->orderBy('acc_trade_vouchers.due_on')->orderByDesc('acc_trade_vouchers.id'),
        ]);

        // ⛔ মোট কেবল পাকা কাগজের; অপেক্ষমাণ = খসড়া + অনুমোদনের খোলা সারি
        $totalled = (clone $query)->reorder();
        $posted = (clone $totalled)->whereIn('acc_trade_vouchers.status', DocumentStatus::POSTED);
        $drafts = (clone $totalled)->where('acc_trade_vouchers.status', DocumentStatus::DRAFT);
        $waiting = (clone $drafts)->whereExists($this->signing());
        $plain = (clone $drafts)->whereNotExists($this->signing());

        $grand = $this->grandTotals(clone $posted, [
            'total' => 't.total',
            'tds_amount' => 't.tds_amount',
            'party_total' => 't.party_total',
        ]);
        $rows = $query->paginate(\App\Core\Support\PerPage::of())->withQueryString();

        $pairs = collect($rows->items())->map(fn (TradeVoucher $v) => [$v->party_type, (int) $v->party_id]);
        $ids = collect($rows->items())->map(fn (TradeVoucher $v) => (int) $v->id)->all();

        return view('accounts::trade-voucher.index', [
            'menu' => $this->menu->forUser($request->user()),
            'kind' => $kind,
            'rows' => $rows,
            'grand' => $grand,
            'totals' => [
                'rows' => (clone $totalled)->count(),
                'money' => (string) ($grand['total'] ?? '0'),
                'drafts' => ['count' => (clone $plain)->count(), 'money' => (string) (clone $plain)->sum('acc_trade_vouchers.total')],
                'waiting' => ['count' => (clone $waiting)->count(), 'money' => (string) (clone $waiting)->sum('acc_trade_vouchers.total')],
            ],
            'names' => $this->parties->labelsOf($pairs),
            'places' => $this->parties->placesOf($pairs),
            'routes' => $this->parties->routesOf($pairs),
            'waitingIds' => $ids === [] ? []
                : (clone $waiting)->whereIn('acc_trade_vouchers.id', $ids)->pluck('acc_trade_vouchers.id')->map(fn ($id) => (int) $id)->all(),
            'filters' => Panel::of($filters, $base, $request),
            'sortOptions' => [
                'latest' => __('accounts::sort.latest'),
                'oldest' => __('accounts::sort.oldest'),
                'amount' => __('accounts::sort.amount'),
                'due' => __('accounts::note_list.sort_due'),
            ],
            'q' => $request->query('q'),
        ]);
    }

    /** @return Builder<TradeVoucher> */
    private function listed(Request $request, string $kind): Builder
    {
        $term = trim((string) $request->query('q', ''));
        $parties = $term !== '' ? $this->parties->matching($term, $this->parties->types()) : [];

        return TradeVoucher::query()
            ->where('acc_trade_vouchers.kind', $kind)
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term, $parties) {
                $w->where('acc_trade_vouchers.document_no', 'like', "%{$term}%")
                    ->orWhere('acc_trade_vouchers.ref_no', 'like', "%{$term}%")
                    ->orWhere('acc_trade_vouchers.mushak_no', 'like', "%{$term}%")
                    ->orWhere('acc_trade_vouchers.narration', 'like', "%{$term}%");

                foreach ($parties as $partyType => $partyIds) {
                    $w->orWhere(fn ($p) => $p->where('acc_trade_vouchers.party_type', $partyType)->whereIn('acc_trade_vouchers.party_id', $partyIds));
                }
            }));
    }

    private function signing(): \Closure
    {
        $morph = (new TradeVoucher)->getMorphClass();

        return fn ($q) => $q->select(DB::raw(1))->from('approvals')
            ->where('approvals.approvable_type', $morph)
            ->whereColumn('approvals.approvable_id', 'acc_trade_vouchers.id')
            ->where('approvals.status', Approval::PENDING);
    }

    /** @param  \Closure(): Builder  $base */
    private function filters(\Closure $base, string $kind): FilterSet
    {
        $stages = [
            'posted' => ['label' => __('accounts::note_list.stage_posted'), 'tone' => 'green',
                'apply' => fn (Builder $q) => $q->whereIn('acc_trade_vouchers.status', DocumentStatus::POSTED)],
            'waiting' => ['label' => __('accounts::note_list.stage_waiting'), 'tone' => 'amber',
                'apply' => fn (Builder $q) => $q->where('acc_trade_vouchers.status', DocumentStatus::DRAFT)->whereExists($this->signing())],
            'draft' => ['label' => __('accounts::note_list.stage_draft'), 'tone' => 'slate',
                'apply' => fn (Builder $q) => $q->where('acc_trade_vouchers.status', DocumentStatus::DRAFT)->whereNotExists($this->signing())],
            'cancelled' => ['label' => __('accounts::note_list.stage_cancelled'), 'tone' => 'red',
                'apply' => fn (Builder $q) => $q->where('acc_trade_vouchers.status', DocumentStatus::CANCELLED)],
        ];

        $today = Carbon::today()->toDateString();

        return new FilterSet([
            (new ChoiceFacet('stage', __('accounts::note_list.filter_stage'), $stages))->look('blue', '●'),
            (new DateRangeFacet('date', __('accounts::note_list.filter_date'), function (Builder $q, ?string $from, ?string $to): void {
                if ($from !== null) {
                    $q->where('acc_trade_vouchers.trx_date', '>=', $from);
                }
                if ($to !== null) {
                    $q->where('acc_trade_vouchers.trx_date', '<=', $to);
                }
            }, fromKey: 'from', toKey: 'to'))->look('sky', '📅')->onTheBar(),
            (new ChoiceFacet('party', __('accounts::note_list.filter_party_'.$kind),
                NoteListController::partyOptions($this->parties, $base, 'acc_trade_vouchers')))->look('teal', '◈'),
            // ⓘ মেয়াদ পার — পাকা ভাউচার, শোধের শেষ তারিখ আজকের আগে (টাকা মেলানো রসিদ/পরিশোধে; এখানে কেবল তারিখ)
            (new ChoiceFacet('due', __('accounts::note_list.filter_due'), [
                'overdue' => ['label' => __('accounts::note_list.due_overdue'), 'tone' => 'red',
                    'apply' => fn (Builder $q) => $q->whereIn('acc_trade_vouchers.status', DocumentStatus::POSTED)
                        ->whereNotNull('acc_trade_vouchers.due_on')->where('acc_trade_vouchers.due_on', '<', $today)],
                'upcoming' => ['label' => __('accounts::note_list.due_upcoming'), 'tone' => 'amber',
                    'apply' => fn (Builder $q) => $q->where('acc_trade_vouchers.due_on', '>=', $today)],
            ]))->look('red', '⏱'),
        ]);
    }
}
