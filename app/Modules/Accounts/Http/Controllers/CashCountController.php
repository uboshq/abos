<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashCountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * নগদ গণনার স্ক্রিন।
 *
 * ফর্মে খাতার সংখ্যাটা দেখানো হয় না যতক্ষণ না গোনা শেষ — ইচ্ছাকৃতভাবে।
 * আগে দেখালে ক্যাশিয়ার ওই সংখ্যাটাই টাইপ করে দিত, আর গণনার পুরো
 * উদ্দেশ্যটাই হারাত। গোনার পর দুইটা সংখ্যা পাশাপাশি আসে।
 */
class CashCountController extends Controller implements HasMiddleware
{
    use SortsLists;

    public function __construct(
        private readonly CashCountService $counts,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.count.create', only: ['index', 'show', 'create', 'store']),
            new Middleware('can:accounts.count.approve', only: ['approve']),
        ];
    }

    public function index(Request $request): View
    {
        $query = CashCount::query()
            ->search($request->query('q'))
            ->with(['till', 'counter', 'approver']);

        $sort = $this->applySort($query, $request, $this->sorts());

        $counts = $query->paginate(50)->withQueryString();

        return view('accounts::count.index', [
            'menu' => $this->menu->forUser($request->user()),
            'counts' => $counts,
            'q' => $request->query('q'),
            'sortOptions' => $this->sortLabels(),
            'sort' => $sort,
        ]);
    }

    /**
     * নতুন গণনা আগে — তালিকাটা খোলা হয় "আজ কে গুনল" দেখতে।
     *
     * পার্থক্য দিয়ে সাজানোটা আলাদা প্রশ্নের উত্তর: কোন গণনায় সবচেয়ে
     * বড় গরমিল ছিল। ওটাই খোঁজা হয় যখন কিছু একটা সন্দেহ হয়।
     *
     * @return array<string, \Closure>
     */
    private function sorts(): array
    {
        return [
            'latest' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            // পার্থক্যটা সংরক্ষিত কলাম, তাই হিসাব করার দরকার নেই। ABS —
            // ঘাটতি আর উদ্বৃত্ত দুইটাই সমান সন্দেহজনক
            'difference' => fn ($q) => $q->orderByRaw('ABS(difference) DESC'),
        ];
    }

    /** @return array<string, string> */
    private function sortLabels(): array
    {
        return [
            'latest' => __('accounts::sort.latest'),
            'oldest' => __('accounts::sort.oldest'),
            'difference' => __('accounts::sort.biggest_difference'),
        ];
    }

    public function create(Request $request): View
    {
        return view('accounts::count.form', [
            'menu' => $this->menu->forUser($request->user()),
            'tills' => CashTill::query()->active()->orderByDesc('is_primary')->orderBy('code')->get(),
            'notes' => CashCount::DENOMINATIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'cash_till_id' => ['required', 'integer'],
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],
            'counts' => ['required', 'array'],
            'counts.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $count = $this->counts->record($validated, $validated['counts']);

        return redirect()
            ->route('accounts.count.show', $count)
            ->with('saved', __('accounts::message.count_recorded', ['no' => $count->document_no]));
    }

    public function show(Request $request, CashCount $count): View
    {
        $count->load(['till', 'counter', 'approver', 'adjustment']);

        return view('accounts::count.show', [
            'menu' => $this->menu->forUser($request->user()),
            'count' => $count,
        ]);
    }

    /** অনুমোদন — পার্থক্য থাকলে এখনই সমন্বয়ের জাবেদা বসে। */
    public function approve(CashCount $count): RedirectResponse
    {
        $this->counts->approve($count);

        return back()->with('saved', __('accounts::message.count_approved', ['no' => $count->document_no]));
    }
}
