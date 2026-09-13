<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\CommissionClaim;
use App\Modules\Sales\Services\CommissionClaimService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ডিলারের কমিশন — দেওয়া, আর কোম্পানির সিদ্ধান্ত বসানো।
 *
 * ── কেন এক পর্দায় ─────────────────────────────────────────────────
 * তালিকাটাই আসল কাগজ: মাস শেষে কোম্পানির লোক বসে সারি ধরে ধরে বলেন
 * কোনটা মানা হলো, কোনটা নয়। আলাদা পর্দায় পাঠালে প্রতিটা সিদ্ধান্তে
 * দুইবার করে যাওয়া-আসা করতে হত।
 *
 * ⓘ তৈরির ফর্মটা আলাদা পাতায় ([[CommissionClaimController::create()]]),
 * আর উপরে বাঁ কোণে তার বোতাম — বিক্রয় বিলের মতোই। আগে ওটা তালিকার
 * নিচে গোঁজা ছিল, আর উপরে কোনো বোতাম ছিল না।
 */
class CommissionClaimController extends Controller implements HasMiddleware
{
    use SortsLists;

    public function __construct(
        private readonly CommissionClaimService $claims,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.commission.view', only: ['index']),
            new Middleware('can:sales.commission.manage', only: ['create', 'store', 'settle', 'reject']),
        ];
    }

    public function index(Request $request): View
    {
        $query = CommissionClaim::query()
            ->with(['customer', 'supplier', 'invoice'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('supplier'), fn ($q, $s) => $q->where('supplier_id', $s));

        $sort = $this->applySort($query, $request, $this->sorts());

        return view('sales::commission.index', [
            'menu' => $this->menu->forUser($request->user()),
            'claims' => $query->paginate(50)->withQueryString(),
            /*
             * ⓘ ডিলার ও কোম্পানির তালিকা দুইটা আর এখানে নয় — ফর্মটা
             * `create`-এ সরার পর তালিকার পাতায় ওগুলোর কোনো ব্যবহারকারী
             * নেই, অথচ প্রতিবার দুইটা পুরো টেবিল পড়া হত।
             */
            'status' => $request->query('status'),
            'sortOptions' => $this->sortLabels(),
            'sort' => $sort,

            /*
             * এখনো কত টাকা কোম্পানির কাছে আটকে।
             *
             * এটাই পাতাটার একমাত্র যোগফল, আর মাস শেষে এটাই প্রথম
             * সংখ্যা যেটা কোম্পানির লোককে বলা হয়।
             */
            'pendingTotal' => (string) (CommissionClaim::query()->pending()->sum('amount') ?: '0'),
        ]);
    }

    /**
     * নতুন কমিশন বসানোর পর্দা।
     *
     * ⭐ কেবল ফর্মের দুইটা তালিকা (ডিলার ও কোম্পানি) — দাবির সারিগুলো
     * আর উপরের যোগফলটা এখানে তোলা হয় না: এই পাতায় একটাও সারি দেখানো
     * হয় না, আর যোগফলটা তালিকার পাতার নিজের প্রশ্ন।
     */
    public function create(Request $request): View
    {
        return view('sales::commission.create', [
            'menu' => $this->menu->forUser($request->user()),
            'customers' => Customer::query()->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_bn']),
            'suppliers' => Supplier::query()->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_bn']),
        ]);
    }

    /** @return array<string, \Closure> */
    private function sorts(): array
    {
        return [
            'latest' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'amount' => fn ($q) => $q->orderByDesc('amount'),
        ];
    }

    /** @return array<string, string> */
    private function sortLabels(): array
    {
        return [
            'latest' => __('sales::sort.latest'),
            'oldest' => __('sales::sort.oldest'),
            'amount' => __('sales::sort.amount'),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'customer_id' => ['required', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'supplier_id' => ['required', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'sales_invoice_id' => ['nullable', 'integer',
                Rule::exists('sal_invoices', 'id')->where('company_id', $companyId)],

            /*
             * ভিত্তি, হার ও টাকা — তিনটাই ঐচ্ছিক এখানে, আর সেবাই ঠিক
             * করে কোনটা ছাড়া চলবে না। নিয়মটা দুই জায়গায় লিখলে একদিন
             * একটা বদলাত আর অন্যটা নয়।
             */
            'base_amount' => ['nullable', 'numeric', 'min:0'],
            'rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rate_amount' => ['nullable', 'numeric', 'min:0'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        $claim = $this->claims->create($data);

        return back()->with('saved', __('sales::message.commission_saved', ['no' => $claim->document_no]));
    }

    public function settle(Request $request, CommissionClaim $claim): RedirectResponse
    {
        $this->claims->settle($claim);

        return back()->with('saved', __('sales::message.commission_settled'));
    }

    public function reject(Request $request, CommissionClaim $claim): RedirectResponse
    {
        $data = $request->validate([
            'decision_reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $this->claims->reject($claim, $data['decision_reason']);

        return back()->with('saved', __('sales::message.commission_rejected'));
    }
}
