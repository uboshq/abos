<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\NumberSeriesProvisioner;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ভিত্তিটা সত্যিই দাঁড়িয়েছে কি না — Phase 1-এর Exit Criteria।
 *
 * প্ল্যান সেকশন ৬ বলে Phase 1 তখনই শেষ যখন "একটা ডামি মডেলে posting +
 * approval + audit + drill-down + দুই ভাষা — পাঁচটাই কাজ করছে"। এই টেস্টটা
 * ঠিক সেটাই একসাথে করে দেখায়, আসল ডেমো ডাটার উপর।
 */
class FoundationWorksEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_whole_chain_holds_together(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        // ১. কোম্পানি প্রসঙ্গ
        $owner->switchCompany($alpha->id);
        CompanyContext::set($owner->fresh()->current_company_id, $owner->fresh()->current_branch_id);

        $this->assertSame($alpha->id, CompanyContext::id());
        $this->assertSame(4, Branch::query()->count(), 'Trade Depot has a head office and three upazila branches.');

        // ২. নম্বর ইস্যু
        //
        // JV ব্যবহার করা হচ্ছে, SI নয়: নম্বর সিরিজ এখন মডিউলের ঘোষণা
        // থেকে তৈরি হয়, আর সেলস মডিউল এখনো লেখা হয়নি — তাই SI বলে
        // কিছু নেই। আগে সিডারে হাতে লেখা তালিকায় ছিল, আর সেটাই
        // অস্তিত্বহীন একটা ডকুমেন্টের সিরিজ বানিয়ে রাখত।
        $numbers = app(NumberSeriesEngine::class);
        $documentNo = $numbers->next('JV', sourceType: 'journal_voucher', sourceId: 1);

        /*
         * ⚠️ `JRN-0001`, আগে `JRN-2026-2027-0001` ছিল।
         *
         * ⛔ ছকটা বদলেছে, ৫ সেপ্টেম্বর ২০২৬ — মালিকের সিদ্ধান্ত:
         * *"Document number series {PREFIX}-{SEQ} বাই ডিফল্ট বসাও"*।
         *
         * ⓘ অর্থবছরটা নম্বর থেকে উঠে গেছে, আর তার সাথে **অটো-রিসেটও বন্ধ**
         * ([[NumberSeriesProvisioner::resetsWith]]) — নাহলে ২০২৬-এর
         * `JRN-0001` আর ২০২৭-এর `JRN-0001` **দুইটা আলাদা কাগজে এক নম্বর**
         * হত।
         *
         * ⚠️ দাবিটা তবু হুবহু সংখ্যাসহ রাখা হলো, `assertStringStartsWith`
         * নয়: এই পরীক্ষাটার কাজই হলো **প্রথম নম্বরটা ঠিক কী** তা লিখে
         * রাখা — আলগা দাবি দিলে `JRN-0007`-ও পাস করত।
         */
        $this->assertSame('JRN-0001', $documentNo);

        // ৩. হিসাবে বসানো
        $posting = app(PostingEngine::class);
        /*
         * ⛔ খাতগুলো **কোড ধরে**, আইডি হার্ডকোড করে নয় — ৬ সেপ্টেম্বর ২০২৬।
         *
         * ── কী ভাঙা ছিল ─────────────────────────────────────────────
         * এখানে লেখা ছিল `1101` · `4001` · `2201` — যেন ওগুলো আইডি। ⚠️
         * মেপে দেখা গেল **তিনটাই ভুল**:
         *
         *     1101  →  আসল আইডি ৩১২৭, আর সেটা একটা **গ্রুপ** (হাতে নগদ)
         *     4001  →  এই চার্টে **নেই** (বিক্রয় ৪১০০)
         *     2201  →  এই চার্টে **নেই** (ভ্যাট প্রদেয় ২১২০)
         *
         * ⓘ CLAUDE.md-তে ফাঁদটা নাম ধরে লেখা: *"`CASH_IN_HAND`='1101' একটা
         * **গ্রুপ** — টাকা বসে till-এর সন্তানে, `CashTillService::
         * ensurePrimaryTill()` দিয়ে।"*
         *
         * ⭐ তাই নগদটা till-এর খাত থেকে, আর বাকি দুইটা [[StandardChart]]-এর
         * ধ্রুবক থেকে। ⚠️ চার্ট বদলালে এই পরীক্ষাটা **নিজে থেকেই** নতুন
         * কোডে চলবে — আগের মতো নীরবে ভুল আইডিতে নয়।
         */
        $till = app(CashTillService::class)->ensurePrimaryTill();
        $sales = Account::query()->where('code', StandardChart::SALES)->firstOrFail();
        $vat = Account::query()->where('code', StandardChart::VAT_PAYABLE)->firstOrFail();

        $posting->post('journal_voucher', 1, '2026-08-04', [
            ['account_id' => $till->account->id, 'debit' => 11500, 'party_type' => 'customer', 'party_id' => 7],
            ['account_id' => $sales->id, 'credit' => 10000],
            ['account_id' => $vat->id, 'credit' => 1500],
        ], documentNo: $documentNo);

        // নিজের কাগজের সারি গোনা হয়, সবার নয় — সিডারে খোলা মজুদের
        // দাখিলাও এখন খতিয়ানে বসে, আর এই ধাপের প্রশ্ন তাদের নিয়ে নয়।
        $voucherEntries = LedgerEntry::query()->where('source_type', 'journal_voucher');

        $this->assertSame(3, (clone $voucherEntries)->count());
        $this->assertEquals(
            LedgerEntry::query()->sum('debit'),
            LedgerEntry::query()->sum('credit'),
            'The books must balance.',
        );

        // ৪. প্রতিটা লাইন তার উৎসে ফিরতে পারে — নিয়ম ১
        $entry = (clone $voucherEntries)->first();
        $this->assertSame('journal_voucher', $entry->source_type);
        $this->assertSame(1, (int) $entry->source_id);
        $this->assertSame($documentNo, $entry->document_no);

        // ৫. অনুমোদন — ছাড় ১,০০০-এর উপরে হলে মালিকের সম্মতি লাগে
        $approvals = app(ApprovalEngine::class);
        $document = Branch::query()->first();

        $small = $approvals->request($document, 'sales', 'discount', '500', userId: $salesman->id);
        $this->assertNull($small, 'A small discount should not need anybody.');

        $large = $approvals->request($document, 'sales', 'discount', '2500', userId: $salesman->id);
        $this->assertNotNull($large);
        $this->assertSame(Approval::PENDING, $large->status);

        $decided = $approvals->approve($large, $owner, 'ঠিক আছে');
        $this->assertSame(Approval::APPROVED, $decided->status);

        // ৬. সেটিংস — মডিউল থেকে আসা ডিফল্ট
        $settings = app(SettingsService::class);
        $this->assertTrue($settings->enabled('customer.credit_limit_enabled'));
        $this->assertSame(7, $settings->get('accounts.backdate_days'));

        // ৭. দুই ভাষা
        app()->setLocale('bn');
        $this->assertSame('খসড়া', __('core.status.draft'));
        $this->assertSame('ট্রেড ডিপো', $alpha->name());

        app()->setLocale('en');
        $this->assertSame('Draft', __('core.status.draft'));
        $this->assertSame('Trade Depot', $alpha->name());

        // ৮. অন্য কোম্পানিতে গেলে কিছুই দেখা যায় না
        $owner->switchCompany($beta->id);
        CompanyContext::set($beta->id);

        $this->assertSame(0, LedgerEntry::query()->count(), "Beta must not see Alpha's ledger.");
        $this->assertSame(1, Branch::query()->count(), 'Beta has one branch.');
        $this->assertSame(0, Approval::query()->count());

        // ৯. ফিরে গেলে সব আগের মতোই
        $owner->switchCompany($alpha->id);
        CompanyContext::set($alpha->id);

        $this->assertSame(3, (clone $voucherEntries)->count());
    }

    public function test_the_demo_data_is_usable_as_it_stands(): void
    {
        $this->assertSame(2, Company::query()->count());
        $this->assertSame(3, User::query()->count());

        $alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        CompanyContext::set($alpha->id);

        // সংখ্যা গোনা হয় না, ঘোষণার সাথে মেলানো হয়: মডিউল যা ঘোষণা
        // করেছে তার প্রতিটার সিরিজ আছে কি না। একটা স্থির সংখ্যা লিখলে
        // নতুন ডকুমেন্ট টাইপ যোগ হলেই টেস্টটা ভাঙত — নিয়ম ভাঙায় নয়।
        $this->assertSame(
            [],
            app(NumberSeriesProvisioner::class)->missing(),
            'Every declared document type needs a series.',
        );
        $this->assertNotNull($alpha->currentFinancialYear());
        $this->assertNotNull($alpha->defaultBranch());
        $this->assertSame('MMS', $alpha->defaultBranch()->code);
    }
}
