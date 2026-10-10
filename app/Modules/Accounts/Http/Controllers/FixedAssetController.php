<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Concerns\GrandTotals;
use App\Core\Contracts\CapitalisesABillLine;
use App\Core\Services\MenuBuilder;
use App\Core\Services\OpenPeriod;
use App\Core\Services\PartyRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AssetCategory;
use App\Modules\Accounts\Models\AssetCostPart;
use App\Modules\Accounts\Models\AssetEvent;
use App\Modules\Accounts\Models\AssetTransfer;
use App\Modules\Accounts\Models\DepreciationRun;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Services\AssetEventService;
use App\Modules\Accounts\Services\DepreciationEngine;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * স্থায়ী সম্পদের খাতা।
 *
 * তালিকাই প্রধান পর্দা, আর উপরে মাস শেষের দৌড়ের বোতাম — কারণ এই
 * খাতাটার সাথে মানুষের দেখা হয় মাসে একবার, ওই দৌড়টা চালাতেই।
 */
class FixedAssetController extends Controller implements HasMiddleware
{
    use GrandTotals;

    public function __construct(
        private readonly FixedAssetService $assets,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.asset.view', only: ['index', 'show', 'run']),
            // ⓘ সম্পদের নীতিও পথ থেকে পৌঁছায় — একই চাবি, নীতির ভাষায় ([[FixedAssetPolicy]]; ধাপ ১)
            new Middleware('can:view,asset', only: ['show']),
            new Middleware('can:create,'.FixedAsset::class, only: ['create', 'store']),
            new Middleware('can:accounts.asset.manage', only: ['create', 'store', 'depreciate', 'dispose', 'transfer', 'status', 'preview', 'estimate', 'usage', 'event']),
        ];
    }

    public function index(Request $request): View
    {
        $query = FixedAsset::query()
            ->with(['assetAccount', 'category'])
            // ⭐ শ্রেণি আর অবস্থা ধরে ছাঁকা (স্থায়ী সম্পদ ধাপ ১)
            ->when($request->integer('category') ?: null, fn ($q, $id) => $q->where('category_id', $id))
            ->when(in_array($request->query('status'), FixedAsset::STATUSES, true) ? $request->query('status') : null,
                fn ($q, $status) => $q->where('status', $status))
            // খোঁজা — নাম, কাগজের নম্বর আর গায়ের ট্যাগ; গুদামে দাঁড়িয়ে
            // মানুষের হাতে ট্যাগ নম্বরটাই থাকে।
            ->when(trim((string) $request->query('q')) ?: null, fn ($query, $term) => $query->where(
                fn ($w) => $w->where('name', 'like', "%{$term}%")
                    ->orWhere('document_no', 'like', "%{$term}%")
                    ->orWhere('tag_no', 'like', "%{$term}%")
            ))
            ->orderByDesc('acquired_on');

        return view('accounts::asset.index', [
            'menu' => $this->menu->forUser($request->user()),
            'assets' => (clone $query)->paginate(50)->withQueryString(),
            // ⭐ যোগফলের পট্টি — ছাঁকা সব সম্পদের কেনা দাম, পাতার নয় (মালিক, ৫ অক্টোবর ২০২৬)
            'grand' => $this->grandTotals($query, ['cost' => 't.cost']),
            'q' => $request->query('q'),
            'categories' => AssetCategory::query()->orderBy('code')->get(),
            /*
             * ⓘ সম্পদের খাতের তালিকাটা আর এখানে নয় — ফর্মটা `create`-এ
             * সরার পর তালিকার পাতায় ওটার কোনো ব্যবহারকারী নেই।
             */
            'accumulated' => Account::query()
                ->where('code', StandardChart::ACCUMULATED_DEPRECIATION)->first(),
            'expense' => Account::query()
                ->where('code', StandardChart::DEPRECIATION_EXPENSE)->first(),

            /*
             * চলতি মাসের আগের মাস — দৌড়ের ডিফল্ট।
             *
             * অবচয় বসে মাস শেষ হওয়ার পরে, কারণ চলতি মাসটা এখনো শেষ
             * হয়নি। ডিফল্টে চলতি মাস দিলে প্রতি মাসে কেউ না কেউ
             * অর্ধেক মাসের ক্ষয় পুরো মাস হিসেবে বসিয়ে ফেলতেন।
             */
            'defaultMonth' => Carbon::today()->subMonthNoOverflow()->format('Y-m'),
        ]);
    }

    /**
     * নতুন সম্পদ বসানোর পর্দা।
     *
     * ⭐ কেবল খাতের তালিকাটা — সম্পদের সারিগুলো, আর মাস শেষের দৌড়ের
     * `defaultMonth` এখানে লাগে না: দৌড়টা তালিকার পাতাতেই থাকে
     * (রুট ফাইলে কারণটা লেখা), আর ফর্মটা একটাও সারি দেখায় না।
     *
     * ⓘ `accumulated` ও `expense` খাত দুইটাও নয় — ওগুলো ফর্মের ঘর নয়,
     * `store()` নিজেই কোড ধরে খুঁজে নেয়।
     */
    public function create(Request $request): View
    {
        return view('accounts::asset.create', [
            'menu' => $this->menu->forUser($request->user()),
            'assetAccounts' => $this->under(StandardChart::FIXED_ASSETS),

            // ⭐ নিবন্ধনের নতুন ঘর — শ্রেণি, মূল সম্পদ, দায়িত্বের কর্মী, ক্রয় বিলের সারি (স্থায়ী সম্পদ ধাপ ১)
            'categories' => AssetCategory::query()->active()->orderBy('code')->get(),
            'parents' => FixedAsset::query()->inService()->whereNull('parent_id')->orderBy('document_no')->limit(500)->get(['id', 'document_no', 'name']),
            'employees' => $this->partyList('employee'),
            'branches' => Branch::query()->orderBy('code')->get(),
            'billLines' => app(CapitalisesABillLine::class)->lines($request->query('bill_q'), 200),
            'pickedLine' => $request->integer('bill_line') ?: null,

            /*
             * ⭐ "টাকাটা কোথা থেকে এল" — ২০ সেপ্টেম্বর ২০২৬, মালিকের
             * *"ok tik koro"*। তিনটা তালিকা, কারণ উত্তরটা তিন রকম হতে
             * পারে: কোন মানুষ, কোন খাত, নাকি কোন বিক্রেতা।
             */
            /*
             * ⭐ পক্ষের তালিকা কোর থেকে — ২১ সেপ্টেম্বর ২০২৬, সীমারেখার নিরীক্ষা।
             *
             * ── ⚠️ আগে কী ছিল ───────────────────────────────────────
             * `MasterData\Models\Person` আর `Supplier\Models\Supplier`
             * সরাসরি ডাকা হত। ⛔ কিন্তু accounts প্রায় সবার নিচের স্তরে;
             * ঐ দুইটাই **accounts-এর উপর দাঁড়িয়ে আছে**, তাই নির্ভরতাটা
             * ঘোষণা করলে চক্র হত আর রেজিস্ট্রি বুট-টাইমেই থামাত।
             *
             * ⭐ এখানে কোনো নতুন চুক্তি লাগেনি: কোর আগে থেকেই জানে
             * "পক্ষ কারা" ([[PartyRegistry]]), আর প্রতিটা মডিউল নিজের
             * `module.php`-তে `parties` ঘোষণা করে সেটা ভরে দেয়।
             * ⓘ অর্থাৎ নির্ভরতাটা উল্টাতে হয়নি — **সরানো** গেছে।
             */
            'people' => $this->partyList('person'),
            'moneyAccounts' => Account::query()->money()->active()->orderBy('code')->get(),
            'suppliers' => $this->partyList('supplier'),
        ]);
    }

    /**
     * এক ধরনের পক্ষের তালিকা — `[id => নাম]`।
     *
     * ⓘ কোর নিজে কোনো মডিউলের নাম জানে না; ধরনগুলো আসে মডিউলের
     * ঘোষণা থেকে ([[PartyRegistry::forPicker()]])। ⚠️ ধরনটা না থাকলে
     * খালি তালিকা — মডিউলটা বন্ধ থাকলে ঘরটা ফাঁকা দেখায়, পাতা ভাঙে না।
     *
     * @return array<int, string>
     */
    private function partyList(string $type): array
    {
        $group = collect(app(PartyRegistry::class)->forPicker())->firstWhere('type', $type);

        return collect($group['options'] ?? [])
            ->mapWithKeys(fn (array $row) => [$row['id'] => $row['label']])
            ->all();
    }

    public function show(Request $request, FixedAsset $asset): View
    {
        return view('accounts::asset.show', [
            'menu' => $this->menu->forUser($request->user()),
            'asset' => $asset->load(['depreciation', 'assetAccount', 'category', 'parent', 'components', 'costParts', 'branch', 'usages', 'estimateChanges.creator', 'events', 'acknowledgements']),
            // ⓘ পক্ষের নাম কোর থেকে — কর্মী আর বিক্রেতা ([[PartyRegistry]]), মডিউলের মডেল থেকে নয়
            'custodian' => $asset->custodian_id === null ? null
                : (app(PartyRegistry::class)->labelsOf([['employee', (int) $asset->custodian_id]])['employee:'.$asset->custodian_id] ?? null),
            'supplier' => $asset->supplier_id === null ? null
                : (app(PartyRegistry::class)->labelsOf([['supplier', (int) $asset->supplier_id]])['supplier:'.$asset->supplier_id] ?? null),
            'billLine' => $asset->purchase_bill_line_id === null ? null : app(CapitalisesABillLine::class)->line((int) $asset->purchase_bill_line_id),
            // `money()` নিজেই দল ছাঁকে, তাই আলাদা `postable()` লাগে না
            'moneyAccounts' => Account::query()
                ->money()->active()->orderBy('code')->get(),
            'nextAmount' => $this->assets->monthlyAmount($asset),

            /*
             * ⭐ শাখা বদলের তালিকা — মানচিত্র §১৫, ২১ সেপ্টেম্বর ২০২৬।
             * ⓘ চলতি শাখাটা বাদ: "যেখানে আছে সেখানেই পাঠাও" কোনো কাজ নয়,
             * আর সেবাও ওটা ফিরিয়ে দেয়।
             */
            'branches' => Branch::query()
                ->where('id', '!=', $asset->branch_id)
                ->orderBy('code')->get(),

            // ⭐ ধাপ ৩: ঘটনার ফর্ম আর কর্মী বদলের তালিকা
            'employees' => $employees = $this->partyList('employee'),
            'people' => $employees,
            'suppliers' => $this->partyList('supplier'),
            'expenseAccounts' => Account::query()->postable()->active()->ofType(Account::EXPENSE)->orderBy('code')->get(),
            'equityAccounts' => Account::query()->postable()->active()->ofType(Account::EQUITY)->orderBy('code')->get(),
            'revaluationOn' => (bool) app(SettingsService::class)->get(AssetEventService::REVALUATION, false),

            /* ⓘ কোথায় কোথায় ছিল — ইতিহাসটাই এই ঘরটার আসল দাম */
            'moves' => AssetTransfer::query()
                ->where('asset_id', $asset->id)
                ->with(['fromBranch', 'toBranch', 'creator'])
                ->orderByDesc('moved_on')->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'tag_no' => ['nullable', 'string', 'max:64'],
            // ⓘ শ্রেণি থাকলে খাত শ্রেণি থেকে; দামের ভাগ বা ক্রয় বিল থাকলে দাম সেখান থেকে (ধাপ ১)
            'asset_account_id' => ['nullable', 'required_without:category_id', 'integer', Rule::exists('accounts', 'id')->where('company_id', CompanyContext::id())],
            'cost' => ['nullable', 'numeric', 'gt:0',
                Rule::requiredIf(fn () => ! $request->filled('cost_parts.0.amount') && $request->input('funded_by') !== FixedAssetService::FUNDED_BILL)],
            'salvage' => ['nullable', 'numeric', 'min:0'],
            'acquired_on' => ['required', 'date'],

            /*
             * ⭐ এ পর্যন্ত যতটা ক্ষয় ধরা হয়েছে — পুরনো জিনিস তোলার ঘর।
             * ⚠️ কেনার দামের বেশি হতে পারে না: হলে খাতায় জিনিসটার
             * দাম ঋণাত্মক হয়ে যেত।
             */
            'opening_accumulated' => ['nullable', 'numeric', 'min:0', 'lte:cost'],
            'method' => ['nullable', 'required_without:category_id', Rule::in(FixedAsset::METHODS)],
            'life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // ⭐ ব্যবহারের এককে মোট একক (ধাপ ২)
            'total_units' => ['nullable', 'numeric', 'gt:0'],
            'narration' => ['nullable', 'string', 'max:500'],

            // ⭐ নিবন্ধনের নতুন ঘর — স্থায়ী সম্পদ ধাপ ১ (IAS 16)
            'category_id' => ['nullable', 'integer', Rule::exists('acc_asset_categories', 'id')->where('company_id', CompanyContext::id())],
            'parent_id' => ['nullable', 'integer', Rule::exists('acc_fixed_assets', 'id')->where('company_id', CompanyContext::id())],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', CompanyContext::id())],
            'location' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'custodian_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'put_in_use_on' => ['nullable', 'date', 'after_or_equal:acquired_on'],
            'serial_no' => ['nullable', 'string', 'max:120'],
            'model_no' => ['nullable', 'string', 'max:120'],
            'warranty_ends_on' => ['nullable', 'date'],
            'insurance_policy_no' => ['nullable', 'string', 'max:64'],
            'insured_until' => ['nullable', 'date'],
            'cost_parts' => ['nullable', 'array', 'max:10'],
            'cost_parts.*.kind' => ['required_with:cost_parts.*.amount', Rule::in(AssetCostPart::KINDS)],
            'cost_parts.*.amount' => ['nullable', 'numeric', 'min:0'],
            'cost_parts.*.note' => ['nullable', 'string', 'max:255'],
            'purchase_bill_line_id' => ['nullable', 'integer', 'required_if:funded_by,'.FixedAssetService::FUNDED_BILL],
            'capitalised_qty' => ['nullable', 'numeric', 'gt:0', 'required_if:funded_by,'.FixedAssetService::FUNDED_BILL],

            /*
             * ⓘ উৎসটা বাধ্যতামূলক — "কিছু বলিনি" বলে আর পার পাওয়া যায় না।
             * ⛔ আগে ঘরটাই ছিল না, আর তাতেই পাঁচ লাখ টাকার সম্পদ খাতার
             * বাইরে থেকে যেত ([[FixedAssetService::register()]])।
             */
            'funded_by' => ['required', Rule::in(FixedAssetService::FUNDING_WAYS)],
            'funding_person_id' => [
                Rule::requiredIf(fn () => $request->input('funded_by') === FixedAssetService::FUNDED_CAPITAL),
                'nullable', 'integer', Rule::exists('mdm_people', 'id')->where('company_id', CompanyContext::id()),
            ],
            'funding_account_id' => [
                Rule::requiredIf(fn () => $request->input('funded_by') === FixedAssetService::FUNDED_MONEY),
                'nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', CompanyContext::id()),
            ],
            'funding_supplier_id' => [
                Rule::requiredIf(fn () => $request->input('funded_by') === FixedAssetService::FUNDED_CREDIT),
                'nullable', 'integer', Rule::exists('suppliers', 'id')->where('company_id', CompanyContext::id()),
            ],
        ]);

        /*
         * ⓘ শ্রেণি থাকলে তার খাত-জোড়া ([[FixedAssetService::register()]] খালি ঘর ভরে); না থাকলে আগের মতো প্রমিত ১২৯০/৫২১২।
         * শেষ দাম খালি রাখলে শ্রেণির হার খাটে — তাই শ্রেণিতে শূন্য বসানো হয় না।
         */
        $asset = $this->assets->register([
            ...$data,
            'cost' => $data['cost'] ?? '0',
            'salvage' => ($data['salvage'] ?? null) ?? (filled($data['category_id'] ?? null) ? null : 0),
            'accumulated_account_id' => filled($data['category_id'] ?? null) ? null : Account::query()
                ->where('code', StandardChart::ACCUMULATED_DEPRECIATION)->value('id'),
            'expense_account_id' => filled($data['category_id'] ?? null) ? null : Account::query()
                ->where('code', StandardChart::DEPRECIATION_EXPENSE)->value('id'),
        ]);

        // ⓘ সইয়ের অপেক্ষায় থাকলে "নিবন্ধিত" বলা মিথ্যা হত ([[AccountsSignature]])
        return redirect()
            ->route('accounts.asset.show', $asset)
            ->with('status', $asset->isAwaiting() ? __('accounts::asset.awaiting_signature') : __('accounts::asset.registered'));
    }

    /** মাস শেষের দৌড় — সব সচল সম্পদে একবারে। */
    public function depreciate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $result = $this->assets->runFor($data['month'].'-01');

        return redirect()->route('accounts.asset.run.preview', ['month' => $data['month']])->with('status', __('accounts::asset.run_done', [
            'posted' => $result['posted'],
            'skipped' => $result['skipped'],
        ]));
    }

    /**
     * ⭐ মাসের দৌড় আগে দেখা — কোন সম্পদে কত, কেন কোনোটায় শূন্য; নিচে এই মাসে বসা কাগজগুলো (স্থায়ী সম্পদ ধাপ ২)।
     * ⓘ কিছু লেখে না; "বসান" চাপলে ঠিক এই সারিগুলোই বসে ([[DepreciationEngine::preview()]])।
     */
    public function preview(Request $request): View
    {
        $month = DepreciationEngine::assertMonth($request->query('month', Carbon::today()->subMonthNoOverflow()->format('Y-m')));
        $rows = app(DepreciationEngine::class)->preview($month);

        return view('accounts::asset.run.preview', [
            'menu' => $this->menu->forUser($request->user()),
            'month' => $month,
            'rows' => $rows,
            'total' => $rows->reduce(fn (string $sum, array $row) => bcadd($sum, $row['amount'], 4), '0'),
            'runs' => DepreciationRun::acrossBranches()->with('branch')->where('period_end', $month->toDateString())->orderBy('branch_key')->get(),
            'open' => app(OpenPeriod::class)->isOpen($month),
        ]);
    }

    /** ⭐ এক শাখার এক মাসের অবচয়ের কাগজ — ভেতরে সম্পদ ধরে সারি (ধাপ ২) */
    public function run(Request $request, DepreciationRun $run): View
    {
        return view('accounts::asset.run.show', [
            'menu' => $this->menu->forUser($request->user()),
            'run' => $run->load(['branch', 'entries.asset.category']),
        ]);
    }

    /** ⭐ আয়ু, শেষ দাম, পদ্ধতি, হার বা মোট একক বদল — আগামীর দিকে, কারণসহ (ধাপ ২) */
    public function estimate(Request $request, FixedAsset $asset): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['nullable', Rule::in(FixedAsset::METHODS)],
            'life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'salvage' => ['nullable', 'numeric', 'min:0'],
            'rate' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'total_units' => ['nullable', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->assets->changeEstimate($asset, $data, (string) $data['reason']);

        return back()->with('status', __('accounts::asset.estimate_changed'));
    }

    /** ⭐ এক মাসে কত একক চলল — ব্যবহারের এককে ক্ষয়ের জন্য (ধাপ ২) */
    public function usage(Request $request, FixedAsset $asset): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'units' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->assets->recordUsage($asset, $data['month'].'-01', (string) $data['units'], ($data['note'] ?? null) ?: null);

        return back()->with('status', __('accounts::asset.usage_saved'));
    }

    /**
     * ⭐ সম্পদ এক শাখা থেকে আরেক শাখায় — মানচিত্র §১৫।
     *
     * ⓘ তারিখটা বাধ্যতামূলক আর ভবিষ্যতে নয়: দাখিলা ঐ তারিখেই বসে, আর
     * কাল-পরশুর তারিখে বসালে চলতি মাসের স্থিতিপত্র ভুল বলত।
     */
    public function transfer(Request $request, FixedAsset $asset): RedirectResponse
    {
        $data = $request->validate([
            // ⓘ খালি মানে একই শাখা — কেবল কর্মী বা জায়গা বদল (ধাপ ৩)
            'to_branch_id' => ['nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', CompanyContext::id())],
            'custodian_id' => ['nullable', 'integer'],
            'location' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'moved_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $move = $this->assets->transfer(
            $asset,
            filled($data['to_branch_id'] ?? null) ? (int) $data['to_branch_id'] : null,
            (string) $data['moved_on'],
            ($data['note'] ?? '') ?: null,
            [
                'custodian_id' => filled($data['custodian_id'] ?? null) ? (int) $data['custodian_id'] : null,
                'location' => $data['location'] ?? null,
                'department' => $data['department'] ?? null,
            ],
        );

        return back()->with('saved', $move->movedBranch() ? __('accounts::asset.moved') : __('accounts::asset.custody_moved'));
    }

    /**
     * ⭐ অবস্থা বদল — ব্যবহারে, অলস, মেরামতে (স্থায়ী সম্পদ ধাপ ১)। ⓘ টাকা নড়ে না, তাই সই নয়; কে কবে বদলালেন নিরীক্ষায়
     * ([[IsAudited]])। ⛔ বাতিল, হারানো আর বিক্রি এখান দিয়ে নয় — ওগুলো খাতা থেকে বেরোনো, নিজের পথে সইসহ।
     */
    public function status(Request $request, FixedAsset $asset): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(FixedAsset::SWITCHABLE)]]);

        $this->assets->changeStatus($asset, $data['status']);

        return back()->with('status', __('accounts::asset.status_changed'));
    }

    public function dispose(Request $request, FixedAsset $asset): RedirectResponse
    {
        $data = $request->validate([
            'disposal_amount' => ['required', 'numeric', 'min:0'],
            // ⓘ বাতিল বা হারানোয় টাকা না-ও আসতে পারে — তখন খাত লাগে না (ধাপ ৩)
            'into_account_id' => ['nullable', 'required_unless:disposal_amount,0', 'integer', Rule::exists('accounts', 'id')->where('company_id', CompanyContext::id())],
            'disposed_on' => ['required', 'date'],
            'as' => ['nullable', Rule::in(FixedAsset::LEAVING)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $after = $this->assets->dispose(
            $asset,
            (string) $data['disposal_amount'],
            filled($data['into_account_id'] ?? null) ? (int) $data['into_account_id'] : null,
            $data['disposed_on'],
            $data['as'] ?? FixedAsset::DISPOSED,
            ($data['reason'] ?? '') ?: null,
        );

        // ⓘ সইয়ের অপেক্ষায় সম্পদটা এখনো চালু — "বিক্রি হয়েছে" বলা মিথ্যা হত ([[AccountsSignature]])
        return back()->with('status', $after->isInService() ? __('accounts::asset.awaiting_signature') : __('accounts::asset.disposed'));
    }

    /**
     * ⭐ সম্পদের ঘটনা — সংযোজন, মেরামত, পুনর্মূল্যায়ন, দাম পড়া (স্থায়ী সম্পদ ধাপ ৩; [[AssetEventService]])।
     * ⓘ নিয়ম সেবায়; এখানে কেবল ঘরের আকার। টাকা নড়লে সই চাওয়া হয়, তাই বার্তা দুই রকম।
     */
    public function event(Request $request, FixedAsset $asset, string $kind, AssetEventService $events): RedirectResponse
    {
        abort_unless(in_array($kind, AssetEvent::KINDS, true), 404);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'happened_on' => ['required', 'date', 'before_or_equal:today'],
            'funded_by' => ['nullable', 'string', 'max:20'],
            'funding_account_id' => ['nullable', 'integer'],
            'funding_supplier_id' => ['nullable', 'integer'],
            'charge_account_id' => ['nullable', 'integer'],
            'account_id' => ['nullable', 'integer'],
            'extend_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'reason' => [in_array($kind, [AssetEvent::REVALUATION, AssetEvent::IMPAIRMENT], true) ? 'required' : 'nullable', 'string', 'max:500'],
        ]);

        $event = match ($kind) {
            AssetEvent::ADDITION => $events->addition($asset, $data),
            AssetEvent::REPAIR => $events->repair($asset, $data),
            AssetEvent::REVALUATION => $events->revalue($asset, $data),
            AssetEvent::IMPAIRMENT => $events->impair($asset, $data),
        };

        return back()->with('status', $event->isAwaiting() ? __('accounts::asset.awaiting_signature') : __('accounts::asset.event_saved'));
    }

    /** @return Collection<int, Account> */
    private function under(string $parentCode): Collection
    {
        $parent = Account::query()->where('code', $parentCode)->first();

        if ($parent === null) {
            return collect();
        }

        return Account::query()
            ->where('parent_id', $parent->id)
            ->postable()->active()->orderBy('code')->get();
    }
}
