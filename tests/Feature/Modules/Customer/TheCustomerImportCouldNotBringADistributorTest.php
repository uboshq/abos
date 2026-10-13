<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Imports\CustomerImporter;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\PartyType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ গ্রাহক আমদানির তিনটা ফাঁক (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[CustomerImporter::check()]])।
 *
 * ⚠️ (ক) ধরন খালি হলে ডিফল্ট "পরিবেশক", আর পরিবেশকের পয়েন্ট লাগে — অথচ ফাইলে পয়েন্টের ঘরই ছিল না, তাই ধরন খালি বা DISTRIB
 * লেখা কোনো সারিই বসত না। (খ) ঋণাত্মক সীমা পার হয়ে বসত — ফর্ম আর সীমার ফাইল দুটোই ফেরায়; পরে প্রতিটা সম্পাদনা আটকাত।
 * (গ) বাকির দিন যাচাই হত না — "৩০" চুপচাপ ০ (সব বিল সেদিনই মেয়াদোত্তীর্ণ), "-5" বসানোর সময় SQL-এ ভাঙত, "400" বসে পরে ফর্ম আটকাত।
 */
final class TheCustomerImportCouldNotBringADistributorTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $row = ['code' => '', 'name_en' => 'Import Point Shop', 'name_bn' => '', 'phone' => '', 'email' => '', 'address' => '',
        'party_type' => '', 'point' => '', 'payment_term' => '', 'credit_limit' => '', 'credit_days' => '', 'opening_balance' => '', 'opening_date' => ''];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_a_distributor_comes_in_with_its_point(): void
    {
        $default = PartyType::query()->for(PartyType::CUSTOMER)->where('is_default', true)->firstOrFail();
        $this->assertTrue($default->isDistributor(), 'ডিফল্ট ধরন পরিবেশক — এই দাবির ভিত্তি');

        // ⓘ চলতি শাখার একটা নতুন পয়েন্ট, যেখানে কোনো পরিবেশক নেই — ডেমোর প্রতিটা পয়েন্টে আগেই পরিবেশক বসা থাকতে পারে
        $point = Location::query()->create([
            'company_id' => CompanyContext::id(), 'branch_id' => CompanyContext::branchId(), 'code' => 'IMP-PT-1',
            'name_en' => 'Import Point', 'name_bn' => 'আমদানির পয়েন্ট', 'level' => Location::POINT, 'is_active' => true,
        ]);

        $row = ['point' => (string) $point->code] + $this->row;
        $importer = app(CustomerImporter::class);

        $this->assertSame([], $importer->check($row), '⛔ পয়েন্টসহ পরিবেশকের সারিও বসল না');
        $importer->import($row);

        $customer = Customer::acrossDealers()->where('name_en', 'Import Point Shop')->firstOrFail();
        $this->assertSame($point->id, (int) $customer->location_id);
        $this->assertSame($default->id, (int) $customer->party_type_id);

        $this->assertNotSame([], $importer->check(['point' => 'NO-SUCH-POINT', 'name_en' => 'Other Shop'] + $this->row), 'অচেনা পয়েন্ট কারণসহ থামে');
    }

    public function test_a_negative_limit_and_a_bad_credit_days_are_refused_with_a_reason(): void
    {
        $importer = app(CustomerImporter::class);
        $base = ['party_type' => 'RETAIL'] + $this->row;

        $this->assertContains(__('customer::import.limit_not_negative'), $importer->check(['credit_limit' => '-100'] + $base),
            '⛔ ঋণাত্মক সীমা আমদানিতে পার হল');

        foreach (['৩০', 'abc', '-5', '400', '30.5', '1e2'] as $days) {
            $this->assertContains(__('core.import.not_a_number', ['column' => 'credit_days']), $importer->check(['credit_days' => $days] + $base),
                '⛔ বাকির দিন "'.$days.'" পার হল');
        }

        $this->assertSame([], $importer->check(['credit_days' => '30', 'credit_limit' => '0'] + $base), 'সাধারণ সারি আগের মতোই চলে');
    }
}
