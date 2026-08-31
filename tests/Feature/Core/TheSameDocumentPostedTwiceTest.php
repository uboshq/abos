<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Posting\PostingException;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * একই ডকুমেন্ট দুইবার খতিয়ানে বসতে পারে না — ডাটাবেজ ধরে রাখে।
 *
 * ── কেন [[PostingEngineTest]]-এর "দুইবার পোস্ট" পরীক্ষাটা যথেষ্ট ছিল না ──
 * ওটা দেখে [[PostingEngine::assertNotAlreadyPosted()]] কাজ করছে কি না —
 * অর্থাৎ **খতিয়ানে সারি থাকলে** দ্বিতীয়বার বসানো আটকায় কি না। কিন্তু
 * ওই চেকটা চলে **লেনদেনের বাইরে**, আর সেটাই ছিল আসল ফাঁক: দুইটা
 * রিকোয়েস্ট একসাথে এলে দুইজনেই "বসেনি" দেখে, কারণ কারও লেখা তখনো
 * commit হয়নি।
 *
 * তাই পরীক্ষাটা এমন হতে হবে যেখানে **চেকটা পাশ করে যায়, তবু বসানো
 * যায় না**। নিচের প্রথম পরীক্ষাটা ঠিক সেই অবস্থা বানায়: খতিয়ানের
 * সারিগুলো সরিয়ে দেওয়া হয়, প্রহরী-সারিটা রেখে। তখন ভদ্র চেকটা কিছুই
 * দেখে না — আর তবু কিছু বসে না।
 *
 * ── কেন এটা সবচেয়ে জরুরি পাহারা ─────────────────────────────────────
 * দুইবার বসা দুইটা সেটই ডেবিট=ক্রেডিট মেলা। রেওয়ামিল মেলে, বইয়ের
 * যাচাই সবুজ থাকে, কোনো পাহারা লাল হয় না — শুধু বিক্রি, খরচ আর বকেয়া
 * দ্বিগুণ দেখায়। এই টেস্টটা ছাড়া ভুলটা ধরার আর কোনো পথ নেই।
 */
class TheSameDocumentPostedTwiceTest extends TestCase
{
    use RefreshDatabase;

    private PostingEngine $engine;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(PostingEngine::class);

        $this->company = Company::create(['code' => 'TWICE', 'name_en' => 'Twice Ltd']);
        CompanyContext::set($this->company->id);

        FinancialYear::create([
            'name' => '2026-2027',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'is_current' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        return [
            ['account_id' => 10, 'debit' => 1200],
            ['account_id' => 20, 'credit' => 1200],
        ];
    }

    /**
     * আসল দৌড়টা — ভদ্র চেক অন্ধ, তবু কিছু বসে না।
     *
     * খতিয়ানের সারি সরিয়ে দিলে `assertNotAlreadyPosted()` কিছুই দেখে না,
     * ঠিক যেমন একটা সমান্তরাল রিকোয়েস্ট প্রথমজনের অ-commit করা লেখা
     * দেখতে পেত না। প্রহরী-সারিটাই একমাত্র জিনিস যা তখনো জানে।
     */
    public function test_a_second_post_is_refused_even_when_the_ledger_looks_empty(): void
    {
        $this->engine->post('sales_invoice', 1, '2026-08-04', $this->lines());

        $this->assertSame(2, LedgerEntry::query()->count());

        // দৌড়টা নকল করা: ভদ্র চেক আর কিছু দেখে না
        LedgerEntry::query()->delete();
        $this->assertSame(0, LedgerEntry::query()->count());

        try {
            $this->engine->post('sales_invoice', 1, '2026-08-04', $this->lines());
            $this->fail('প্রহরী-সারি থাকা সত্ত্বেও দ্বিতীয়বার বসে গেল।');
        } catch (PostingException $e) {
            $this->assertStringContainsString('already in the ledger', $e->getMessage());
        }

        // আর সবচেয়ে জরুরি: অর্ধেক বসা খতিয়ান বলে কিছু নেই
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    /** প্রথমবার বসলে প্রহরী-সারিটা সত্যিই লেখা হয়। */
    public function test_posting_leaves_exactly_one_claim(): void
    {
        $this->engine->post('sales_invoice', 2, '2026-08-04', $this->lines());

        $claims = DB::table('posted_documents')
            ->where('company_id', $this->company->id)
            ->where('source_type', 'sales_invoice')
            ->where('source_id', 2)
            ->count();

        $this->assertSame(1, $claims);
    }

    /**
     * উল্টো এন্ট্রিরও নিজের দাবি — দুইবার বাতিল করা যায় না।
     *
     * দুইবার বাতিল হলে হিসাব উল্টোদিকে দ্বিগুণ হত, আর সেটাও রেওয়ামিল
     * মেলা অবস্থাতেই — অর্থাৎ ঠিক একই শ্রেণির নীরব ভুল।
     */
    public function test_a_reversal_cannot_be_taken_twice(): void
    {
        $this->engine->post('sales_invoice', 3, '2026-08-04', $this->lines());
        $this->engine->reverse('sales_invoice', 3, '2026-08-05', 'ফেরত');

        $this->assertSame(4, LedgerEntry::query()->count());

        try {
            $this->engine->reverse('sales_invoice', 3, '2026-08-05', 'আবার ফেরত');
            $this->fail('একই ডকুমেন্ট দুইবার বাতিল হয়ে গেল।');
        } catch (PostingException $e) {
            $this->assertStringContainsString('already in the ledger', $e->getMessage());
        }

        $this->assertSame(4, LedgerEntry::query()->count());
    }

    /**
     * দুই কোম্পানির একই আইডির ডকুমেন্ট আলাদা — ওরা আলাদা বই।
     *
     * চাবিতে company_id না থাকলে দ্বিতীয় কোম্পানির প্রথম বিলটাই
     * "আগে বসানো হয়েছে" বলে ফিরে যেত, আর নতুন কোম্পানি কোনোদিন
     * শুরুই করতে পারত না।
     */
    public function test_two_companies_may_post_the_same_document_id(): void
    {
        $this->engine->post('sales_invoice', 7, '2026-08-04', $this->lines());

        $other = Company::create(['code' => 'OTHER', 'name_en' => 'Other Ltd']);
        CompanyContext::set($other->id);

        // অর্থবছরও কোম্পানির নিজের — প্রথমটার বছর দ্বিতীয়জন দেখতে পায় না,
        // আর সেটাই ঠিক
        FinancialYear::create([
            'name' => '2026-2027',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'is_current' => true,
        ]);

        $entries = $this->engine->post('sales_invoice', 7, '2026-08-04', $this->lines());

        $this->assertCount(2, $entries);
    }
}
