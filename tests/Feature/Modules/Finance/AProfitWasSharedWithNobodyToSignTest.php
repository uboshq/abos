<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\Finance\Services\ProfitDistribution;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * লাভ ভাগ হত কারও সই ছাড়াই — চেকলিস্ট (অডিট ২৭ সেপ্টেম্বর) ঘর ৪, ১ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * [[ProfitDistribution::declare()]] ভাউচার বানিয়ে সাথে সাথে খাতায় বসাত — মুনাফা ভাগ, টাকা "প্রদেয়"
 * হয়ে দেনা, অথচ কেউ সই দেয়নি। মালিকের স্থায়ী নিয়ম: *যেকোনো টাকা, যেকোনো অঙ্ক — সই লাগে*
 * (স্বয়ংক্রিয় অনুমোদন বাতিল, ২৭ সেপ্টেম্বর ২০২৬)। আর মুনাফা ঘোষণা সই-যোগ্য কাজের তালিকাতেই ছিল না।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * ছক থাকলে ঘোষণা খসড়া থাকে; শেষ সই পড়লে নিজে থেকে খাতায় বসে ([[PostTheProfitOnTheLastSignature]]),
 * তালা দিয়ে সীমা আবার দেখে। অপেক্ষার ঘোষণাগুলোও সীমা থেকে কাটা যায় — দুইটা খসড়া মিলে আয়ের বেশি
 * নয়। ছক না থাকলে (সুইচ বন্ধ) আগের মতো সাথে সাথে।
 */
final class AProfitWasSharedWithNobodyToSignTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $signer;

    /** খাতার আসল সঞ্চিত মুনাফা — ডেমোরটা সহ; প্রতিটা ঘোষণা এর ৬০% ([[TwoAtOnceBrokeAFinanceCeilingTest]]-এর একই কারণ) */
    private string $available = '0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();

        $this->contribute('SIGN-A', 'Partner A', '600000');
        $this->contribute('SIGN-B', 'Partner B', '400000');
        $this->earn('100000');

        $this->available = StandardChart::find(StandardChart::RETAINED_EARNINGS)?->balanceOn() ?? '0';
    }

    /** ⭐ সুইচ বন্ধ — ছক নেই, তাই আগের মতো সাথে সাথে খাতায়। */
    public function test_with_no_signature_flow_the_declaration_posts_at_once(): void
    {
        $this->declare($this->share());

        $this->assertSame(0, ProfitShare::query()->where('status', ProfitShare::DRAFT)->count(), '⛔ ছক ছাড়াই ঘোষণা আটকে আছে।');
        $this->assertRetained($this->left(), 'ছক না থাকলে ঘোষণা সাথে সাথে খাতায় বসার কথা।');
    }

    /** ⛔ সুইচ চালু — খাতা নড়ে না; শেষ সই পড়লে নিজে থেকে বসে। */
    public function test_a_signed_flow_holds_the_declaration_until_the_last_signature(): void
    {
        $this->aProfitFlow();

        $this->declare($this->share());

        $this->assertSame(0, ProfitShare::query()->where('status', ProfitShare::POSTED)->count(), '⛔ সই ছাড়াই লাভ ভাগ হয়ে গেল।');
        $this->assertRetained($this->available, '⛔ সই-এর আগেই সঞ্চিত মুনাফা কমেছে।');

        app(ApprovalEngine::class)->approve($this->pending(), $this->signer);

        $this->assertSame(0, ProfitShare::query()->where('status', ProfitShare::DRAFT)->count(), '⛔ শেষ সই পড়ল, অথচ ঘোষণা খসড়াই রইল।');
        $this->assertRetained($this->left(), '⛔ শেষ সই-এর পরে ঘোষণা খাতায় বসেনি।');
    }

    /** ⛔ অপেক্ষার ঘোষণাও সীমা থেকে কাটা — দুইটা খসড়া মিলে আয়ের বেশি নয়। */
    public function test_a_declaration_still_waiting_is_counted_against_the_profit(): void
    {
        $this->aProfitFlow();

        $this->declare($this->share());

        $refused = false;

        try {
            $this->declare($this->share());
        } catch (ValidationException $e) {
            $refused = array_key_exists('profit', $e->errors());
        }

        $this->assertTrue($refused, '⛔ ৬০,০০০ অপেক্ষায় থাকতেই আরেকটা ৬০,০০০ ঘোষণা হলো — আয় ১,০০,০০০।');
    }

    /** ⭐ "না" বলা ঘোষণা বাতিল — আর ছেড়ে দেওয়া অঙ্কটা আবার ঘোষণা করা যায়। */
    public function test_a_rejected_declaration_is_cancelled_and_frees_the_profit(): void
    {
        $this->aProfitFlow();

        $this->declare($this->share());
        app(ApprovalEngine::class)->reject($this->pending(), $this->signer, 'এখন নয়');

        $this->assertSame(0, ProfitShare::query()->where('status', ProfitShare::DRAFT)->count(), '⛔ প্রত্যাখ্যাত ঘোষণা এখনো অপেক্ষায়।');
        $this->assertGreaterThan(0, ProfitShare::query()->where('status', ProfitShare::CANCELLED)->count(), '⛔ প্রত্যাখ্যাত ঘোষণা বাতিল হয়নি।');
        $this->assertRetained($this->available, '⛔ প্রত্যাখ্যাত ঘোষণা খাতায় বসেছে।');

        $this->declare($this->share());
        $this->assertSame(1, Approval::query()->where('status', Approval::PENDING)->count(), 'ছেড়ে দেওয়া অঙ্কটা আবার ঘোষণা করা যায়নি।');
    }

    /**
     * ⛔ সই-এর আগে সঞ্চিত মুনাফা কমে গেল — শেষ সই-এ খাতায় বসে না, ঘোষণা বাতিল।
     *
     * ⓘ সই মানুষের সিদ্ধান্ত, শ্রোতার বাধায় সেটা ফেরে না ([[ApprovalDecided]]); তাই সইকারীর কাছে
     * ভুল হয়ে ফেরে না — ঘোষণাটা বাতিল হয়, আর খাতা নড়ে না।
     */
    public function test_the_last_signature_rechecks_the_profit_under_the_lock(): void
    {
        $this->aProfitFlow();

        $this->declare($this->share());
        $this->earn('-'.bcdiv($this->available, '2', 2));
        $after = StandardChart::find(StandardChart::RETAINED_EARNINGS)?->balanceOn() ?? '0';

        app(ApprovalEngine::class)->approve($this->pending(), $this->signer);

        $this->assertSame(0, ProfitShare::query()->where('status', ProfitShare::POSTED)->count(), '⛔ সঞ্চিত মুনাফা অর্ধেক হলো, অথচ পুরনো অঙ্কের ঘোষণা সই পেয়ে খাতায় বসল।');
        $this->assertSame(0, ProfitShare::query()->where('status', ProfitShare::DRAFT)->count(), '⛔ খাটে না এমন ঘোষণা অপেক্ষায় ঝুলে রইল — সীমা চিরকাল আটকে থাকত।');
        $this->assertRetained($after, '⛔ সীমার বাইরের ঘোষণা খাতায় বসেছে।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function share(): string
    {
        return bcdiv(bcmul($this->available, '60', 4), '100', 2);
    }

    private function left(): string
    {
        return bcsub($this->available, $this->share(), 4);
    }

    private function declare(string $profit): void
    {
        app(ProfitDistribution::class)->declare(['profit' => $profit, 'trx_date' => now()->toDateString()]);
    }

    /** `finance.profit` — হিসাবরক্ষক সই দেন; মালিক বানান। */
    private function aProfitFlow(): void
    {
        $flow = ApprovalFlow::query()->create(['module' => 'finance', 'action' => 'profit', 'is_active' => true]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->signer->id,
        ]);

        // ⓘ ইঞ্জিন scoped, ছক জমিয়ে রাখে ([[TheRejectedRequestStillLetTheMoneyOutTest]]-এর একই কারণে)
        $this->app->forgetInstance(ApprovalEngine::class);
    }

    private function pending(): Approval
    {
        return Approval::query()->where('status', Approval::PENDING)->where('action', 'profit')->latest('id')->firstOrFail();
    }

    private function assertRetained(string $want, string $why): void
    {
        $have = StandardChart::find(StandardChart::RETAINED_EARNINGS)?->balanceOn() ?? '0';

        $this->assertSame(0, bccomp($have, $want, 4), "{$why} সঞ্চিত মুনাফা {$have}, হওয়ার কথা {$want}।");
    }

    /** মূলধনদাতা — [[TheProfitDeclaredWasMoreThanWasEverEarnedTest]]-এর একই উপায়। */
    private function contribute(string $code, string $name, string $amount): void
    {
        $person = Person::query()->create(['code' => $code, 'name_en' => $name, 'name_bn' => $name, 'is_active' => true]);

        CapitalEntry::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'document_no' => 'CAP-'.$code,
            'person_id' => $person->id,
            'contributor_type' => CapitalEntry::CONTRIBUTION,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'in_kind' => CapitalEntry::CASH,
            'trx_date' => now()->subMonth()->toDateString(),
            'amount' => $amount,
            'status' => CapitalEntry::POSTED,
        ]);
    }

    /** সঞ্চিত মুনাফায় ঢোকা (ধনাত্মক) বা বেরোনো (ঋণাত্মক) — পোস্টিং ইঞ্জিন দিয়েই। */
    private function earn(string $amount): void
    {
        $cash = Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
        $retained = StandardChart::find(StandardChart::RETAINED_EARNINGS);
        $in = bccomp($amount, '0', 4) > 0;
        $abs = ltrim($amount, '-');

        app(PostingEngine::class)->post(
            sourceType: 'test:earned',
            sourceId: random_int(1, 999999),
            trxDate: now()->subDays(2)->toDateString(),
            lines: $in
                ? [['account_id' => $cash->id, 'debit' => $abs], ['account_id' => $retained->id, 'credit' => $abs]]
                : [['account_id' => $retained->id, 'debit' => $abs], ['account_id' => $cash->id, 'credit' => $abs]],
        );
    }
}
