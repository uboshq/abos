<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সরবরাহকারীর "দেনা" আর বিল-না-আসা মাল একটাই সংখ্যা ছিল — পুরো ERP অডিট, ক্রয় ⚠️১২ (৬ অক্টোবর ২০২৬; মালিকের বাছাই,
 * ১০ অক্টোবর ২০২৬: "দুই ভাগে দেখাও")।
 *
 * ⛔ মাল-গ্রহণে GRNI-র সারিতেও সরবরাহকারীর নাম বসে, আর "দেনা" তাঁর নামের সব সারি যোগ করত — দেনা + এসে-যাওয়া-কিন্তু-বিল-
 * না-আসা মাল, আর উপখাতা দেনার খাত ২১১০-এর সাথে মিলত না। ⭐ এখন "দেনা" কেবল দেনার খাত-পরিবার; বিল-না-আসা মাল পাশে আলাদা
 * ঘরে; বাকির সীমার যাচাই দুটো মিলিয়ে ([[Supplier::payable()]], [[Supplier::goodsNotBilled()]])।
 */
final class TheSupplierOwedAndTheGoodsNotBilledWereOneNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_payable_and_goods_not_billed_are_two_numbers_and_the_limit_counts_both(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $supplier = Supplier::query()->onlySuppliers()->orderBy('id')->firstOrFail();
        $before = [$supplier->payable(), $supplier->goodsNotBilled()];
        $leaf = fn (string $code) => ($root = StandardChart::find($code))->is_group
            ? Account::query()->postable()->whereKey($root->selfAndDescendants()->pluck('id'))->orderBy('code')->firstOrFail()
            : $root;

        // ⓘ বিল হওয়া দেনা ৫০০ (২১১১) আর বিল-না-আসা মাল ৩০০ (২১৬০), দুটোই তাঁর নামে
        app(PostingEngine::class)->post(sourceType: 'test:owed', sourceId: 1, trxDate: now(), lines: [
            ['account_id' => StandardChart::find(StandardChart::HAMMALI)->id, 'debit' => '500'],
            ['account_id' => $leaf(StandardChart::PAYABLE)->id, 'credit' => '500', 'party_type' => Supplier::drillSourceType(), 'party_id' => $supplier->id],
        ]);
        app(PostingEngine::class)->post(sourceType: 'test:grni', sourceId: 1, trxDate: now(), lines: [
            ['account_id' => StandardChart::find(StandardChart::INVENTORY)->id, 'debit' => '300'],
            ['account_id' => $leaf(StandardChart::GOODS_RECEIVED_NOT_INVOICED)->id, 'credit' => '300', 'party_type' => Supplier::drillSourceType(), 'party_id' => $supplier->id],
        ]);

        $fresh = $supplier->fresh();
        $this->assertSame(0, bccomp(bcsub($fresh->payable(), $before[0], 4), '500', 4), '⛔ দেনায় বিল-না-আসা মালও ঢুকল।');
        $this->assertSame(0, bccomp(bcsub($fresh->goodsNotBilled(), $before[1], 4), '300', 4), '⛔ বিল-না-আসা মাল আলাদা গোনা হয় না।');

        // ⓘ তালিকার পথও — একই দুই অঙ্ক
        $row = $this->get(route('supplier.index'))->assertOk()->viewData('suppliers')->getCollection()->firstWhere('id', $supplier->id);
        $this->assertNotNull($row);
        $this->assertSame(0, bccomp((string) $row->payable_in_view, $fresh->payable(), 4), '⛔ তালিকার দেনায় বিল-না-আসা মাল।');
        $this->assertSame(0, bccomp((string) $row->grni_in_view, $fresh->goodsNotBilled(), 4), '⛔ তালিকায় বিল-না-আসা মালের ঘর ভুল।');

        // ⭐ বাকির সীমা দুটো মিলিয়ে — দেনা একা সীমার নিচে, দুটো মিলে ওপরে
        $owedOnly = $fresh->payable();
        $both = bcadd($owedOnly, $fresh->goodsNotBilled(), 4);
        $fresh->forceFill(['credit_limit' => bcadd($owedOnly, '100', 4)])->save();
        $this->assertTrue($fresh->fresh()->isOverTheirLimit(), '⛔ বাকির সীমায় বিল-না-আসা মাল গোনা হয় না।');
        $fresh->forceFill(['credit_limit' => bcadd($both, '1', 4)])->save();
        $this->assertFalse($fresh->fresh()->isOverTheirLimit());
    }
}
