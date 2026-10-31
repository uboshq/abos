<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Metrics\SalesMetrics;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ অন্য কোম্পানির ফেরত আমাদের নিট বিক্রি কমায় না — পণ্য ধরে নিট বিক্রি ([[SalesMetrics::netByProduct()]]) (fe, ২২ অক্টোবর ২০২৬)।
 *
 * ⓘ bundle-3b-এর দৌড়ে কোম্পানির পাহারা ([[EveryRawQueryNamesItsCompanyTest]]) ধরেছিল, ফেরতের সারির কাঁচা কোয়েরিতে কোম্পানির ছাঁকনি
 * নেই। পাহারা গঠন দেখে; এই দাবি আচরণ দেখে: একই দিনে দুই কোম্পানির পাকা ফেরত, আর প্রতিটা কোম্পানি কেবল নিজেরটা দেখে।
 *
 * ⚠️ সৎ কথা: ফেরতের সারি এখন দুই দিক থেকে বাঁধা — `rl.company_id` আর কোম্পানি-স্কোপের ফেরতের সহ-কোয়েরি। তাই কেবল
 * `rl.company_id` সরালে আচরণ বদলায় না (সেটা পাহারার মিউট্যান্ট); দুই বাঁধনই খুললে (কাঁচা সহ-কোয়েরি) অন্যের ফেরত ঢোকে, আর এই দাবি লাল।
 */
final class AnotherCompanysReturnNeverLowersOurNetSalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_company_sees_only_its_own_return_in_net_sales_by_product(): void
    {
        $this->seed(DemoSeeder::class);
        $ours = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $theirs = Company::query()->where('code', 'FMART')->firstOrFail();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $day = now()->toDateString();

        // ⓘ ডেমোর নিজের ফেরত আজকের তারিখে থাকতে পারে — তাই আগে-পরের পার্থক্য দেখা হয়, পুরো তালিকা নয়
        $oursBefore = $this->netByProductIn($ours, $day);
        $theirsBefore = $this->netByProductIn($theirs, $day);

        $ourProduct = $this->returnIn($ours, 'RTN-OURS', 2, '200.0000', $day);
        $theirProduct = $this->returnIn($theirs, 'RTN-THEIRS', 9, '999.0000', $day);
        $this->assertNotSame($ourProduct, $theirProduct, 'প্রস্তুতিটাই ভুল — দুই কোম্পানির একই পণ্য।');

        $ourChange = $this->change($oursBefore, $this->netByProductIn($ours, $day));
        $this->assertSame([$ourProduct => ['qty' => '-2.0000', 'amount' => '-200.0000']], $ourChange,
            '⛔ আমাদের নিট বিক্রি শুধু নিজের ফেরতে বদলানোর কথা — অন্য কোম্পানির ফেরতও ঢুকেছে: '.json_encode($ourChange));

        // ⓘ উল্টো দিকেও — প্রস্তুতিটা সত্যি: ওদের ফেরত ওদের নিট বিক্রিতে আছে, আমাদেরটা নেই
        $theirChange = $this->change($theirsBefore, $this->netByProductIn($theirs, $day));
        $this->assertSame([$theirProduct => ['qty' => '-9.0000', 'amount' => '-999.0000']], $theirChange,
            '⛔ আমাদের ফেরত ওদের নিট বিক্রিতে: '.json_encode($theirChange));
    }

    /**
     * আগে-পরের পার্থক্য, শুধু যে পণ্য বদলেছে।
     *
     * @param  array<int, array{qty: string, amount: string}>  $before
     * @param  array<int, array{qty: string, amount: string}>  $after
     * @return array<int, array{qty: string, amount: string}>
     */
    private function change(array $before, array $after): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $product) {
            $qty = bcsub($after[$product]['qty'] ?? '0', $before[$product]['qty'] ?? '0', 4);
            $amount = bcsub($after[$product]['amount'] ?? '0', $before[$product]['amount'] ?? '0', 4);
            if (bccomp($qty, '0', 4) !== 0 || bccomp($amount, '0', 4) !== 0) {
                $out[$product] = ['qty' => $qty, 'amount' => $amount];
            }
        }
        ksort($out);

        return $out;
    }

    /** একটা পাকা ফেরত, এক সারি — কোম্পানির নিজের গ্রাহক, পণ্য আর গুদাম। @return int পণ্যের আইডি */
    private function returnIn(Company $company, string $no, int $qty, string $amount, string $day): int
    {
        $this->basicsIn($company);
        $customer = (int) Customer::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $product = (int) Product::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $warehouse = (int) Warehouse::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $this->assertGreaterThan(0, $customer * $product * $warehouse, 'প্রস্তুতিটাই ভুল — '.$company->code.'-এ গ্রাহক/পণ্য/গুদাম নেই।');

        $return = DB::table('sal_returns')->insertGetId([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'document_no' => $no,
            'customer_id' => $customer, 'warehouse_id' => $warehouse, 'trx_date' => $day, 'status' => 'confirmed',
            'subtotal' => $amount, 'tax' => 0, 'total' => $amount, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sal_return_lines')->insert([
            'company_id' => $company->id, 'sales_return_id' => $return, 'product_id' => $product,
            'qty' => $qty, 'rate' => bcdiv($amount, (string) $qty, 4), 'tax' => 0, 'amount' => $amount, 'line_no' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $product;
    }

    /**
     * ⓘ ডেমোর দ্বিতীয় কোম্পানি (FMART) খালি — গ্রাহক, পণ্য, গুদাম কিছুই নেই (bundle-3b-এর দৌড়ে প্রস্তুতির পাহারা ধরেছে,
     * ২২ অক্টোবর ২০২৬)। তাই যার নেই, তার জন্য সেই কোম্পানির নিজের প্রসঙ্গে একটা করে বানানো।
     */
    private function basicsIn(Company $company): void
    {
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $tag = strtoupper(substr($company->code, 0, 3));

        if (! Customer::query()->withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            Customer::query()->create(['code' => $tag.'-RTN-C', 'name_en' => $tag.' return shop', 'name_bn' => $tag.' ফেরতের দোকান',
                'credit_limit' => 0, 'credit_days' => 0, 'is_active' => true]);
        }
        if (! Product::query()->withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            Product::query()->create(['code' => $tag.'-RTN-P', 'name_en' => $tag.' return item', 'sale_price' => '100',
                'purchase_price' => '70', 'is_active' => true]);
        }
        if (! Warehouse::query()->withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            Warehouse::query()->create(['branch_id' => $company->defaultBranch()?->id, 'code' => $tag.'-RTN-W',
                'name_en' => $tag.' store', 'name_bn' => $tag.' গুদাম', 'is_active' => true]);
        }
    }

    /** @return array<int, array{qty: string, amount: string}> পণ্য → নিট পরিমাণ আর টাকা, সেই কোম্পানির চোখে */
    private function netByProductIn(Company $company, string $day): array
    {
        CompanyContext::set($company->id, null);

        $out = [];
        foreach (SalesMetrics::netByProduct($day, $day)->get() as $row) {
            $out[(int) $row->product_id] = ['qty' => (string) $row->qty, 'amount' => (string) $row->amount];
        }

        return $out;
    }
}
