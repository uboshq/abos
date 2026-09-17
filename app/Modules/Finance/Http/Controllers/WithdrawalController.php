<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\WithdrawalService;
use App\Modules\MasterData\Models\Person;
use App\Modules\MasterData\Services\PersonResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * উত্তোলন — কে কত নিলেন, আর মাসে কতটা নিতে পারবেন।
 *
 * ── কেন সীমাটাও এই পর্দাতেই ──────────────────────────────────────────
 * সীমা পেরোলে সেবাটা আটকায়। বদলানোর ঘরটা অন্য পর্দায় থাকলে
 * ব্যবহারকারীকে আটকে গিয়ে খুঁজতে যেতে হত, আর বেশিরভাগ মানুষ খুঁজতে
 * যান না — তাঁরা ধরে নেন জিনিসটা নষ্ট।
 */
class WithdrawalController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly WithdrawalService $withdrawals,
        private readonly PersonResolver $people,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:finance.withdrawal.view', only: ['index']),
            new Middleware('can:finance.withdrawal.create', only: ['store']),
            new Middleware('can:finance.withdrawal.post', only: ['post']),
            new Middleware('can:finance.withdrawal.cap', only: ['cap']),
        ];
    }

    public function index(Request $request): View
    {
        $month = $request->query('month');

        return view('finance::withdrawal.index', [
            'menu' => $this->menu->forUser($request->user()),
            'month' => is_string($month) && $month !== '' ? $month : now()->format('Y-m'),
            'standing' => $this->withdrawals->standing(
                is_string($month) && $month !== '' ? $month.'-01' : null,
            ),
            'rows' => Withdrawal::query()->with(['moneyAccount', 'voucher', 'person'])
                ->orderByDesc('trx_date')->orderByDesc('id')->paginate(50),
            /*
             * কে তুলতে পারেন — মালিক, অংশীদার।
             *
             * ⓘ কেবল সক্রিয়রা: নিষ্ক্রিয় মানুষ নতুন কাগজে বসেন না,
             * কিন্তু তাঁর পুরনো সারি ও সীমা অটুট থাকে।
             */
            'people' => Person::query()->active()->orderBy('name_en')
                ->pluck('name_en', 'id'),
            'accounts' => $this->moneyAccounts(),
        ]);
    }

    /**
     * উত্তোলন লেখার পাতা — মালিকের পুঁজির অন্য দিক।
     *
     * ── ⭐ কেন `index` থেকে আলাদা, ১৮ সেপ্টেম্বর ২০২৬ ────────────────
     * মালিক নমুনা আর লাইভ পাশাপাশি রেখে দেখিয়েছেন: নমুনায় উত্তোলন
     * মূলধনের পাতারই দ্বিতীয় দিক — একই হেডার, একই ঘরের ক্রম, একই
     * ফিতা, একই ভাউচারের বাক্স।
     *
     * ⓘ `index` তবু রয়ে গেছে, কারণ ওখানে যা আছে তা **পড়ার** জিনিস:
     * মাসের হিসাব, কে কত তুলেছেন, মাসিক সীমা। ⛔ ওগুলো নমুনার লেখার
     * পাতায় নেই, আর থাকলে ফর্মটা তিনগুণ লম্বা হত।
     */
    public function create(Request $request): View
    {
        return view('finance::withdrawal.form', [
            'menu' => $this->menu->forUser($request->user()),
            'people' => $this->peopleForPicker(),
            'moneyAccounts' => $this->moneyAccounts(),
            'carriers' => $this->peopleForPicker(),
            'writingFor' => $this->writingFor(),
        ]);
    }

    /**
     * কোন কোম্পানির, কোন শাখার খাতায় লেখা হচ্ছে।
     *
     * ⓘ বাছাই নয়, তথ্য — কোম্পানি ও শাখা আগেই বাছা হয়ে আছে শেলে।
     * ⛔ এখানে দ্বিতীয় বাছাই বসালে দুই জায়গায় দুই উত্তর থাকত।
     */
    private function writingFor(): string
    {
        $company = Company::query()->find(CompanyContext::id());
        $branch = Branch::query()->find(CompanyContext::branchId());

        return trim(implode(' — ', array_filter([
            $company?->name(),
            $branch?->name(),
        ]))) ?: '—';
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            /*
             * ⓘ দুইটা পথ, একটাই লাগে — তালিকা থেকে বাছা, নয় নতুন নাম।
             * ⚠️ `exists`-এ `company_id`, নাহলে অন্য কোম্পানির আইডি বসিয়ে
             * দিলে সেই মানুষের নামে এখানকার টাকা বেরোত।
             */
            'person_id' => ['nullable', 'integer', 'required_without:person_new',
                Rule::exists('mdm_people', 'id')->where('company_id', $companyId)],
            'person_new' => ['nullable', 'string', 'max:120', 'required_without:person_id'],
            'person_mobile' => ['nullable', 'string', 'max:32'],
            /*
             * ⭐ পক্ষের তিনটা ঘর — মানুষটার সাথে যায়, সারির সাথে নয়
             * ([[App\Modules\MasterData\Services\PersonResolver]])।
             *
             * ⛔ এগুলো `validate()`-এ না থাকলে **নীরবে হারায়**: Laravel
             * কেবল যাচাই করা চাবিগুলোই ফেরায়, তাই ফর্ম পাঠালেও
             * PersonResolver ঘরগুলো পেত না আর সারি বসত `NULL` নিয়ে।
             * ⓘ ১৫ সেপ্টেম্বর ২০২৬-এ লোকালে জমা দিয়ে ধরা পড়েছে।
             */
            'person_relationship' => ['nullable', 'string', 'max:60'],
            'person_address' => ['nullable', 'string', 'max:191'],
            'person_nid_tin' => ['nullable', 'string', 'max:40'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            /*
             * ⭐ উত্তোলনের ধরন — ১৮ সেপ্টেম্বর ২০২৬।
             *
             * ⛔ কলামটা আমি বসিয়েছিলাম, চিপ তিনটাও বসিয়েছিলাম, কিন্তু
             * ভ্যালিডেশনে নাম ছিল না — তাই `validate()` ঘরটা **ফেলে
             * দিত**, আর সারিতে সবসময় ডিফল্ট `drawing` বসত।
             *
             * ⚠️ পর্দায় "মালিকের বেতন" বাছা যেত, সারিতে বসত "উত্তোলন",
             * আর কোনো ভুল উঠত না। ⓘ ধরা পড়েছে ফর্ম জমা দিয়ে সারি
             * পড়ায়, পর্দা দেখে নয়।
             */
            'kind' => ['nullable', Rule::in(Withdrawal::KINDS)],
            'paper' => ['nullable', 'file'],

            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $data['person_id'] = $this->people->resolve($data);

        $withdrawal = $this->withdrawals->request($data);

        return back()->with('saved', __('finance::message.withdrawal_recorded', [
            'no' => $withdrawal->document_no,
        ]));
    }

    /**
     * টাকা গেল — খাতায় বসাও।
     *
     * কোন খাত থেকে সেটা এখানেই জিজ্ঞেস করা হয়, লেখার সময় নয়: তখনো
     * জানা ছিল না টাকাটা সিন্দুক থেকে যাবে না ব্যাংক থেকে।
     */
    public function post(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $data = $request->validate([
            'money_account_id' => ['required', 'integer', 'exists:accounts,id'],
            /*
             * ব্যাংক বা MFS হলে যে নম্বরটা লাগে — চেক নম্বর, TrxID।
             *
             * ⛔ `required` **নয়**, আর সেটা ইচ্ছাকৃত: নগদে নম্বর হয় না,
             * আর কখন নম্বর লাগবে সেটা ইতিমধ্যেই এক জায়গায় জানা —
             * [[App\Modules\Accounts\Services\VoucherService::assertBankReferenceIsFree]]।
             * এখানে `required` করলে নিয়মটা দুই জায়গায় থাকত, আর একদিন
             * দুইটা আলাদা কথা বলত (নগদেও চাওয়া, বা ব্যাংকে না চাওয়া)।
             */
            'instrument_no' => ['nullable', 'string', 'max:64'],
        ]);

        $this->withdrawals->post(
            $withdrawal,
            Account::query()->findOrFail($data['money_account_id']),
            ($data['instrument_no'] ?? '') ?: null,
        );

        return back()->with('saved', __('finance::message.withdrawal_posted', [
            'no' => $withdrawal->document_no,
        ]));
    }

    public function cap(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            /*
             * ⓘ দুইটা পথ, একটাই লাগে — তালিকা থেকে বাছা, নয় নতুন নাম।
             * ⚠️ `exists`-এ `company_id`, নাহলে অন্য কোম্পানির আইডি বসিয়ে
             * দিলে সেই মানুষের নামে এখানকার টাকা বেরোত।
             */
            'person_id' => ['nullable', 'integer', 'required_without:person_new',
                Rule::exists('mdm_people', 'id')->where('company_id', $companyId)],
            'person_new' => ['nullable', 'string', 'max:120', 'required_without:person_id'],
            'person_mobile' => ['nullable', 'string', 'max:32'],
            'monthly_cap' => ['nullable', 'numeric', 'min:0'],
        ]);

        /*
         * ⛔ সীমাটা এখন ব্যক্তির সারির উপর বসে, নামের উপর নয় — আর এটাই
         * এই ফাইলের সবচেয়ে জরুরি বদল (১৩ সেপ্টেম্বর ২০২৬)।
         *
         * আগে সীমা বসত নামে, আর উত্তোলনও মেলানো হত নামে। বানান এক অক্ষর
         * আলাদা হলেই সীমাটা খুঁজে পাওয়া যেত না, আর
         * [[App\Modules\Finance\Services\WithdrawalService::assertWithinCap]]
         * চুপচাপ `return` করত — অর্থাৎ সীমা **একেবারেই বসত না**, কোনো
         * বার্তা ছাড়া। টাকা বেরিয়ে যাওয়ার একটা নীরব পথ।
         */
        $personId = $this->people->resolve($data);

        $this->withdrawals->setCap((int) $personId, $data['monthly_cap'] ?? null);

        return back()->with('saved', __('finance::message.withdrawal_cap_set', [
            'who' => Person::query()->whereKey($personId)->value('name_en') ?? '',
        ]));
    }

    /** @return Collection<int, Account> */
    /**
     * নামের তালিকা — বাছাইয়ের ঘরে আর "কার মাধ্যমে" ঘরে, একই উৎস।
     *
     * ⓘ দুইটা তালিকা আলাদা করে বানানো হয়নি: যিনি টাকা তোলেন আর যিনি
     * বয়ে নিয়ে যান, দুইজনই পক্ষের নিবন্ধনের সারি। ⚠️ আলাদা করলে একই
     * মানুষ দুই জায়গায় দুই নামে থাকতেন।
     *
     * @return array<int, string>
     */
    private function peopleForPicker(): array
    {
        return Person::query()->active()->orderBy('name_en')
            ->pluck('name_en', 'id')->all();
    }

    private function moneyAccounts(): Collection
    {
        return Account::query()
            ->where('is_group', false)
            ->whereIn('parent_id', Account::query()
                ->whereIn('code', StandardChart::MONEY_PARENTS)->select('id'))
            ->orderBy('code')->get();
    }
}
