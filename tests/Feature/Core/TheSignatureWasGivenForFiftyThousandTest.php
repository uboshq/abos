<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * সই দেওয়া হয়েছিল পঞ্চাশ হাজারে, পাশ হয়েছিল পাঁচ লাখ।
 *
 * ── ⛔ ফাঁকটা কোথায় ছিল ───────────────────────────────────────────────
 * [[ApprovalEngine::approve()]] কাগজটায় হাত দেয় না — কেবল অনুরোধটাকে
 * `approved` করে। ⓘ তাই মানুষটাকে ফিরে এসে আবার "নিশ্চিত" চাপতে হয়।
 *
 * ⚠️ **ঐ দুই চাপের মাঝখানে কাগজটা খসড়াই থাকে, আর সম্পাদনা করা যায়।**
 * ⛔ ২২ সেপ্টেম্বর ২০২৬ পর্যন্ত [[DocumentApproval::stopping()]] ঐ
 * ফাঁকটায় তাকাত না: পুরনো `approved` সারিটা পেলেই `null` ফেরাত।
 *
 * ⓘ ফলে এটা সম্ভব ছিল, আর কোথাও কোনো দাগ পড়ত না: ব্যবস্থাপক ৫০ হাজারের
 * অর্ডারে সই দিলেন, তারপর অর্ডারটা ৫ লাখ করে পাশ করিয়ে নেওয়া হলো।
 * ⚠️ খাতায় লেখা থাকত ৫০ হাজার — অর্থাৎ **প্রমাণটাও ভুল থাকত**।
 *
 * ── ⓘ এই ফাইলটা কী মাপে, আর কী মাপে না ───────────────────────────────
 * ⭐ মাপে: অঙ্ক বদলালে সই আর চলে না।
 * ⛔ মাপে না: অঙ্ক ছাড়া বাকি বদল (গ্রাহক পাল্টে দেওয়া) — ওটা এখনো
 * ঢাকা পড়ে না, আর কথাটা এখানে লেখা আছে বলেই কেউ উল্টোটা ধরে নেবে না।
 */
final class TheSignatureWasGivenForFiftyThousandTest extends TestCase
{
    use RefreshDatabase;

    private DocumentApproval $guard;

    private ApprovalEngine $engine;

    private User $salesman;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = app(DocumentApproval::class);
        $this->engine = app(ApprovalEngine::class);

        $company = Company::create(['code' => 'SIG', 'name_en' => 'Signature Co']);
        CompanyContext::set($company->id);

        $this->salesman = User::create(['name' => 'Salesman', 'email' => 's@sig.test', 'password' => 'x']);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'm@sig.test', 'password' => 'x']);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── আসল দাবিটা ────────────────────────────────────────────────────

    public function test_a_signature_for_fifty_thousand_does_not_pass_five_lakh(): void
    {
        $this->aFlowAbove('5000');
        $document = $this->document();

        $asked = $this->guard->stopping($document, 'sales', 'discount', '50000');

        $this->assertNotNull($asked, 'সীমার উপরে অঙ্ক, তবু সই চাওয়াই হয়নি।');

        $this->engine->approve($asked, $this->manager);

        /*
         * ⓘ প্রথমে প্রমাণ করা হয় সইটা **কাজ করে** — নাহলে নিচের
         * `assertNotNull`-টা একটা চিরকাল-আটকে-থাকা ব্যবস্থাতেও পাস করত,
         * আর সেটা সারানো নয়, ভাঙা।
         */
        $this->assertNull(
            $this->guard->stopping($document->fresh(), 'sales', 'discount', '50000'),
            'একই অঙ্কে সইটা আর চলছে না — অর্থাৎ অনুমোদন নেওয়ার পরেও কাজ এগোয় না।'
        );

        // ⛔ আর এখন কাগজটা ৫ লাখ।
        $again = $this->guard->stopping($document->fresh(), 'sales', 'discount', '500000');

        $this->assertNotNull($again,
            'পঞ্চাশ হাজারে দেওয়া সই দিয়ে পাঁচ লাখ পাশ হয়ে গেছে।');

        $this->assertSame(Approval::PENDING, $again->status);
        $this->assertSame('500000.0000', (string) $again->amount,
            'নতুন অনুরোধটা পুরনো অঙ্কেই বসেছে — তাহলে সইটা আবার ভুল কাগজের হত।');
        $this->assertNotSame($asked->id, $again->id, 'পুরনো অনুমোদনটাই ফেরত এসেছে।');
    }

    /** ⓘ অঙ্ক নামলেও সই বাতিল — কারণ "কত কম চলবে" প্রশ্নের সৎ উত্তর নেই। */
    public function test_a_smaller_amount_also_needs_its_own_signature(): void
    {
        $this->aFlowAbove('5000');
        $document = $this->document();

        $asked = $this->guard->stopping($document, 'sales', 'discount', '50000');
        $this->engine->approve($asked, $this->manager);

        $this->assertNotNull(
            $this->guard->stopping($document->fresh(), 'sales', 'discount', '40000')
        );
    }

    /**
     * ⭐ আর নিচে নামলে কারো পথ আটকায় না।
     *
     * ⓘ অঙ্কটা সীমার নিচে পড়লে [[ApprovalEngine::request()]] এমনিতেই
     * `null` ফেরায়, তাই "সব বদলেই সই" নিয়মটা বাস্তবে কড়া হয়ে ওঠে না।
     */
    public function test_falling_below_the_threshold_simply_goes_through(): void
    {
        $this->aFlowAbove('5000');
        $document = $this->document();

        $asked = $this->guard->stopping($document, 'sales', 'discount', '50000');
        $this->engine->approve($asked, $this->manager);

        $this->assertNull(
            $this->guard->stopping($document->fresh(), 'sales', 'discount', '900'),
            'সীমার নিচে নেমেও কাগজটা আটকে আছে।'
        );
    }

    /**
     * ⛔ অঙ্ক না জানা থাকলে যেন লুপে না পড়ে।
     *
     * ⚠️ কিছু কাগজ অঙ্ক ছাড়াই অনুমোদনে যায় (`amount: null`)। ⓘ তুলনাটা
     * কাঁচাভাবে লিখলে `null !== null` জাতীয় ভুলে সইটা **কোনোদিনই**
     * কাগজটা ঢাকত না, আর মানুষ অনন্তকাল সই চেয়ে যেত।
     */
    public function test_a_document_with_no_amount_stays_approved(): void
    {
        $this->aFlowAbove(null);
        $document = $this->document();

        $asked = $this->guard->stopping($document, 'sales', 'discount', null);

        $this->assertNotNull($asked);

        $this->engine->approve($asked, $this->manager);

        $this->assertNull(
            $this->guard->stopping($document->fresh(), 'sales', 'discount', null),
            'অঙ্কহীন কাগজে সই দেওয়ার পরেও আবার সই চাওয়া হচ্ছে — অসীম লুপ।'
        );
    }

    /**
     * ⛔ `(string) null` = `''`, আর PHP ৮-এ `bccomp('', …)` ছোঁড়ে।
     *
     * ⚠️ ডাকার জায়গাগুলো সত্যিই `(string) $voucher->amount` লেখে, তাই
     * এটা তাত্ত্বিক নয়। ⓘ না ধরলে পাহারাটা বসানোর ফল হত একটা **ভেঙে
     * পড়া পোস্টিং**, আটকানো নয় — অর্থাৎ সারানোর চেয়ে খারাপ।
     */
    public function test_an_empty_string_is_not_read_as_zero(): void
    {
        $this->aFlowAbove(null);
        $document = $this->document();

        $asked = $this->guard->stopping($document, 'sales', 'discount', null);
        $this->engine->approve($asked, $this->manager);

        $this->assertNull(
            $this->guard->stopping($document->fresh(), 'sales', 'discount', ''),
            '`(string) null` এসে সইটা বাতিল করে দিয়েছে অথবা ছুঁড়ে ফেলেছে।'
        );
    }

    // ── যমজটা যেন আলাদা না হয়ে যায় ───────────────────────────────────

    /**
     * ⛔ একই নিয়ম দুই জায়গায় — আর সেটাই আসল ঝুঁকি।
     *
     * ⓘ [[DocumentApproval]]-র নিজের মন্তব্যেই লেখা আছে কেন নিয়মটা
     * কোরে একবার থাকা উচিত: *"একটা কপি অন্যদের থেকে আলাদা হয়ে যেত, আর
     * সেই আলাদা হওয়াটা নীরব"*। ⚠️ [[VoucherApproval]] সেই যমজ, আর
     * ভাউচারই সবচেয়ে বেশি টাকার কাগজ।
     *
     * ⓘ তাই এখানে আচরণ নয়, **তারটা** মাপা হয়: যে যে জায়গায় `approved`
     * দেখে ছেড়ে দেওয়া হয়, সেখানে অঙ্কের প্রশ্নটাও থাকতে হবে।
     */
    public function test_no_copy_of_the_rule_forgets_to_ask_about_the_amount(): void
    {
        $blind = [];
        $swept = 0;

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            if (! str_contains($code, 'Approval::APPROVED')) {
                continue;
            }

            $swept++;

            /*
             * ⓘ কেবল সেই জায়গাগুলো যেখানে `approved` দেখে **ছেড়ে দেওয়া**
             * হয় (`return null`) — রিপোর্ট বা ব্যাজে `Approval::APPROVED`
             * থাকা স্বাভাবিক, ওখানে কিছু ছাড়া হয় না।
             */
            if (! preg_match('/=== Approval::APPROVED(.{0,300}?)return null;/s', $code, $m)) {
                continue;
            }

            if (! str_contains($m[1], '->covers(')) {
                $blind[] = $file->getRelativePathname();
            }
        }

        $this->assertGreaterThan(0, $swept,
            'একটা ফাইলেও `Approval::APPROVED` পাওয়া গেল না — সুইপটা কিছুই পড়ছে না।');

        $this->assertSame([], $blind,
            "এই জায়গাগুলো `approved` দেখেই ছেড়ে দেয়, অঙ্কটা মেলায় না:\n"
            .implode("\n", $blind));
    }

    /**
     * ⛔ উপরের সুইপটা সত্যিই তাকায় কি না।
     *
     * ⚠️ ঐ regex-টা এমনভাবে লেখা যে **কিছুই না মিললেও** সবুজ থাকত।
     * ⓘ তাই এখানে হাতে বানানো দুইটা নমুনা দেওয়া হয়: একটা অন্ধ, একটা নয়।
     */
    public function test_the_sweep_can_tell_a_blind_copy_from_a_careful_one(): void
    {
        $blind = <<<'PHP'
            if ($latest?->status === Approval::APPROVED) {
                return null;
            }
            PHP;

        $careful = <<<'PHP'
            if ($latest?->status === Approval::APPROVED
                && $latest->covers($amount)) {
                return null;
            }
            PHP;

        $this->assertTrue($this->looksBlind($blind), 'অন্ধ কপিটাই ধরা পড়ছে না।');
        $this->assertFalse($this->looksBlind($careful), 'ঠিক লেখা কপিটাকেও অন্ধ বলছে।');
    }

    private function looksBlind(string $code): bool
    {
        if (! preg_match('/=== Approval::APPROVED(.{0,300}?)return null;/s', $code, $m)) {
            return false;
        }

        return ! str_contains($m[1], '->covers(');
    }

    // ── হাতিয়ার ────────────────────────────────────────────────────────

    private function aFlowAbove(?string $threshold): ApprovalFlow
    {
        $flow = ApprovalFlow::create([
            'module' => 'sales',
            'action' => 'discount',
            'threshold_amount' => $threshold,
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->manager->id,
        ]);

        return $flow;
    }

    /** ⓘ ইঞ্জিন polymorphic — কোন কাগজ সেটা তার জানার কথা নয়। */
    private function document(): Branch
    {
        $this->actingAs($this->salesman);

        return Branch::create(['code' => 'S'.uniqid(), 'name_en' => 'Doc']);
    }
}
