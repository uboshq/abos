<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ডিপোর ছয়টা বিশেষ খতিয়ান — আসলে একটা ছাঁকনি।
 *
 * ── পরিকল্পনায় কী লেখা ছিল, ৩০ আগস্ট ২০২৬ ────────────────────────────
 * *"ডিপোর ছয়টা বিশেষ খতিয়ান — ভাড়া গাড়ি · ট্রান্সপোর্ট ভেন্ডর ·
 * শ্রমিক ঠিকাদার · দালাল · কোম্পানি দাবি · ক্ষতির দাবি"*
 *
 * ছয়টা আলাদা পর্দা বানানো যেত। কিন্তু প্রথম চারটা **সবাই পক্ষ** — এমন
 * মানুষ বা প্রতিষ্ঠান যাদের ডিপো টাকা দেয় — আর পক্ষের ধরন এই ব্যবস্থায়
 * আগে থেকেই একটা **খোলা তালিকা** (কোম্পানি সেটিংস থেকে সারি যোগ করে)।
 *
 * ছয়টা পর্দা বানালে সপ্তম ধরনটার দিন আবার কোড লিখতে হত — আর ডিপোতে
 * সপ্তম ধরন আসে, কারণ ব্যবসাটাই এমন। ছাঁকনি হলে কোম্পানি নিজে একটা
 * ধরন যোগ করলেই তার খতিয়ান পেয়ে যায়।
 *
 * (বাকি দুইটা আলাদা: **কোম্পানি দাবি** আগেই বসানো — কমিশনের দাবি,
 * খাত ১১৫০; **ক্ষতির দাবি** এখনো বাকি, কারণ ওটা পক্ষ নয়, একটা ঘটনা।)
 *
 * ── এই ফাইলটা যা পাহারা দেয় ─────────────────────────────────────────
 * ছাঁকনিটা **সত্যিই ছাঁকে**। একটা ছাঁকনি যা সব সারি ফেরত দেয় সেটাও
 * "কাজ করে" বলে মনে হয় — আর সেটাই সবচেয়ে সহজে অলক্ষ্যে থেকে যায়।
 */
class SixSpecialLedgersAreOneFilterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
    }

    /**
     * এই সরবরাহকারীকে কিছু টাকা পাওনা বানানো।
     *
     * ── কেন সিডারের ডেটার উপর দাঁড়ানো যায় না ─────────────────────────
     * প্রথমে ধরে নিয়েছিলাম সিডারে সরবরাহকারীর বকেয়া আছে। নেই — আর
     * তাতে পরীক্ষাটা "কোনো বকেয়াই নেই" বলে লাল হলো, অথচ কোডে কিছুই
     * ভুল ছিল না।
     *
     * নিজের ডেটা নিজে বসালে পরীক্ষাটা সিডার বদলালেও সত্যি থাকে, আর
     * কী মাপা হচ্ছে সেটাও পড়ে বোঝা যায়।
     */
    private function owe(Supplier $supplier, string $amount): void
    {
        $payable = Account::query()->where('code', StandardChart::PAYABLE)->firstOrFail();
        $expense = Account::query()->where('code', StandardChart::OPERATING_EXPENSES)->firstOrFail();

        app(PostingEngine::class)->post(
            sourceType: 'test.payable',
            sourceId: $supplier->id,
            trxDate: now()->toDateString(),
            lines: [
                ['account_id' => $expense->id, 'debit' => $amount, 'credit' => '0'],
                [
                    'account_id' => $payable->id,
                    'debit' => '0',
                    'credit' => $amount,
                    'party_type' => Supplier::drillSourceType(),
                    'party_id' => $supplier->id,
                ],
            ],
            documentNo: 'TEST-'.$supplier->id,
        );
    }

    private function partyType(string $code, string $name): PartyType
    {
        return PartyType::query()->create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name_en' => $name,
            'name_bn' => $name,
            'applies_to' => PartyType::SUPPLIER,
            'is_active' => true,
        ]);
    }

    /**
     * ছাঁকনিটা সত্যিই ছাঁকে — অন্য ধরনের সরবরাহকারী বাদ পড়ে।
     */
    public function test_the_filter_actually_filters(): void
    {
        $transport = $this->partyType('TRANS', 'Transport vendor');
        $labour = $this->partyType('LABOUR', 'Labour contractor');

        $suppliers = Supplier::query()->orderBy('id')->take(2)->get();

        if ($suppliers->count() < 2) {
            $this->markTestSkipped('সিডারে দুইটা সরবরাহকারী নেই।');
        }

        $suppliers[0]->forceFill(['party_type_id' => $transport->id])->save();
        $suppliers[1]->forceFill(['party_type_id' => $labour->id])->save();

        $this->owe($suppliers[0], '12000');
        $this->owe($suppliers[1], '8000');

        $everyone = $this->payableNames();
        $onlyTransport = $this->payableNames($transport->id);

        $this->assertNotEmpty($everyone, 'কোনো বকেয়াই নেই — পরীক্ষাটার মানে থাকে না।');

        /*
         * সবার তালিকা আর ছাঁকা তালিকা এক হলে ছাঁকনিটা কিছুই করেনি।
         *
         * এটাই আসল পরীক্ষা: একটা ছাঁকনি যা সব সারি ফেরত দেয় সেটাও
         * "কাজ করে" বলে মনে হয়।
         */
        $this->assertNotEquals($everyone, $onlyTransport,
            'ছাঁকনি দিয়েও একই তালিকা এসেছে — ছাঁকনিটা কিছুই ছাঁকছে না।');

        $this->assertNotContains($suppliers[1]->name_en, $onlyTransport,
            'শ্রমিক ঠিকাদার ট্রান্সপোর্টের তালিকায় আছে।');
    }

    /**
     * ছাঁকনি না দিলে কাউকে বাদ দেওয়া হয় না।
     *
     * উল্টো ভুলটাও সহজ: ঘরটা খালি থাকলে `null` নিয়ে কোয়েরি চালিয়ে
     * ফেললে কোনো সারিই আসত না, আর ডিফল্ট পর্দাটাই খালি দেখাত।
     */
    public function test_leaving_it_empty_hides_nobody(): void
    {
        $type = $this->partyType('TRANS2', 'Transport');

        $supplier = Supplier::query()->orderBy('id')->first();

        if ($supplier === null) {
            $this->markTestSkipped('সিডারে সরবরাহকারী নেই।');
        }

        $supplier->forceFill(['party_type_id' => $type->id])->save();
        $this->owe($supplier, '5000');

        $withNothing = $this->payableNames();
        $withNull = $this->payableNames(null);

        $this->assertSame($withNothing, $withNull,
            'ছাঁকনি খালি রাখলে তালিকা বদলে যাচ্ছে।');

        $this->assertNotEmpty($withNothing,
            'ছাঁকনি না দিয়েও তালিকা খালি — ডিফল্ট পর্দাই ফাঁকা দেখাবে।');
    }

    /**
     * পর্দায় ঘরটা আসে, আর কেবল যে রিপোর্ট চেয়েছে তার পর্দায়।
     *
     * সব রিপোর্টে বসালে মজুদের রিপোর্টেও "পক্ষের ধরন" ড্রপডাউন বসত,
     * যেখানে প্রশ্নটার কোনো মানে নেই।
     */
    public function test_the_dropdown_shows_only_where_it_means_something(): void
    {
        $this->partyType('TRANS3', 'Transport');

        $offered = $this->get(route('supplier.report.show', ['slug' => 'payable-list']))
            ->assertOk()->viewData('partyTypes');

        $this->assertNotEmpty($offered, 'সরবরাহকারীর বকেয়ায় ধরনের তালিকা আসেনি।');

        $notOffered = $this->get(route('inventory.report.show', ['slug' => 'stock-summary']))
            ->assertOk()->viewData('partyTypes');

        $this->assertEmpty($notOffered,
            'মজুদের রিপোর্টেও পক্ষের ধরনের ঘর বসেছে — ওখানে প্রশ্নটার মানে নেই।');
    }

    /**
     * বকেয়ার তালিকার নামগুলো, ছাঁকনিসহ বা ছাড়া।
     *
     * @return list<string>
     */
    private function payableNames(?int $partyTypeId = null): array
    {
        $filters = ['to' => now()->toDateString()];

        if ($partyTypeId !== null) {
            $filters['party_type_id'] = $partyTypeId;
        }

        $result = app(ReportEngine::class)->run('supplier.payable_list', $filters);

        return collect($result->rows)->pluck('supplier_name')->sort()->values()->all();
    }
}
