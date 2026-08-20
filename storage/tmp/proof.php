<?php
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;

$c = Company::query()->where('code','TDEPOT')->firstOrFail();
CompanyContext::set($c->id, $c->defaultBranch()?->id);
auth()->login(User::query()->where('email','owner@abos.test')->firstOrFail());

$asset = app(FixedAssetService::class)->register([
    'name' => 'Proof van',
    'asset_account_id' => Account::query()->where('code','1202')->value('id'),
    'accumulated_account_id' => Account::query()->where('code',StandardChart::ACCUMULATED_DEPRECIATION)->value('id'),
    'expense_account_id' => Account::query()->where('code',StandardChart::DEPRECIATION_EXPENSE)->value('id'),
    'cost' => '600000', 'salvage' => '0', 'acquired_on' => '2026-08-01',
    'method' => 'straight', 'life_months' => 60,
]);

echo "asset created: {$asset->document_no}\n";

$res = app(\Illuminate\Contracts\Http\Kernel::class)
    ->handle(\Illuminate\Http\Request::create('/accounts/assets','GET'));
$body = $res->getContent();

echo "status: ".$res->getStatusCode()."\n";
echo "নামটা পাতায় আছে? ".(str_contains($body,'Proof van') ? 'হ্যাঁ' : 'না — টেবিল রেন্ডারই হয়নি')."\n";
echo "খালি-অবস্থা দেখাচ্ছে? ".(str_contains($body, __('accounts::asset.empty')) ? 'হ্যাঁ' : 'না')."\n";
