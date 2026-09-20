<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\MasterData\Models\Person;
use App\Modules\Supplier\Models\Supplier;
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
    public function __construct(
        private readonly FixedAssetService $assets,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.asset.view', only: ['index', 'show']),
            new Middleware('can:accounts.asset.manage', only: ['create', 'store', 'depreciate', 'dispose']),
        ];
    }

    public function index(Request $request): View
    {
        $assets = FixedAsset::query()
            ->with(['assetAccount'])
            // খোঁজা — নাম, কাগজের নম্বর আর গায়ের ট্যাগ; গুদামে দাঁড়িয়ে
            // মানুষের হাতে ট্যাগ নম্বরটাই থাকে।
            ->when(trim((string) $request->query('q')) ?: null, fn ($query, $term) => $query->where(
                fn ($w) => $w->where('name', 'like', "%{$term}%")
                    ->orWhere('document_no', 'like', "%{$term}%")
                    ->orWhere('tag_no', 'like', "%{$term}%")
            ))
            ->orderByDesc('acquired_on')
            ->paginate(50)
            ->withQueryString();

        return view('accounts::asset.index', [
            'menu' => $this->menu->forUser($request->user()),
            'assets' => $assets,
            'q' => $request->query('q'),
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

            /*
             * ⭐ "টাকাটা কোথা থেকে এল" — ২০ সেপ্টেম্বর ২০২৬, মালিকের
             * *"ok tik koro"*। তিনটা তালিকা, কারণ উত্তরটা তিন রকম হতে
             * পারে: কোন মানুষ, কোন খাত, নাকি কোন বিক্রেতা।
             */
            'people' => Person::query()->active()->orderBy('name_en')->get(),
            'moneyAccounts' => Account::query()->money()->active()->orderBy('code')->get(),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name_en')->get(),
        ]);
    }

    public function show(Request $request, FixedAsset $asset): View
    {
        return view('accounts::asset.show', [
            'menu' => $this->menu->forUser($request->user()),
            'asset' => $asset->load(['depreciation', 'assetAccount']),
            // `money()` নিজেই দল ছাঁকে, তাই আলাদা `postable()` লাগে না
            'moneyAccounts' => Account::query()
                ->money()->active()->orderBy('code')->get(),
            'nextAmount' => $this->assets->monthlyAmount($asset),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'tag_no' => ['nullable', 'string', 'max:64'],
            'asset_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'cost' => ['required', 'numeric', 'gt:0'],
            'salvage' => ['nullable', 'numeric', 'min:0'],
            'acquired_on' => ['required', 'date'],
            'method' => ['required', Rule::in([FixedAsset::STRAIGHT_LINE, FixedAsset::REDUCING])],
            'life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'narration' => ['nullable', 'string', 'max:500'],

            /*
             * ⓘ উৎসটা বাধ্যতামূলক — "কিছু বলিনি" বলে আর পার পাওয়া যায় না।
             * ⛔ আগে ঘরটাই ছিল না, আর তাতেই পাঁচ লাখ টাকার সম্পদ খাতার
             * বাইরে থেকে যেত ([[FixedAssetService::register()]])।
             */
            'funded_by' => ['required', Rule::in(FixedAssetService::FUNDING_WAYS)],
            'funding_person_id' => [
                Rule::requiredIf(fn () => $request->input('funded_by') === FixedAssetService::FUNDED_CAPITAL),
                'nullable', 'integer', 'exists:mdm_people,id',
            ],
            'funding_account_id' => [
                Rule::requiredIf(fn () => $request->input('funded_by') === FixedAssetService::FUNDED_MONEY),
                'nullable', 'integer', 'exists:accounts,id',
            ],
            'funding_supplier_id' => [
                Rule::requiredIf(fn () => $request->input('funded_by') === FixedAssetService::FUNDED_CREDIT),
                'nullable', 'integer', 'exists:suppliers,id',
            ],
        ]);

        $asset = $this->assets->register([
            ...$data,
            'salvage' => $data['salvage'] ?? 0,
            'accumulated_account_id' => Account::query()
                ->where('code', StandardChart::ACCUMULATED_DEPRECIATION)->value('id'),
            'expense_account_id' => Account::query()
                ->where('code', StandardChart::DEPRECIATION_EXPENSE)->value('id'),
        ]);

        return redirect()
            ->route('accounts.asset.show', $asset)
            ->with('status', __('accounts::asset.registered'));
    }

    /** মাস শেষের দৌড় — সব সচল সম্পদে একবারে। */
    public function depreciate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $result = $this->assets->runFor($data['month'].'-01');

        return back()->with('status', __('accounts::asset.run_done', [
            'posted' => $result['posted'],
            'skipped' => $result['skipped'],
        ]));
    }

    public function dispose(Request $request, FixedAsset $asset): RedirectResponse
    {
        $data = $request->validate([
            'disposal_amount' => ['required', 'numeric', 'min:0'],
            'into_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'disposed_on' => ['required', 'date'],
        ]);

        $this->assets->dispose(
            $asset,
            (string) $data['disposal_amount'],
            (int) $data['into_account_id'],
            $data['disposed_on'],
        );

        return back()->with('status', __('accounts::asset.disposed'));
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
