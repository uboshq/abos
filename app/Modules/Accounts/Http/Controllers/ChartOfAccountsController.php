<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Services\MenuBuilder;
use App\Core\Support\RunningBalance;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Http\Requests\AccountRequest;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * হিসাবের ছকের স্ক্রিন।
 *
 * তালিকাটা গাছ, পাতা-ভাগ করা তালিকা নয় — ছকে দুইশো খাত থাকলেও সেটা
 * একটা কাঠামো, আর কাঠামোর মাঝখানে পাতা ভাগ করলে বাবা এক পাতায় আর
 * সন্তান আরেক পাতায় পড়ে। প্রতিটা মাথার নিচে যোগফল দেখাতেও পুরো গাছটা
 * লাগে।
 *
 * দুইশো সারি এক রেসপন্সে পাঠানো নিরাপদ; দুই হাজার হলে না। তাই একটা
 * সীমা আছে আর সেটা ছাড়ালে স্ক্রিনটা নিজেই বলে দেয়।
 */
class ChartOfAccountsController extends Controller implements HasMiddleware
{
    use AuthorizesResource;

    /**
     * পুরো গাছ একবারে দেখানোর সীমা।
     *
     * এর বেশি হলে গাছ ভেঙে খোঁজায় বদলে যায় — কারণ ততগুলো সারি একসাথে
     * দেখে কেউ কিছু খুঁজে পায় না, আর ব্যালেন্স গোনাটাও ভারী হয়ে ওঠে।
     */
    private const TREE_LIMIT = 400;

    public function __construct(
        private readonly AccountService $accounts,
        private readonly StandardChart $chart,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return static::resourcePermissions(Account::class, 'account');
    }

    public function index(Request $request): View
    {
        $q = $request->query('q');
        $showInactive = $request->boolean('inactive');

        $query = Account::query()
            ->when(! $showInactive, fn ($b) => $b->active())
            ->orderBy('code');

        $total = (clone $query)->count();

        // খোঁজার সময় গাছ নয়, সমতল ফল — কেউ "৫২০২" লিখলে সে ওই খাতটা
        // চায়, তার পূর্বপুরুষদের নয়। পথটা প্রতিটা সারিতে দেখানো হয়।
        $searching = filled($q);

        $accounts = $searching
            ? $query->search($q)->limit(100)->get()
            : ($total <= self::TREE_LIMIT ? $query->get() : new Collection);

        /*
         * শুধু খোঁজার ফলে — গাছের চেহারায় পথ দেখানো হয় না।
         *
         * খোঁজার ফলে প্রতিটা সারির নিচে তার পুরো পথ থাকে (উপরের মন্তব্য),
         * আর ১০০টা সারির জন্য সেটা স্তরে স্তরে কোয়েরি হত। গাছেও ডাকলে
         * ওখানে অকারণে একটা বাড়তি কোয়েরি যোগ হত — যে খরচটা এখানে কমাতে
         * বসেছি সেটাই অন্য পাতায় ফিরিয়ে আনা হত।
         */
        if ($searching) {
            Account::primeAncestry($accounts);
        }

        return view('accounts::coa.index', [
            'menu' => $this->menu->forUser($request->user()),
            'tree' => $searching ? new Collection : $this->tree($accounts),
            'results' => $searching ? $accounts : new Collection,
            'balances' => $this->balancesFor($accounts),
            'q' => $q,
            'showInactive' => $showInactive,
            'total' => $total,
            'tooManyToShow' => ! $searching && $total > self::TREE_LIMIT,
            'treeLimit' => self::TREE_LIMIT,
        ]);
    }

    public function create(Request $request): View
    {
        return view('accounts::coa.form', [
            'menu' => $this->menu->forUser($request->user()),
            'account' => new Account(['is_active' => true, 'type' => Account::ASSET]),
            'parents' => $this->groupOptions(),
            // ?parent=12 দিয়ে এলে ওই মাথার নিচেই তৈরি হবে — গাছের
            // একটা শাখা থেকে "+" চাপলে আবার বাবা বাছতে হয় না
            'preselectedParent' => $request->integer('parent') ?: null,
        ]);
    }

    public function store(AccountRequest $request): RedirectResponse
    {
        $account = $this->accounts->create($request->validated());

        return redirect()
            ->route('accounts.coa.show', $account)
            ->with('saved', __('accounts::message.created'));
    }

    public function show(Request $request, Account $account): View
    {
        $entries = LedgerEntry::query()
            ->forAccount($account->id)
            ->orderBy('trx_date')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $page = max(1, (int) $request->query('page', 1));

        /*
         * চলমান জের শূন্য থেকে শুরু।
         *
         * খোলার জের এখন খতিয়ানের প্রথম সারিটাই ([[OpeningBalanceService]]),
         * তাই এখানে আবার শুরুর মান হিসেবে বসালে প্রতিটা সারিতে সংখ্যাটা
         * দ্বিগুণ হত। HP-র অভিযোগটাই ছিল উল্টোটা: তালিকায় জেরের কোনো
         * লাইন নেই অথচ চলমান জের সরাসরি লাফ দেয় — এখন লাইনটাই আছে।
         */
        $opening = '0';

        if ($page > 1) {
            $opening = RunningBalance::sumOf(
                LedgerEntry::query()
                    ->forAccount($account->id)
                    ->orderBy('trx_date')
                    ->orderBy('id')
                    ->forPage(1, ($page - 1) * 50)
                    ->get(),
                fn (LedgerEntry $e) => $e->debit,
                fn (LedgerEntry $e) => $e->credit,
                $opening,
            );
        }

        $account->withRunningBalance($entries->getCollection(), $opening);

        /*
         * গ্রুপ খাতের নিচের ধাপগুলো আগে থেকে আনা।
         *
         * ── কেন ────────────────────────────────────────────────────
         * [[Account::balanceOn()]] গ্রুপের বেলায় সন্তানদের জের যোগ করে,
         * আর সন্তান নিজেও গ্রুপ হলে আবার নিচে নামে। আগে থেকে না আনলে
         * প্রতিটা ধাপে আলাদা কোয়েরি যেত — মাথার খাতে ২২৯টা সারির ছক
         * মানে দুইশোর বেশি কোয়েরি, আর পাতাটা ধীরে খোলা ছাড়া কোনো
         * লক্ষণ থাকত না। ধরা পড়েছে preventLazyLoading চালু করার পর,
         * ৩১ আগস্ট ২০২৬-এ পর্দা খুলে।
         *
         * চারটা ধাপ, কারণ ছকটা চার ধাপ গভীর (মাপা)। ধাপ ধরে আনলে
         * Eloquent প্রতি ধাপে একটাই কোয়েরি করে, খাত যত বেশিই হোক।
         *
         * ── যা এতে সারে না, আর সেটা লিখে রাখা ──────────────────────
         * জেরটা তবু প্রতিটা পাতা-খাতের জন্য আলাদা করে গোনা হয়। পুরো
         * সাবট্রির জন্য একটাই যোগফল-কোয়েরি করা যেত, কিন্তু তাতে চিহ্ন
         * ঠিক রাখার নিয়মটা বদলাতে হয় (প্রতিটা খাত নিজের প্রকৃতি ধরে
         * চিহ্ন ঠিক করে), আর টাকার অঙ্কের নিয়ম এভাবে একা বদলানো ঠিক নয়।
         * ওটা আলাদা সিদ্ধান্ত।
         */
        if ($account->is_group) {
            $account->load('children.children.children.children');
        }

        return view('accounts::coa.show', [
            'menu' => $this->menu->forUser($request->user()),
            'account' => $account,
            'entries' => $entries,
            'balance' => $account->balanceOn(),
            // আগে থেকে আনা সন্তানগুলোই — `children()->get()` লিখলে নতুন
            // মডেল আসত, আর তাদের জের গুনতে গিয়ে আবার নিচে নামা শুরু হত
            'children' => $account->is_group ? $account->children : new Collection,
        ]);
    }

    public function edit(Request $request, Account $account): View
    {
        return view('accounts::coa.form', [
            'menu' => $this->menu->forUser($request->user()),
            'account' => $account->load('parent'),
            // নিজে ও নিজের নিচের কেউ বাবা হতে পারে না — তালিকা থেকেই
            // বাদ, নাহলে ব্যবহারকারী বাছার পর ভুলের বার্তা পেত
            'parents' => $this->groupOptions(exclude: $account->selfAndDescendants()->pluck('id')->all()),
            'preselectedParent' => $account->parent_id,
        ]);
    }

    public function update(AccountRequest $request, Account $account): RedirectResponse
    {
        $this->accounts->update($account, $request->validated());

        return redirect()
            ->route('accounts.coa.show', $account)
            ->with('saved', __('accounts::message.updated'));
    }

    /** মোছা নয়, নিষ্ক্রিয় করা — নিয়ম ৫। */
    public function destroy(Account $account): RedirectResponse
    {
        $this->accounts->deactivate($account);

        return redirect()
            ->route('accounts.coa.index')
            ->with('saved', __('accounts::message.deactivated'));
    }

    /**
     * প্রমিত ছক বসানো।
     *
     * আলাদা রুট, নিজের অনুমতি সহ। resource ছকের বাইরে বলে
     * resourcePermissions() এটা ছোঁয় না, তাই can: রুটেই লেখা আছে।
     */
    public function installStandardChart(): RedirectResponse
    {
        $count = $this->chart->install();

        return redirect()
            ->route('accounts.coa.index')
            ->with('saved', __('accounts::message.chart_installed', ['count' => $count]));
    }

    /**
     * সমতল তালিকাকে গাছে সাজানো — একবার ঘুরে, বারবার কোয়েরি না করে।
     *
     * প্রতিটা সন্তানের জন্য আলাদা কোয়েরি করলে দুইশো খাতে দুইশো কোয়েরি
     * হত। এখানে একটাই ফলাফল, আর সম্পর্কটা মেমোরিতে জোড়া হয়।
     *
     * @param  Collection<int, Account>  $accounts
     * @return Collection<int, Account>
     */
    private function tree(Collection $accounts): Collection
    {
        $byParent = $accounts->groupBy(fn (Account $a) => $a->parent_id ?? 0);

        $attach = function (Account $node) use ($byParent, &$attach): Account {
            $node->setRelation(
                'children',
                ($byParent[$node->id] ?? new Collection)->map($attach)->values(),
            );

            return $node;
        };

        return ($byParent[0] ?? new Collection)->map($attach)->values();
    }

    /**
     * প্রতিটা খাতের ব্যালেন্স — একটা কোয়েরিতে, খাত ধরে নয়।
     *
     * balanceOn() একটা খাতের জন্য ঠিক আছে, কিন্তু দুইশো খাতের তালিকায়
     * ওটা দুইশো বার ডাকলে দুইশো কোয়েরি হত, আর গ্রুপগুলোর জন্য আরও।
     * তাই তালিকার জন্য সব যোগফল একবারে এনে গাছ ধরে উপরে তোলা হয়।
     *
     * @param  Collection<int, Account>  $accounts
     * @return array<int, string>
     */
    private function balancesFor(Collection $accounts): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        $sums = LedgerEntry::query()
            ->whereIn('account_id', $accounts->pluck('id'))
            ->groupBy('account_id')
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->get()
            ->keyBy('account_id');

        $own = [];

        foreach ($accounts as $account) {
            $row = $sums[$account->id] ?? null;

            $signed = bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4);

            /* খোলার জের খতিয়ানেই আছে — এখানে আবার যোগ করলে দ্বিগুণ */
            $own[$account->id] = $signed;
        }

        // সন্তানের যোগফল বাবার ঘরে — নিচ থেকে উপরে, তাই কোড অনুসারে
        // উল্টো দিক থেকে ঘোরা হয় (সন্তানের কোড বাবার চেয়ে বড়)।
        foreach ($accounts->sortByDesc('code') as $account) {
            if ($account->parent_id !== null && isset($own[$account->parent_id])) {
                $own[$account->parent_id] = bcadd($own[$account->parent_id], $own[$account->id], 4);
            }
        }

        // শেষে স্বাভাবিক দিকে ধনাত্মক করে ফেরানো
        $out = [];

        foreach ($accounts as $account) {
            $out[$account->id] = $account->nature === Account::CREDIT
                ? bcmul($own[$account->id], '-1', 4)
                : $own[$account->id];
        }

        return $out;
    }

    /**
     * যেসব খাতের নিচে আরেকটা খাত বসানো যায়।
     *
     * @param  list<int>  $exclude
     * @return Collection<int, Account>
     */
    private function groupOptions(array $exclude = []): Collection
    {
        /*
         * primeAncestry — ফর্মের ঝুলন্ত তালিকায় প্রতিটা নাম তার গভীরতা
         * অনুযায়ী ভেতরে সরানো থাকে, আর গভীরতাটা আসে শিকড় গুনে। এটা ছাড়া
         * প্রতিটা বিকল্পের জন্য parent, তার parent — মাপা হয়েছে: ৪৪টা
         * কোয়েরির ১৮টাই ছিল কেবল ওই গোনা।
         */
        return Account::primeAncestry(
            Account::query()
                ->where('is_group', true)
                ->active()
                ->when($exclude !== [], fn ($q) => $q->whereNotIn('id', $exclude))
                ->orderBy('code')
                ->get()
        );
    }
}
