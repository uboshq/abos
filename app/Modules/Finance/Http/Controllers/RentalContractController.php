<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Services\RentalContractService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;

/**
 * ভাড়ার চুক্তি ও জামানত।
 *
 * ── কেন Finance-এ, আলাদা মডিউলে নয় ──────────────────────────────────
 * জিনিসটা ভাউচার পোস্ট করে, খাত ব্যবহার করে, টাকার খাত চায় — তিনটাই
 * Finance-এর প্লাম্বিং। বাইরে নিলে তিনটাই আবার লিখতে হত, আর একটা
 * হিসাবের রেকর্ড হিসাবের বাইরে বসে থাকত।
 *
 * ── কেন খরচের চাবিটাই, নতুন চাবি নয় ────────────────────────────────
 * ⛔ নতুন `PermissionKey` বসালে সেটা প্রথমে কেউ পেত না — চাবি বিলি হয়
 * enum-এ লেখা ডিফল্ট রোল ধরে, আর নতুন চাবি কোনো রোলে থাকে না। ফল হত
 * একটা লাইভ পর্দা যা সবার জন্য ৪০৩। ⓘ ভাড়া একটা খরচ, আর যিনি খরচ
 * লেখেন তিনিই ভাড়ার চুক্তিও দেখেন।
 */
class RentalContractController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly RentalContractService $contracts,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            /*
             * ⚠️ ৫ সেপ্টেম্বর ২০২৬ — এখানে আগে `finance.expense.*` লেখা
             * ছিল, আর সেটা **নীরবে ৪০৩** দিত।
             *
             * ⛔ কারণ `finance.expense.create` বলে কোনো চাবিই নেই — খরচ
             * লেখা হয় ভাউচারে, তাই Finance-এ কেবল `expense.view` আছে।
             * Gate অজানা চাবিকে "না" বলে, আর বার্তাটা হয় "This action is
             * unauthorized" — যা পড়ে কেউ বুঝত না চাবিটাই ভুল।
             *
             * ⚠️ আর পর্দাটা `@can('finance.rental.create')` দেখত, অর্থাৎ
             * **বোতামটা দেখা যেত, চাপলে ৪০৩** — সবচেয়ে বিভ্রান্তিকর রূপ।
             *
             * ⓘ কোনো টেস্ট এটা ধরেনি: টেস্টগুলো সার্ভিসটাকে সরাসরি ডাকে,
             * দরজা দিয়ে যায় না। ⭐ ধরা পড়েছে ব্রাউজারে হাতে চালিয়ে —
             * মালিক ঠিক এই কারণেই বলেন "Edge খুলে নিজে দেখো"।
             */
            new Middleware('can:finance.rental.view', only: ['index', 'show']),
            new Middleware('can:finance.rental.create', only: [
                'store', 'adjust', 'revise', 'topUp',
            ]),

            /*
             * চুক্তি শেষ করা আলাদা চাবি — ওটা **বাকি জামানত ফেরত এসেছে
             * বলে খাতায় লেখা**, আর ভুল করে করলে লাখ টাকার একটা পাওনা
             * নীরবে খাতা থেকে মুছে যেত।
             */
            new Middleware('can:finance.rental.close', only: ['close']),
        ];
    }

    public function index(Request $request): View
    {
        $query = RentalContract::query()
            ->with(['account', 'expenseAccount'])
            ->when(
                ! $request->boolean('closed'),
                fn ($q) => $q->active(),
                fn ($q) => $q->where('status', RentalContract::CLOSED),
            )
            ->orderBy('ends_on');

        return view('finance::rental.index', [
            'menu' => $this->menu->forUser($request->user()),
            'contracts' => $query->paginate(50)->withQueryString(),

            /*
             * ⭐ যেগুলো শেষ হয়ে আসছে — তালিকার মাথায়, আলাদা করে।
             *
             * ⚠️ এটাই এই গোটা মডিউলের সবচেয়ে দামি সংখ্যা। বারো লাখ
             * জামানতের নয় লাখ ষাট হাজার ফেরত নিতে ভুলে যাওয়া এভাবেই
             * ঘটে — কাগজটা কোথাও থাকে, তারিখটা কারো মনে থাকে না।
             */
            'endingSoon' => RentalContract::query()->endingSoon()->orderBy('ends_on')->get(),
            'showClosed' => $request->boolean('closed'),
            'money' => $this->moneyAccounts(),
            'heads' => Account::query()->postable()->active()->orderBy('code')->get(),
        ]);
    }

    public function show(Request $request, RentalContract $contract): View
    {
        return view('finance::rental.show', [
            'menu' => $this->menu->forUser($request->user()),
            'contract' => $contract->load(['account', 'expenseAccount']),

            /*
             * নতুন মাস আগে — প্রশ্নটা প্রায় সবসময় "শেষ মাসটা করা
             * হয়েছে কি না", "প্রথমটা কবে" নয়।
             */
            'adjustments' => $contract->adjustments()
                ->with('voucher')
                ->orderByDesc('for_month')
                ->get(),
            'money' => $this->moneyAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $contract = $this->contracts->open($request->validate([
            'counterparty' => ['required', 'string', 'max:191'],
            'counterparty_phone' => ['nullable', 'string', 'max:40'],
            'subject' => ['nullable', 'string', 'max:191'],
            'deposit_amount' => ['required', 'numeric', 'min:0'],
            'monthly_rent' => ['required', 'numeric', 'min:0'],
            'monthly_adjustment' => ['nullable', 'numeric', 'min:0'],
            'starts_on' => ['required', 'date'],
            'term_months' => ['required', 'integer', 'min:1', 'max:600'],
            'account_id' => ['nullable', 'integer'],
            'expense_account_id' => ['nullable', 'integer'],
            'money_account_id' => ['nullable', 'integer'],
            // ব্যাংক/MFS হলে যে নম্বরটা লাগে — ⛔ `required` নয়, নিয়মটা
            // এক জায়গায়: [[VoucherService::assertBankReferenceIsFree]]
            'instrument_no' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ]));

        return redirect()
            ->route('finance.rental.show', $contract)
            ->with('saved', __('finance::message.rental_opened'));
    }

    /** এক মাসের ভাড়া — নগদের অংশ আর জামানতের অংশ। */
    public function adjust(Request $request, RentalContract $contract): RedirectResponse
    {
        $this->contracts->adjustMonth($contract, $request->validate([
            'for_month' => ['required', 'date'],
            'paid_on' => ['nullable', 'date'],
            'rent' => ['nullable', 'numeric', 'min:0'],
            'from_deposit' => ['nullable', 'numeric', 'min:0'],
            'money_account_id' => ['nullable', 'integer'],
            // ব্যাংক/MFS হলে যে নম্বরটা লাগে — ⛔ `required` নয়, নিয়মটা
            // এক জায়গায়: [[VoucherService::assertBankReferenceIsFree]]
            'instrument_no' => ['nullable', 'string', 'max:64'],
        ]));

        return back()->with('saved', __('finance::message.rental_month_done'));
    }

    /**
     * শর্ত বদল — ভাড়া বাড়ল বা কমল।
     *
     * ⓘ গত মাসগুলো নড়ে না; বদলটা কেবল সামনের মাসগুলোয় খাটে।
     */
    public function revise(Request $request, RentalContract $contract): RedirectResponse
    {
        $this->contracts->reviseTerms($contract, $request->validate([
            'monthly_rent' => ['nullable', 'numeric', 'min:0'],
            'monthly_adjustment' => ['nullable', 'numeric', 'min:0'],
            'counterparty_phone' => ['nullable', 'string', 'max:40'],
            'subject' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:500'],
        ]));

        return back()->with('saved', __('finance::message.rental_revised'));
    }

    /** জামানতে আরও টাকা — জায়গা বাড়ল, বা ভাড়া কমানোর বিনিময়ে। */
    public function topUp(Request $request, RentalContract $contract): RedirectResponse
    {
        $this->contracts->addToDeposit($contract, $request->validate([
            'amount' => ['required', 'numeric', 'min:0.0001'],
            'money_account_id' => ['required', 'integer'],
            // ব্যাংক/MFS হলে যে নম্বরটা লাগে — ⛔ `required` নয়, নিয়মটা
            // এক জায়গায়: [[VoucherService::assertBankReferenceIsFree]]
            'instrument_no' => ['nullable', 'string', 'max:64'],
            'paid_on' => ['nullable', 'date'],
        ]));

        return back()->with('saved', __('finance::message.rental_topped_up'));
    }

    /**
     * চুক্তি শেষ — মেয়াদ ফুরিয়ে, বা আগেই ছেড়ে দিয়ে।
     *
     * ⚠️ টাকার খাতটা এখানে চাওয়া হয় কারণ **ফেরত ভুলে যাওয়াটাই আসল
     * বিপদ**। না দিলে চুক্তি বন্ধ হয়, কিন্তু জামানত খাতে পড়ে থাকে —
     * আর সেটা পর্দায় লেখা থাকে।
     */
    public function close(Request $request, RentalContract $contract): RedirectResponse
    {
        $this->contracts->close($contract, $request->validate([
            'closed_on' => ['nullable', 'date'],
            'money_account_id' => ['nullable', 'integer'],
            // ব্যাংক/MFS হলে যে নম্বরটা লাগে — ⛔ `required` নয়, নিয়মটা
            // এক জায়গায়: [[VoucherService::assertBankReferenceIsFree]]
            'instrument_no' => ['nullable', 'string', 'max:64'],
        ]));

        return back()->with('saved', __('finance::message.rental_closed_done'));
    }

    /** @return Collection<int, Account> */
    private function moneyAccounts()
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->get();
    }
}
