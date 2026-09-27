<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Services\PurchaseRequisitionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * চাহিদাপত্র ক্রয় ফেরতের নম্বর নিয়ে ছাপা হত — নিরীক্ষা ২৭.০৯.২৬ §২ (L1)।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `Purchase/module.php`-এর `doc_types`-এ `'PR'` চাবি **দুইবার** লেখা ছিল —
 * একবার চাহিদার, একবার ফেরতের। PHP-র অ্যারেতে পরেরটা জেতে, কোনো ভুল
 * বার্তা ছাড়া। তাই চাহিদা আর ফেরত একই সিরিজে নম্বর নিত: চাহিদা PRT-0001,
 * ফেরত PRT-0002, চাহিদা PRT-0003 — আর চাহিদাপত্রটা "ক্রয় ফেরত" নামে
 * ছাপা হত। অথচ চাবির ঠিক উপরের মন্তব্যটাই বলছিল চাহিদার নিজের সিরিজ।
 *
 * ⭐ সারাই: চাহিদার নিজের কোড `PRQ`। ফেরত `PR`-তেই থাকে (ছাপায় PRT),
 * কারণ লাইভে ফেরতের নম্বর ইতিমধ্যে ঐ সিরিজে ছাপা — ওটা বদলালে পুরনো
 * কাগজের নম্বর আর নতুন কাগজের নম্বর দুই সিরিজে ভাগ হত।
 */
final class TheRequisitionTookTheReturnsNumberTest extends TestCase
{
    use RefreshDatabase;

    /** ⭐ চাহিদা আর ফেরত দুইটা আলাদা ধরন — নামসহ। */
    public function test_the_requisition_and_the_return_are_two_document_types(): void
    {
        $types = app(ModuleRegistry::class)->get('purchase')->docTypes;

        $this->assertSame('purchase::doc.requisition', $types['PRQ'] ?? null,
            '⛔ চাহিদার নিজের ধরন নেই — চাহিদা অন্য কাগজের সিরিজে নম্বর নেবে।');
        $this->assertSame('purchase::doc.return', $types['PR'] ?? null,
            '⛔ ফেরতের ধরন বদলে গেছে — লাইভে ছাপা ফেরতের সিরিজ ভাঙবে।');
    }

    /**
     * ⭐ চাহিদা, ফেরত, চাহিদা — প্রতিটা নিজের সিরিজে গোনে, কেউ কারো নম্বর খায় না।
     *
     * ⓘ ফেরতের নম্বর ইঞ্জিন থেকে সরাসরি নেওয়া হয়: এখানে প্রশ্নটা সিরিজের,
     * ফেরতের পুরো পথ নয় (সেটা ThePurchaseCycleRanEndToEndTest-এ মাপা)।
     */
    public function test_each_counts_in_its_own_series(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $first = $this->requisition();
        $return = app(NumberSeriesEngine::class)->next('PR');
        $second = $this->requisition();

        $this->assertStringStartsWith('PRQ-', $first, "⛔ চাহিদা পেল {$first} — নিজের উপসর্গ নয়।");
        $this->assertStringStartsWith('PRT-', $return, "⛔ ফেরত পেল {$return} — ফেরতের পুরনো উপসর্গ নয়।");
        $this->assertSame(
            (int) substr($first, strrpos($first, '-') + 1) + 1,
            (int) substr($second, strrpos($second, '-') + 1),
            "⛔ দুই চাহিদার মাঝে ফাঁক ({$first} → {$second}) — মাঝের নম্বরটা অন্য কাগজ খেয়েছে।",
        );
    }

    /**
     * ⭐ লাইভের পুরনো চাহিদাপত্র: নম্বর বদলায় না, পাতা খোলে, আর নতুন নম্বর
     * কারো সাথে ধাক্কা খায় না (সমন্বয়কারী abos-69-এর শর্ত, ২৭ সেপ্টেম্বর)।
     *
     * ⓘ পুরনো চাহিদা ঠিক যেমন লাইভে বসে আছে তেমন বানানো হয় — ফেরতের সিরিজ
     * থেকে নেওয়া PRT নম্বরে। তারপর নতুন চাহিদা PRQ সিরিজে ১ থেকে শুরু করে।
     * ⚠️ PRT আর PRQ উপসর্গ আলাদা, তাই নম্বর মিলতে পারে না — তবু দাবিটা
     * ডাটাবেসের অদ্বিতীয় নিয়ম (`issued_numbers`) দিয়ে মাপা, অনুমানে নয়।
     */
    public function test_an_old_requisition_keeps_its_number_and_opens(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $old = app(PurchaseRequisitionService::class)->create(
            ['trx_date' => now()->toDateString(), 'purpose' => 'পুরনো'],
            [['product_id' => Product::query()->orderBy('id')->firstOrFail()->id, 'qty' => '1']],
        );
        $legacy = app(NumberSeriesEngine::class)->next('PR');
        $old->forceFill(['document_no' => $legacy])->save();

        $new = $this->requisition();

        $this->assertSame($legacy, $old->fresh()->document_no, '⛔ পুরনো চাহিদার নম্বর বদলে গেছে।');
        $this->assertStringStartsWith('PRT-', $legacy);
        $this->assertStringStartsWith('PRQ-', $new);

        $issued = DB::table('issued_numbers')->where('company_id', $company->id)
            ->whereIn('document_no', [$legacy, $new])->pluck('document_no')->all();
        $this->assertEqualsCanonicalizing([$legacy, $new], $issued,
            '⛔ দুইটা নম্বরই আলাদা করে ইস্যু-খাতায় থাকার কথা।');
        $this->assertSame(0, DB::table('issued_numbers')->where('company_id', $company->id)
            ->select('document_no')->groupBy('document_no')->havingRaw('count(*) > 1')->get()->count(),
            '⛔ একই নম্বর দুইবার ইস্যু হয়েছে।');

        $this->get(route('purchase.requisition.show', $old))
            ->assertOk()
            ->assertSee($legacy);
    }

    private function requisition(): string
    {
        return app(PurchaseRequisitionService::class)->create(
            ['trx_date' => now()->toDateString(), 'purpose' => 'নম্বর যাচাই'],
            [['product_id' => Product::query()->orderBy('id')->firstOrFail()->id, 'qty' => '1']],
        )->document_no;
    }
}
