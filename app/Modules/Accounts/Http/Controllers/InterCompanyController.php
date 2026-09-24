<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Http\Requests\InterCompanyRequest;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\InterCompanyTransfer;
use App\Modules\Accounts\Services\InterCompanyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * ভাই-কোম্পানির মধ্যে টাকা — দুই খাতায় এক লেনদেন।
 *
 * ── ⛔ নিজের চাবি, আর কেন ────────────────────────────────────────────
 * ⚠️ এই পর্দাটা **অন্য কোম্পানির খাতায় দাখিলা লেখে**। ⓘ বাকি প্রতিটা
 * পর্দা নিজের কোম্পানির ভেতরে থাকে, তাই `accounts.voucher.create`
 * থাকলেই কেউ যেন এটা পেয়ে না যান।
 *
 * ⭐ আর চাবিটা "সব কোম্পানি" খোলে না — [[InterCompanyService]] প্রতিটা
 * লেখার আগে দেখে নেয় ব্যবহারকারী **দুইটা কোম্পানিতেই** আছেন কি না।
 */
class InterCompanyController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly InterCompanyService $transfers,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.inter_company')];
    }

    public function index(Request $request): View
    {
        $rows = InterCompanyTransfer::query()
            ->with(['counterCompany', 'creator'])
            ->latest('trx_date')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('accounts::inter-company.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,

            /*
             * ⭐ প্রতিটা সারি কী ধরনের ছিল — গোটা পাতার জন্য **একটাই**
             * কোয়েরি। ⛔ মডেলে একটা `kind()` বসিয়ে সারি-প্রতি ডাকলে
             * ৫০টা সারিতে ৫০টা কোয়েরি হত, আর ধীরগতিটা কোথাও লাল হত না।
             */
            'kinds' => InterCompanyService::kindsOf($rows),

        ]);
    }

    /**
     * ফরমটা দুই ধাপে, আর কোনো JS ছাড়া।
     *
     * ── ⚠️ কেন AJAX নয় ─────────────────────────────────────────────
     * ⓘ দ্বিতীয় খাতটা **অন্য কোম্পানির**, আর সেই তালিকা চলতি
     * কোম্পানির স্কোপে পাওয়া যায় না। প্রথম নকশায় একটা আলাদা দরজা
     * ছিল যা প্রসঙ্গ বদলে তালিকাটা ফেরত দিত, আর পর্দা সেটা fetch করত।
     *
     * ⛔ কিন্তু এখানে CSP চালু, আর Alpine-এর সীমিত রূপে ঐ জোড়টা
     * ভঙ্গুর — আর জোড় ভাঙলে কিছুই লাল হয় না, কেবল ড্রপডাউনটা খালি
     * থাকে ([[the-work-is-done-the-wiring-is-not]])।
     *
     * ⭐ তাই ধাপ এক: কোম্পানি বাছা (সাধারণ GET)। ধাপ দুই: বাকি ফরম,
     * যেখানে দুই কোম্পানির খাতই সার্ভার থেকে বসানো। ⓘ একটা ক্লিক বেশি,
     * কিন্তু ভাঙার মতো কোনো সুতো নেই।
     */
    public function create(Request $request): View
    {
        $user = $request->user();
        $own = CompanyContext::id();

        $chosen = $request->integer('counter') ?: null;

        /*
         * ⛔ বাছা কোম্পানিটা সত্যিই তাঁর কি না — এখানেই যাচাই।
         * ⚠️ নাহলে ঠিকানায় যেকোনো আইডি বসিয়ে অন্য ক্রেতার খাতের নাম
         * পড়া যেত, যদিও লেখা আটকাত সেবা স্তর।
         */
        if ($chosen !== null && ! $user->companies()->whereKey($chosen)->exists()) {
            abort(403);
        }

        return view('accounts::inter-company.form', [
            'menu' => $this->menu->forUser($user),
            'chosen' => $chosen,

            /*
             * ⓘ অন্য কোম্পানির টাকার খাত — প্রসঙ্গ বদলে এনে দেওয়া।
             * ⚠️ [[CompanyContext::forCompany()]] `finally`-তে আগের
             * প্রসঙ্গ ফিরিয়ে দেয়, তাই এর পরের কোয়েরিগুলো নিজের
             * কোম্পানিতেই থাকে।
             */
            /*
             * ⭐ তাদের দিকে তিন ধরনের খাত — টাকা, খরচ, দায়।
             *
             * ⓘ কোনটা বাছা হলো তার উপরেই নির্ভর করে কাজটা কী: টাকার খাত
             * মানে টাকা সরানো, খরচের খাত মানে তাদের খরচ দেওয়া, দায়ের
             * খাত মানে তাদের দেনা মেটানো। ⚠️ তিনটাই একই আকারের দাখিলা,
             * তাই আলাদা পর্দা লাগে না — কেবল তালিকাটা চওড়া।
             */
            'theirAccounts' => $chosen === null
                ? collect()
                : CompanyContext::forCompany($chosen, fn () => $this->receivingAccounts()),

            /*
             * ⭐ কেবল সেই কোম্পানিগুলো যেগুলোতে ব্যবহারকারী নিজে আছেন,
             * আর চলতিটা বাদ। ⚠️ `Company::all()` নয় — `companies`
             * টেবিলে কোনো দেয়াল নেই (সে নিজেই কোম্পানি), তাই ঢালাও
             * তালিকা অন্য ক্রেতার নাম এনে ফেলত।
             */
            'companies' => $user->companies()
                ->where('companies.id', '!=', $own)
                ->orderBy('companies.name_en')
                ->get(['companies.id', 'companies.name_en', 'companies.name_bn']),

            'money' => $this->moneyAccounts(),
        ]);
    }

    public function store(InterCompanyRequest $request): RedirectResponse
    {
        $transfer = $this->transfers->record($request->user(), $request->validated());

        return redirect()
            ->route('accounts.inter_company.index')
            ->with('status', __('accounts::message.inter_company_done', [
                'company' => $transfer->counterCompany->name(),
            ]));
    }

    /**
     * দেওয়ার দিকের খাত — কেবল টাকা।
     *
     * @return Collection<int, Account>
     */
    private function moneyAccounts()
    {
        return Account::query()
            ->whereIn('money_kind', Account::MONEY_KINDS)
            ->where('is_group', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_bn']);
    }

    /**
     * পাওয়ার দিকের খাত — টাকা, খরচ বা দায়।
     *
     * ⭐ ধরনগুলো [[InterCompanyService::CAN_RECEIVE]] থেকে নেওয়া, এখানে
     * হাতে লেখা নয়।
     *
     * ⛔ প্রথম লেখায় তালিকাটা এখানে আবার টাইপ করা ছিল, আর পাশে একটা
     * মন্তব্য ছিল *"দুইটা মিলিয়ে রাখতে হবে"*। ⚠️ ওরকম মন্তব্য কোনো
     * পাহারা নয়: একদিন সেবায় একটা ধরন যোগ হত আর পর্দায় হত না, তখন
     * ব্যবহারকারী ঐ খাতটা বাছতেই পারতেন না — আর কেউ বুঝত না কেন।
     * ⓘ তাই ধ্রুবকটা `public`, আর সত্যটা একটাই জায়গায়।
     */
    private function receivingAccounts()
    {
        return Account::query()
            ->whereIn('type', InterCompanyService::CAN_RECEIVE)
            ->where('is_group', false)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_bn', 'type']);
    }
}
