<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Core\Support\RunningBalance;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Http\Requests\CashTillRequest;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashTillService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * নগদ কাউন্টারের স্ক্রিন।
 *
 * তালিকাটার আসল কাজ একটাই প্রশ্নের উত্তর দেওয়া: এই মুহূর্তে কার কাছে
 * কত টাকা। তাই ব্যালেন্স কলামটা ঐচ্ছিক নয়, আর সীমা ছাড়ানো কাউন্টার
 * চোখে পড়ার মতো করে দেখানো হয়।
 */
class CashTillController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use SortsLists;

    public function __construct(
        private readonly CashTillService $tills,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return static::resourcePermissions(CashTill::class, 'till');
    }

    public function index(Request $request): View
    {
        $query = CashTill::query()
            ->search($request->query('q'))
            ->when(! $request->boolean('inactive'), fn ($q) => $q->active())
            ->with(['account', 'holder', 'branch']);

        $sort = $this->applySort($query, $request, $this->sorts());

        $tills = $query->get();

        // ব্যালেন্স একবারে, প্রতিটা টিলের জন্য আলাদা কোয়েরি নয় — দশটা
        // কাউন্টারে দশটা কোয়েরি হত, আর তালিকাটা রোজ খোলা হয়।
        $balances = $this->balancesFor($tills);

        return view('accounts::till.index', [
            'menu' => $this->menu->forUser($request->user()),
            'tills' => $tills,
            'balances' => $balances,
            'total' => array_reduce($balances, fn ($c, $b) => bcadd((string) $c, $b, 4), '0'),
            'q' => $request->query('q'),
            'showInactive' => $request->boolean('inactive'),
            'sortOptions' => $this->sortLabels(),
            'sort' => $sort,
        ]);
    }

    /**
     * সাজানোর নিয়ম — প্রথমটাই ডিফল্ট।
     *
     * প্রধান কাউন্টার আগে, তারপর কোড: তালিকাটা খোলা হয় "কার কাছে কত"
     * দেখতে, আর প্রধান কাউন্টারেই সবচেয়ে বেশি টাকা থাকে। বর্ণানুক্রম
     * ডিফল্ট করলে প্রতিবার চোখ খুঁজতে হত।
     *
     * @return array<string, \Closure>
     */
    private function sorts(): array
    {
        return [
            'primary' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('code'),
            'code' => fn ($q) => $q->orderBy('code'),
            'name' => fn ($q) => $q->orderBy('name_en'),
        ];
    }

    /** @return array<string, string> */
    private function sortLabels(): array
    {
        return [
            'primary' => __('accounts::sort.primary_first'),
            'code' => __('accounts::field.code'),
            'name' => __('accounts::field.name'),
        ];
    }

    public function create(Request $request): View
    {
        return view('accounts::till.form', [
            'menu' => $this->menu->forUser($request->user()),
            'till' => new CashTill(['is_active' => true, 'limit_amount' => 0]),
            'holders' => $this->holderOptions(),
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),
        ]);
    }

    public function store(CashTillRequest $request): RedirectResponse
    {
        $till = $this->tills->create($request->validated());

        return redirect()
            ->route('accounts.till.show', $till)
            ->with('saved', __('accounts::message.till_created'));
    }

    public function show(Request $request, CashTill $till): View
    {
        $entries = LedgerEntry::query()
            ->forAccount($till->account_id)
            ->orderByDesc('trx_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        /*
         * সারিগুলো নতুন থেকে পুরনো — কাউন্টারের পর্দায় লোকে আজকের
         * লেনদেন দেখতে আসে, ছয় মাস আগেরটা নয়।
         *
         * কিন্তু চলমান ব্যালেন্স গুনতে হয় পুরনো থেকে নতুন দিকে, নাহলে
         * প্রতিটা সারির ব্যালেন্স ভুল হত। তাই গোনাটা উল্টো করে, তারপর
         * দেখানোর ক্রমে ফেরানো হয়।
         */
        $ordered = $entries->getCollection()->reverse()->values();

        $opening = RunningBalance::sumOf(
            LedgerEntry::query()
                ->forAccount($till->account_id)
                ->orderBy('trx_date')
                ->orderBy('id')
                ->limit(max(0, $entries->total() - $entries->lastItem()))
                ->get(),
            fn (LedgerEntry $e) => $e->debit,
            fn (LedgerEntry $e) => $e->credit,
            (string) $till->account->opening_balance,
        );

        $running = new RunningBalance($opening);

        foreach ($ordered as $entry) {
            $entry->running_balance = $running->add($entry->debit, $entry->credit);
        }

        return view('accounts::till.show', [
            'menu' => $this->menu->forUser($request->user()),
            'till' => $till,
            'entries' => $entries,
            'balance' => $till->balance(),
        ]);
    }

    public function edit(Request $request, CashTill $till): View
    {
        return view('accounts::till.form', [
            'menu' => $this->menu->forUser($request->user()),
            'till' => $till,
            'holders' => $this->holderOptions(),
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),
        ]);
    }

    public function update(CashTillRequest $request, CashTill $till): RedirectResponse
    {
        $this->tills->update($till, $request->validated());

        return redirect()
            ->route('accounts.till.show', $till)
            ->with('saved', __('accounts::message.till_updated'));
    }

    /** বন্ধ করা — মোছা নয় (নিয়ম ৫)। টাকা হাতে থাকলে সার্ভিস আটকায়। */
    public function destroy(CashTill $till): RedirectResponse
    {
        $this->tills->deactivate($till);

        return redirect()
            ->route('accounts.till.index')
            ->with('saved', __('accounts::message.till_closed'));
    }

    /**
     * প্রধান কাউন্টার বদলানো।
     *
     * সম্পাদনার ফর্মে চেকবক্স হিসেবেও আছে, কিন্তু তালিকা থেকে এক ক্লিকে
     * বদলানো দরকার — নাহলে প্রধান কাউন্টার বদলাতে ফর্ম খুলে সেভ করতে হত।
     */
    public function makePrimary(Request $request, CashTill $till): RedirectResponse
    {
        $this->authorize('update', $till);

        $this->tills->makePrimary($till);

        return back()->with('saved', __('accounts::message.till_is_primary', ['name' => $till->name()]));
    }

    /**
     * @param  Collection<int, CashTill>  $tills
     * @return array<int, string>
     */
    private function balancesFor(Collection $tills): array
    {
        if ($tills->isEmpty()) {
            return [];
        }

        $sums = LedgerEntry::query()
            ->whereIn('account_id', $tills->pluck('account_id'))
            ->groupBy('account_id')
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->get()
            ->keyBy('account_id');

        $out = [];

        foreach ($tills as $till) {
            $row = $sums[$till->account_id] ?? null;

            $out[$till->id] = bcadd(
                (string) $till->account->opening_balance,
                bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4),
                4,
            );
        }

        return $out;
    }

    /**
     * যাদের হেফাজতে কাউন্টার দেওয়া যায়।
     *
     * এই কোম্পানির সক্রিয় ব্যবহারকারীরাই — অন্য কোম্পানির কাউকে টাকা
     * ধরিয়ে দেওয়ার কোনো মানে নেই, আর তালিকায় দেখা গেলে একদিন কেউ
     * ভুল করে বেছে ফেলত।
     *
     * @return Collection<int, User>
     */
    private function holderOptions(): Collection
    {
        return User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
