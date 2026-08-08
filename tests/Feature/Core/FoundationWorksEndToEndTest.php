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

        $this->assertSame('JRN-2026-2027-0001', $documentNo);

        // ৩. হিসাবে বসানো
        $posting = app(PostingEngine::class);
        $posting->post('journal_voucher', 1, '2026-08-04', [
            ['account_id' => 1101, 'debit' => 11500, 'party_type' => 'customer', 'party_id' => 7],
            ['account_id' => 4001, 'credit' => 10000],
            ['account_id' => 2201, 'credit' => 1500],
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
