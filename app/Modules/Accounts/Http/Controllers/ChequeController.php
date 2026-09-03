<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\ChequeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * চেকের খাতা।
 *
 * ── পাতাটার আসল প্রশ্ন ──────────────────────────────────────────────
 * "আজ কোন চেকগুলো পাশ হওয়ার কথা, আর কোনগুলোর তারিখ পেরিয়ে গেছে অথচ
 * এখনো ঝুলে আছে।" দ্বিতীয়টাই বেশি জরুরি: আগাম তারিখের চেক ফেলে রাখা
 * স্বাভাবিক, কিন্তু তারিখ পেরোনোর পরেও ঝুলে থাকা মানে হয় কেউ জমা দিতে
 * ভুলে গেছে, নয় ব্যাংক থেকে খবর আসেনি।
 */
class ChequeController extends Controller implements HasMiddleware
{
    use SortsLists;

    public function __construct(
        private readonly ChequeService $cheques,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.cheque.view', only: ['index']),
            new Middleware('can:accounts.cheque.manage', only: ['store', 'deposit', 'clear', 'bounce']),
        ];
    }

    public function index(Request $request): View
    {
        $query = Cheque::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('direction'), fn ($q, $d) => $q->where('direction', $d))
            /*
             * উপরের দুইটা সংখ্যা (ঝুলন্ত মোট · তারিখ-পেরোনো) ক্লিক করে ঠিক
             * ওই চেকগুলোই দেখা যায় — মালিকের নিয়ম: প্রতিটা সংখ্যা থেকে উৎসে।
             */
            ->when($request->query('filter') === 'open', fn ($q) => $q->open())
            ->when($request->query('filter') === 'ripe', fn ($q) => $q->ripe());

        $sort = $this->applySort($query, $request, $this->sorts());

        return view('accounts::cheque.index', [
            'menu' => $this->menu->forUser($request->user()),
            'cheques' => $query->paginate(50)->withQueryString(),
            'parties' => app(PartyRegistry::class)->forPicker(),
            'banks' => Account::query()->where('is_bank', true)->active()->orderBy('code')->get(),
            'status' => $request->query('status'),
            'direction' => $request->query('direction'),
            'sortOptions' => $this->sortLabels(),
            'sort' => $sort,

            /*
             * তারিখ পেরোনো অথচ ঝুলে থাকা চেকের সংখ্যা — উপরে, বড় করে।
             *
             * এটাই পাতাটার একমাত্র সতর্কতা, আর এটাই রোজ সকালে দেখার
             * জিনিস।
             */
            'ripe' => Cheque::query()->ripe()->count(),
            'openTotal' => (string) (Cheque::query()->open()->sum('amount') ?: '0'),
        ]);
    }

    /** @return array<string, \Closure> */
    private function sorts(): array
    {
        return [
            'due' => fn ($q) => $q->orderBy('cheque_date')->orderBy('id'),
            'latest' => fn ($q) => $q->orderByDesc('received_on')->orderByDesc('id'),
            'amount' => fn ($q) => $q->orderByDesc('amount'),
        ];
    }

    /** @return array<string, string> */
    private function sortLabels(): array
    {
        return [
            'due' => __('accounts::field.cheque_date'),
            'latest' => __('accounts::sort.latest'),
            'amount' => __('accounts::sort.amount'),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'direction' => ['required', Rule::in([Cheque::RECEIVED, Cheque::ISSUED])],
            'cheque_no' => ['required', 'string', 'max:40'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'cheque_date' => ['required', 'date'],
            'received_on' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'party' => ['nullable', 'string', 'max:64'],
            'bank_account_id' => ['nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        /*
         * পক্ষের ঘরটা এক টুকরো ("customer:12") — জাবেদার মতোই।
         *
         * দুইটা আলাদা ঘর দিলে একটা ভরে অন্যটা খালি রাখা যেত, আর
         * খতিয়ানে একটা আধা-পক্ষ বসত।
         */
        if (filled($data['party'] ?? null) && str_contains((string) $data['party'], ':')) {
            [$type, $id] = explode(':', (string) $data['party'], 2);
            $data['party_type'] = trim($type);
            $data['party_id'] = (int) $id;
        }

        $cheque = $this->cheques->create($data);

        return back()->with('saved', __('accounts::message.cheque_saved', ['no' => $cheque->cheque_no]));
    }

    public function deposit(Request $request, Cheque $cheque): RedirectResponse
    {
        $data = $request->validate([
            'bank_account_id' => ['nullable', 'integer'],
        ]);

        $this->cheques->deposit($cheque, $data['bank_account_id'] ?? null);

        return back()->with('saved', __('accounts::message.cheque_deposited'));
    }

    public function clear(Request $request, Cheque $cheque): RedirectResponse
    {
        $data = $request->validate([
            'bank_account_id' => ['nullable', 'integer'],
        ]);

        $this->cheques->clear($cheque, $data['bank_account_id'] ?? null);

        return back()->with('saved', __('accounts::message.cheque_cleared'));
    }

    public function bounce(Request $request, Cheque $cheque): RedirectResponse
    {
        $data = $request->validate([
            'bounce_reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $this->cheques->bounce($cheque, $data['bounce_reason']);

        return back()->with('saved', __('accounts::message.cheque_bounced'));
    }
}
