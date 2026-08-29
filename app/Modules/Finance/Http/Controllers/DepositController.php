<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Services\DepositService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * সঞ্চয় ও বিনিয়োগ — ব্যাংক আমানত · সঞ্চয়পত্র · বন্ড।
 *
 * ── কেন এক নিয়ন্ত্রক, তিনটা মেনু সারি ────────────────────────────────
 * তিনটার ঘর একই, কাজও একই — কেবল কাগজটা কে ছাপে সেটা আলাদা। তিনটা
 * নিয়ন্ত্রক লিখলে একই যাচাই তিনবার লিখতে হত, আর একদিন একটায় সংশোধন
 * হত বাকি দুইটায় নয়।
 *
 * মেনুতে তিনটা সারি, কারণ কেউ "জমা" খোঁজে না — খোঁজে "সঞ্চয়পত্র"।
 * ইস্যুয়ারটা রুটের প্যারামিটার, প্রশ্নচিহ্নের পরের অংশ নয়: মেনু
 * কোন সারিটা সক্রিয় তা রুটের প্যারামিটার দেখেই বলে।
 */
class DepositController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DepositService $deposits,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:finance.deposit.view', only: ['index', 'show']),
            new Middleware('can:finance.deposit.create', only: ['store']),
            new Middleware('can:finance.deposit.move', only: ['movement', 'close']),
            new Middleware('can:finance.deposit.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request, string $issuer): View
    {
        return view('finance::deposit.index', [
            'menu' => $this->menu->forUser($request->user()),
            'issuer' => $issuer,
            'standing' => $this->deposits->standing($issuer),

            /*
             * খোলাগুলো আগে, তারপর যেগুলো চুকে গেছে।
             *
             * ── কেন মেয়াদ ধরে সাজানো ────────────────────────────────
             * মেয়াদোত্তীর্ণ FD ব্যাংকে পড়ে থাকে আর সাধারণ সঞ্চয়ী হারে
             * সুদ পায় — অর্থাৎ প্রতিদিন টাকা হারায়। যেটার মেয়াদ সবার
             * আগে, সেটাই সবার উপরে থাকা দরকার।
             */
            'deposits' => Deposit::query()
                ->issuedBy($issuer)
                ->with(['kind', 'movements'])
                ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [Deposit::ACTIVE])
                ->orderByRaw('matures_on IS NULL')
                ->orderBy('matures_on')
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString(),

            'kinds' => DepositKind::query()
                ->where('issuer', $issuer)->where('is_active', true)
                ->orderBy('sort')->get(),

            'accounts' => $this->moneyAccounts(),
        ]);
    }

    public function store(Request $request, string $issuer): RedirectResponse
    {
        $data = $request->validate([
            'kind_id' => ['required', 'integer', 'exists:fin_deposit_kinds,id'],
            'institution' => ['required', 'string', 'max:160'],
            'branch_name' => ['nullable', 'string', 'max:160'],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'held_by' => ['required', 'string', 'in:'.Deposit::BUSINESS.','.Deposit::OWNER],
            'holder_name' => ['nullable', 'string', 'max:160'],
            'principal' => ['required', 'numeric', 'gt:0'],
            'profit_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'return_word' => ['required', 'string', 'in:interest,profit'],
            'opened_on' => ['required', 'date'],
            'matures_on' => ['nullable', 'date', 'after:opened_on'],
            'instalment_amount' => ['nullable', 'numeric', 'gt:0'],
            'instalment_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'payout_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'funded_from_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $deposit = $this->deposits->open($data);

        /*
         * খোলার পর তার নিজের পাতায় — তালিকায় নয়।
         *
         * পরের কাজটা প্রায় সবসময় ওখানেই: DPS হলে প্রথম কিস্তি, FD হলে
         * কাগজের নম্বরটা মিলিয়ে দেখা। তালিকায় ফেরালে ব্যবহারকারীকে
         * পঞ্চাশ সারির মধ্যে সদ্য খোলা সারিটা খুঁজতে হত।
         */
        return redirect()->route('finance.deposit.show', ['issuer' => $issuer, 'deposit' => $deposit->id])
            ->with('saved', __('finance::message.deposit_opened', ['no' => $deposit->document_no]));
    }

    /**
     * একটা জমার নিজের পাতা — তার তথ্য, তার চলাচল, আর যা করা যায়।
     */
    public function show(Request $request, string $issuer, Deposit $deposit): View
    {
        return view('finance::deposit.show', [
            'menu' => $this->menu->forUser($request->user()),
            'issuer' => $issuer,
            'deposit' => $deposit->load('kind', 'payoutAccount'),

            /*
             * চলাচলগুলো — নতুনটা উপরে।
             *
             * ── কেন উল্টো ক্রম ──────────────────────────────────────
             * ষাট মাসের DPS-এ পুরনো ক্রমে সবশেষ কিস্তিটা দেখতে ষাট
             * সারি স্ক্রল করতে হত, অথচ প্রশ্নটা সবসময় ওটাই: এই মাসেরটা
             * দেওয়া হয়েছে কি না।
             */
            'movements' => $deposit->movements()
                ->with('moneyAccount', 'voucher')
                ->orderByDesc('moved_on')->orderByDesc('id')->get(),

            'accounts' => $this->moneyAccounts(),
        ]);
    }

    /**
     * কিস্তি বা মুনাফা — একই বোতামের দুইটা মুখ।
     *
     * ── কেন এক পথ, দুইটা নয় ────────────────────────────────────────
     * পর্দায় ঘটনাটা একটাই সারি: তারিখ, টাকা, কোন খাত। কোনটা কিস্তি আর
     * কোনটা মুনাফা তা জমার আকৃতিই বলে দেয় — কিস্তির জমায় মুনাফা তোলার
     * প্রশ্নই ওঠে না। দুইটা রুট রাখলে পর্দায় দুইটা ফর্ম বসত, আর
     * ব্যবহারকারীকে বেছে নিতে হত এমন একটা পার্থক্য যা সিস্টেম নিজেই
     * জানে।
     */
    public function movement(Request $request, string $issuer, Deposit $deposit): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.DepositMovement::INSTALMENT.','.DepositMovement::PAYOUT],
            'amount' => ['required', 'numeric', 'gt:0'],
            'moved_on' => ['required', 'date'],
            'money_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $data['kind'] === DepositMovement::INSTALMENT
            ? $this->deposits->instalment($deposit, $data)
            : $this->deposits->payout($deposit, $data);

        return back()->with('saved', __('finance::message.deposit_moved', ['no' => $deposit->document_no]));
    }

    public function close(Request $request, string $issuer, Deposit $deposit): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'moved_on' => ['required', 'date'],
            'money_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->deposits->close($deposit, $data);

        return back()->with('saved', __('finance::message.deposit_closed', ['no' => $deposit->document_no]));
    }

    /**
     * ভুল করে বসানো হয়েছিল — পুরোটা ফিরিয়ে নাও।
     *
     * কারণটা বাধ্যতামূলক, আর সেটা সেবাও দ্বিতীয়বার দেখে: ছয় মাস পর
     * বাতিল সারিটা দেখে কেউ জানতে চাইবেন কী হয়েছিল।
     */
    public function cancel(Request $request, string $issuer, Deposit $deposit): RedirectResponse
    {
        $data = $request->validate([
            'cancel_reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->deposits->cancel($deposit, $data['cancel_reason']);

        return back()->with('saved', __('finance::message.deposit_cancelled', [
            'no' => $deposit->document_no,
        ]));
    }

    /**
     * নগদ ও ব্যাংকের নিচের খাতগুলো — মূলধনের পর্দা যেভাবে বাছে।
     *
     * `is_cash` পতাকা দিয়ে নয়: বসানো ছকে ওটা কেউ তোলে না, আর তালিকা
     * খালি আসত। ছকের গড়নটা `StandardChart` নিজেই বসায়, তাই ওটাই ধরা।
     *
     * @return Collection<int, Account>
     */
    private function moneyAccounts(): Collection
    {
        return Account::query()
            ->where('is_group', false)
            ->whereIn('parent_id', Account::query()
                ->whereIn('code', StandardChart::MONEY_PARENTS)->select('id'))
            ->orderBy('code')->get();
    }
}
