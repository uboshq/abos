<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AssetCategory;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Services\AssetCategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ সম্পদের শ্রেণি — তালিকা, নতুন, বদল (স্থায়ী সম্পদ ধাপ ১, মালিক, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ শ্রেণি বানানো হিসাবের নীতি — তাই `accounts.asset.manage`। খাতের ধরন মেলানো [[AssetCategoryService]]-এ, এখানে নয়।
 */
class AssetCategoryController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly AssetCategoryService $categories,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.asset.manage')];
    }

    public function index(Request $request): View
    {
        return view('accounts::asset.category.index', [
            'menu' => $this->menu->forUser($request->user()),
            'categories' => AssetCategory::query()
                ->with(['assetAccount', 'expenseAccount'])
                ->withCount('assets')
                ->orderBy('code')
                ->paginate(50)->withQueryString(),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new AssetCategory(['method' => FixedAsset::STRAIGHT_LINE, 'residual_percent' => 0, 'is_active' => true]));
    }

    public function edit(Request $request, AssetCategory $category): View
    {
        return $this->form($request, $category);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->categories->create($this->validated($request));

        return redirect()->route('accounts.asset.category.index')->with('status', __('accounts::asset.category_saved'));
    }

    public function update(Request $request, AssetCategory $category): RedirectResponse
    {
        $this->categories->update($category, $this->validated($request));

        return redirect()->route('accounts.asset.category.index')->with('status', __('accounts::asset.category_saved'));
    }

    private function form(Request $request, AssetCategory $category): View
    {
        return view('accounts::asset.category.form', [
            'menu' => $this->menu->forUser($request->user()),
            'category' => $category,
            'accounts' => $this->accountsByType(),
        ]);
    }

    /** @return array<string, Collection<int, Account>> ধরন → পোস্টযোগ্য সক্রিয় খাত */
    private function accountsByType(): array
    {
        $all = Account::query()->postable()->active()->whereNull('money_kind')->orderBy('code')->get();

        return [
            Account::ASSET => $all->where('type', Account::ASSET)->values(),
            Account::EXPENSE => $all->where('type', Account::EXPENSE)->values(),
            Account::INCOME => $all->where('type', Account::INCOME)->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'asset_account_id' => ['required', 'integer'],
            'accumulated_account_id' => ['required', 'integer'],
            'expense_account_id' => ['required', 'integer'],
            'gain_account_id' => ['nullable', 'integer'],
            'loss_account_id' => ['nullable', 'integer'],
            'impairment_account_id' => ['nullable', 'integer'],
            'method' => ['required', Rule::in(FixedAsset::METHODS)],
            'life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'residual_percent' => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'capitalisation_threshold' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['gain_account_id' => null, 'loss_account_id' => null, 'impairment_account_id' => null]
            // ⓘ টিক তুলে নিলে ঘরটা আসেই না — তাই হাতে, নইলে বন্ধ করা শ্রেণি কখনো বন্ধ হত না
            + ['is_active' => $request->boolean('is_active')];
    }
}
