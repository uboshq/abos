<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * কোথায় সই বসানো যায়, আর কোথায় বসানো নেই — পর্দা দুইটাই বলে।
 *
 * ── ⭐ কীভাবে কাজটা এল, ২২ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * মালিককে জিজ্ঞেস করতে গিয়েছিলাম *"কোথায় কোথায় সই চান"*, আর দেখা গেল
 * **তিনি জানতেনই না কোথায় কোথায় বসানো যায়** — তালিকাটা সোর্স কোডে
 * (`module.php`-র `approvals`), আর পর্দায় দেখা যেত কেবল একটা নতুন
 * নিয়ম বানানোর ড্রপডাউনের ভিতরে।
 *
 * ── ⛔ আর অনুপস্থিতিটাই ছিল আসল বিপদ ────────────────────────────────
 * `assertClear()` কোনো ছক না পেলে **চুপচাপ ছেড়ে দেয়**। ⚠️ অর্থাৎ
 * একটাও নিয়ম না থাকলেও প্রতিটা পর্দা স্বাভাবিক চলে। ⓘ ২২ সেপ্টেম্বর
 * সকাল পর্যন্ত লাইভে ছক ছিল তিনটা, আর মালিক যে কোম্পানিতে বসেন
 * সেখানে **একটাও নয়** — মাসের পর মাস, আর কোথাও কিছু বলেনি।
 *
 * ⭐ তাই এই পর্দার সবচেয়ে দামি সারিগুলো হলো **যেগুলো নেই**, আর নিচের
 * দ্বিতীয় দাবিটাই সেটা পাহারা দেয়।
 */
final class TheScreenSaysWhereApprovalIsMissingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_a_place_with_a_rule_is_shown_as_required(): void
    {
        $this->flowFor('accounts', 'transfer');

        $this->assertStringContainsString(
            __('approval::message.coverage_on'),
            $this->page(),
            '⛔ যেখানে সই বসানো আছে, পর্দা সেটা বলছে না।',
        );
    }

    /**
     * ⭐ এই ফাইলের সবচেয়ে দামি দাবি — যা **নেই** তা দেখানো।
     *
     * ⛔ কেবল বসানো নিয়মগুলো দেখালে পর্দাটা মালিকের প্রশ্নের উত্তরই
     * দিত না: *"কোথায় নেই"* জানতে হলে দুইটা তালিকা হাতে মিলাতে হত —
     * কোডের তালিকা আর ছকের তালিকা।
     *
     * ⚠️ আর ঠিক ঐ না-জানাটাই মাসখানেক লাইভে একটাও অনুমোদন ছাড়া
     * চলতে দিয়েছে।
     */
    public function test_a_place_with_no_rule_is_shown_as_missing(): void
    {
        ApprovalFlow::query()->delete();

        $html = $this->page();

        $this->assertStringContainsString(__('approval::message.coverage_none'), $html, implode("\n", [
            '⛔ যেখানে কোনো নিয়ম নেই, পর্দা সেটা বলছে না।',
            '',
            '⚠️ নিয়ম না থাকলে কেউ কিছু আটকায় না, আর কোনো পর্দা সেটা বলে',
            'না — তাই "কোথায় নেই" প্রশ্নটার উত্তর কেবল এখানেই আছে।',
        ]));
    }

    /**
     * ⓘ "বসানো আছে কিন্তু বন্ধ" তৃতীয় একটা অবস্থা, আর সেটা আলাদা কথা।
     *
     * ⛔ "নেই"-এর সাথে এক রঙে দেখালে ইচ্ছে করে থামানো একটা নিয়ম আর
     * কোনোদিন সিদ্ধান্তই না নেওয়া একটা জায়গা এক দেখাত।
     */
    public function test_a_switched_off_rule_is_not_the_same_as_none(): void
    {
        $this->flowFor('accounts', 'transfer')->forceFill(['is_active' => false])->save();

        $this->assertStringContainsString(__('approval::message.coverage_off'), $this->page(),
            '⛔ বন্ধ করে রাখা নিয়মটা "বসানো নেই" বলে দেখাচ্ছে।');
    }

    /**
     * ⭐ গোনাটা সত্যিই গোনে — আর এটাই পাহারার পাহারা।
     *
     * ⓘ উপরের দাবিগুলো সবুজ থাকত যদি পর্দাটা **একটা সারিও** না আঁকত:
     * তখন কোনো লেখাই থাকত না, আর `assertStringContainsString` লাল হত —
     * কিন্তু উল্টোটা নয়। ⚠️ তাই মাপা হয় জায়গার সংখ্যা সত্যিই আছে,
     * আর একটা ছক বসালে গোনাটা **বাড়ে**।
     */
    public function test_the_count_really_counts(): void
    {
        ApprovalFlow::query()->delete();

        $before = $this->covered();

        $this->flowFor('accounts', 'transfer');

        $this->assertSame($before + 1, $this->covered(), implode("\n", [
            '⛔ একটা ছক বসানোর পরেও গোনাটা বাড়েনি।',
            '',
            '⚠️ তাহলে সংখ্যাটা কোডের তালিকা আর ছকের তালিকা মেলাচ্ছে না,',
            'আর গোটা পর্দাটাই তখন একটা সাজানো ছবি।',
        ]));
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ সব দাবি সবুজ থাকত যদি কোডে একটাও অনুমোদনের জায়গা ঘোষিত না
     * থাকত — তখন পর্দাটা খালি, আর খালি পর্দায় কোনো ভুলও নেই।
     */
    public function test_there_really_are_places_to_cover(): void
    {
        $this->assertGreaterThan(10, $this->total(),
            'কোডে দশটার কম অনুমোদনের জায়গা পাওয়া গেল — তালিকাটা পড়া হচ্ছে না।');
    }

    private function flowFor(string $module, string $action): ApprovalFlow
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(),
            'module' => $module,
            'action' => $action,
            'document_type' => '',
            'threshold_amount' => '5000',
            'is_active' => true,
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => (int) User::query()->value('id'),
            'requires_all' => false,
        ]);

        return $flow;
    }

    private function covered(): int
    {
        return (int) $this->response()->viewData('covered');
    }

    private function total(): int
    {
        return (int) $this->response()->viewData('total');
    }

    private function page(): string
    {
        return (string) $this->response()->getContent();
    }

    private function response(): TestResponse
    {
        return $this->get(route('approval.flow.coverage'))->assertOk();
    }
}
