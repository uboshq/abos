<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Models\SisterLink;
use App\Modules\Executive\Services\Board;
use App\Modules\Executive\Services\Figures;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ভাই-কোম্পানি বাদ — গ্রুপের মোট থেকে কেবল জোড়া দেওয়া পক্ষের বিক্রি, পাওনা আর দেনা (IFRS 10)।
 *
 * ⭐ জোড়া না থাকলে কিছুই বাদ নয়; জোড়া থাকলে ঠিক ঐ পক্ষের সংখ্যা, আর কেবল দুই কোম্পানিই পর্দায় থাকলে।
 */
final class ASaleToASisterIsNotTheGroupsSaleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $alpha;

    private Company $beta;

    private Customer $sister;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->beta = Company::query()->where('code', 'FMART')->firstOrFail();
        $this->actingAs($this->owner);
        CompanyContext::set((int) $this->alpha->id, (int) $this->owner->current_branch_id);

        // ⓘ আলম স্টোর ধরা যাক আসলে ফ্যামিলি মার্ট; রহিম ট্রেডার্স সত্যিকারের বাইরের ক্রেতা
        $this->sister = CompanyContext::forCompany((int) $this->alpha->id, fn () => Customer::acrossDealers()->where('name_en', 'Alam Store')->firstOrFail());
        $this->sell('Alam Store', '3', '1500');
        $this->sell('Rahim Traders', '2', '1200');
    }

    public function test_with_no_links_nothing_is_removed(): void
    {
        $board = app(Board::class)->build($this->owner, Figures::TODAY);

        $this->assertNull($board['eliminated'], '⛔ জোড়া ছাড়াই কিছু বাদ গেছে — নাম মিলিয়ে আন্দাজ?');
        $this->assertSame(0, bccomp($this->sumOfCompanies($board, Figures::SALES), $board['total'][Figures::SALES], 4));
    }

    public function test_only_the_linked_partys_sales_and_dues_come_off_the_group_total(): void
    {
        $this->link();
        $board = app(Board::class)->build($this->owner, Figures::TODAY);

        $today = now()->toDateString();
        $sisterSales = CompanyContext::forCompany((int) $this->alpha->id, fn () => SalesMetrics::invoiceTotal($today, $today, [(int) $this->sister->id]));
        $sisterDue = CompanyContext::forCompany((int) $this->alpha->id, fn () => (string) (Customer::query()->withOutstandingInView()->findOrFail($this->sister->id)->outstanding_in_view ?? '0'));

        $this->assertSame(1, bccomp($sisterSales, '0', 4), 'প্রস্তুতিটাই ভুল — ভাই-কোম্পানিকে বিক্রি শূন্য।');
        $this->assertNotNull($board['eliminated']);
        $this->assertSame(0, bccomp($sisterSales, $board['eliminated'][Figures::SALES], 4), '⛔ বাদের বিক্রি ঐ পক্ষের বিক্রি নয়।');
        $this->assertSame(0, bccomp($sisterDue, $board['eliminated'][Figures::RECEIVABLE], 4), '⛔ বাদের পাওনা ঐ পক্ষের পাওনা নয়।');
        $this->assertSame(0, bccomp('0', $board['eliminated'][Figures::PAYABLE], 4), 'কোনো সরবরাহকারী জোড়া নেই — দেনায় কিছু বাদ যাওয়ার কথা নয়।');

        // ⭐ গ্রুপের মোট = সারিগুলোর যোগ − বাদ; রহিমের বিক্রি গ্রুপের মোটে থেকে যায়
        $this->assertSame(0, bccomp(bcsub($this->sumOfCompanies($board, Figures::SALES), $sisterSales, 4), $board['total'][Figures::SALES], 4));
        $this->assertSame(0, bccomp('2400', $board['total'][Figures::SALES], 4), '⛔ গ্রুপের বিক্রিতে কেবল বাইরের ক্রেতা থাকার কথা।');

        // ⓘ কোম্পানির নিজের সারি বদলায় না — ট্রেড ডিপো সত্যিই বেচেছে
        $alpha = collect($board['companies'])->firstWhere('id', (int) $this->alpha->id);
        $this->assertSame(0, bccomp('6900', $alpha['values'][Figures::SALES], 4));

        $this->get(route('executive.today'))->assertOk()->assertSee(__('executive::today.eliminated'));
    }

    public function test_nothing_comes_off_when_the_sister_is_not_on_the_screen(): void
    {
        $this->link();

        // ⓘ হিসাবরক্ষক কেবল ট্রেড ডিপো দেখেন — তাঁর কাছে আলম স্টোরের বিক্রি সত্যিই বাইরের বিক্রি
        $accountant = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->giveKeys($accountant, ['executive.view', 'sales.invoice.view']);

        $this->actingAs($accountant->fresh());
        CompanyContext::set((int) $this->alpha->id, null);
        $board = app(Board::class)->build($accountant->fresh(), Figures::TODAY);

        $this->assertNull($board['eliminated'], '⛔ পর্দায় না থাকা ভাই-কোম্পানির জন্য বাদ গেছে।');
    }

    public function test_links_are_set_only_between_ones_own_companies_with_the_key(): void
    {
        $this->actingAs($this->owner)
            ->post(route('executive.links.store'), [
                'company_id' => $this->alpha->id, 'party' => 'customer:'.$this->sister->id, 'sister_company_id' => $this->beta->id,
            ])->assertRedirect();

        $this->assertSame(1, SisterLink::acrossAllCompanies()->count());

        // ⛔ একই পক্ষ দুইবার নয়
        $this->from(route('executive.links'))->post(route('executive.links.store'), [
            'company_id' => $this->alpha->id, 'party' => 'customer:'.$this->sister->id, 'sister_company_id' => $this->beta->id,
        ])->assertSessionHasErrors('party');

        // ⛔ অন্য কোম্পানির পক্ষ এই কোম্পানির নামে নয়
        $this->from(route('executive.links'))->post(route('executive.links.store'), [
            'company_id' => $this->beta->id, 'party' => 'customer:'.$this->sister->id, 'sister_company_id' => $this->alpha->id,
        ])->assertSessionHasErrors('party');

        // ⛔ চাবি ছাড়া নয়, আর যে কোম্পানি নিজের নয় সেখানে নয়
        $accountant = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->actingAs($accountant)->get(route('executive.links'))->assertForbidden();

        $this->giveKeys($accountant, ['executive.view', 'executive.links.manage']);
        $this->actingAs($accountant->fresh())->from(route('executive.links'))->post(route('executive.links.store'), [
            'company_id' => $this->alpha->id, 'party' => 'customer:'.Customer::acrossDealers()->where('name_en', 'Rahim Traders')->value('id'), 'sister_company_id' => $this->beta->id,
        ])->assertSessionHasErrors('company_id');

        $this->actingAs($this->owner->fresh())->get(route('executive.links'))->assertOk()->assertSee($this->beta->name());

        $link = SisterLink::acrossAllCompanies()->firstOrFail();
        $this->delete(route('executive.links.destroy', ['link' => $link->id]))->assertRedirect();
        $this->assertSame(0, SisterLink::acrossAllCompanies()->count());
    }

    private function link(): void
    {
        CompanyContext::forCompany((int) $this->alpha->id, fn () => SisterLink::query()->create([
            'company_id' => $this->alpha->id, 'party_type' => SisterLink::CUSTOMER,
            'party_id' => $this->sister->id, 'sister_company_id' => $this->beta->id,
        ]));
        app(Board::class)->refresh($this->owner);
    }

    private function sell(string $customer, string $qty, string $rate): void
    {
        CompanyContext::forCompany((int) $this->alpha->id, function () use ($customer, $qty, $rate) {
            $warehouse = Warehouse::query()->where('code', 'WH-MMS')->firstOrFail();
            CompanyContext::set((int) $this->alpha->id, (int) $warehouse->branch_id);
            app(DirectSaleService::class)->complete(
                ['customer_id' => Customer::acrossDealers()->where('name_en', $customer)->firstOrFail()->id,
                    'warehouse_id' => $warehouse->id, 'own_transport' => '1', DirectSaleService::REPEAT_FIELD => '1'],
                [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id,
                    'qty' => $qty, 'rate' => $rate, 'free_qty' => '0']],
            );
        });
    }

    /** @param list<string> $keys */
    private function giveKeys(User $user, array $keys): void
    {
        CompanyContext::forCompany((int) $this->alpha->id, function () use ($user, $keys) {
            foreach ($user->roles as $role) {
                Role::findById($role->id)->givePermissionTo($keys);
            }
        });
        $user->unsetRelation('roles')->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function sumOfCompanies(array $board, string $key): string
    {
        return array_reduce($board['companies'], fn (string $s, array $c) => bcadd($s, $c['values'][$key], 4), '0');
    }
}
