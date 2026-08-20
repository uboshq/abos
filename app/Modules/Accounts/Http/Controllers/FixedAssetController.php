<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\FixedAsset;
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
    public function __construct(
        private readonly FixedAssetService $assets,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.asset.view', only: ['index', 'show']),
            new Middleware('can:accounts.asset.manage', only: ['store', 'depreciate', 'dispose']),
        ];
    }

    public function index(Request $request): View
    {
        $assets = FixedAsset::query()
            ->with(['assetAccount'])
            ->orderByDesc('acquired_on')
            ->paginate(50)
            ->withQueryString();

        return view('accounts::asset.index', [
            'menu' => $this->menu->forUser($request->user()),
            'assets' => $assets,
            'assetAccounts' => $this->under(StandardChart::FIXED_ASSETS),
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

    public function show(Request $request, FixedAsset $asset): View
    {
        return view('accounts::asset.show', [
            'menu' => $this->menu->forUser($request->user()),
            'asset' => $asset->load(['depreciation', 'assetAccount']),
            'moneyAccounts' => Account::query()
                ->where(fn ($q) => $q->where('is_cash', true)->orWhere('is_bank', true))
                ->postable()->active()->orderBy('code')->get(),
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
