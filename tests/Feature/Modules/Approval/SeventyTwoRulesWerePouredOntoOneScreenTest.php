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
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * বাহাত্তরটা নিয়ম এক পর্দায় ঢেলে দেওয়া হচ্ছিল।
 *
 * ── ⓘ কেন, ২২ সেপ্টেম্বর ২০২৬ ───────────────────────────────────────
 * ঐদিন সকালে লাইভে **৭২টা ছক** বসেছে (তিন কোম্পানিতে ২৪টা করে)। ⛔ আর
 * তালিকার পর্দাটা গোটাটা `get()` করে এক পাতায় ঢালত — পাতা ভাগ নেই,
 * খোঁজা নেই।
 *
 * ── ⚠️ কেন সেটা কেবল অগোছালো নয় ────────────────────────────────────
 * মানুষ স্ক্রল করে খোঁজেন, আর **খুঁজে না পেয়ে ধরে নেন নিয়মটা নেই** —
 * তারপর আরেকটা বানাতে যান। ⓘ তখন একই কাজে দুইটা ছক বসানোর চেষ্টা
 * হয়, আর সেটা আটকায় ভ্যালিডেশন — কিন্তু ততক্ষণে সময়টা গেছে।
 *
 * ── ⓘ পুরনো কারণটা কেন আর খাটে না ───────────────────────────────────
 * কন্ট্রোলারে লেখা ছিল *"পাতা ভাগ নেই, ইচ্ছাকৃত — সারির সংখ্যা কোডে
 * বাঁধা"*, আর যুক্তিটা ঠিকই ছিল: একটা সারি মানে এক মডিউলের এক কাজ।
 * ⚠️ কিন্তু **কোম্পানির সংখ্যা দিয়ে গুণ হয়** — আর সেটা কোডে বাঁধা নয়।
 */
final class SeventyTwoRulesWerePouredOntoOneScreenTest extends TestCase
{
    use RefreshDatabase;

    /** ⓘ কন্ট্রোলারে পাতাপ্রতি ২০ — তার চেয়ে বেশি বসালে তবেই দ্বিতীয় পাতা */
    private const MORE_THAN_ONE_PAGE = 24;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⭐ তালিকাটা পাতায় ভাগ হয় — সব এক পর্দায় ঢালে না।
     */
    public function test_the_list_is_broken_into_pages(): void
    {
        $this->manyFlows();

        $html = (string) $this->get(route('approval.flow.index'))->assertOk()->getContent();

        /*
         * ⚠️ দাবিটা "পাতার লিংক আছে" নয় — "দ্বিতীয় পাতায় যাওয়ার পথ আছে"।
         * ⓘ কেবল `links()` ছাপা হচ্ছে কি না দেখলে এক পাতার তালিকাতেও
         * সবুজ থাকত, কারণ তখনো ট্যাগটা ছাপা হয়, কেবল খালি।
         */
        $this->assertStringContainsString('page=2', $html, 'দ্বিতীয় পাতায় যাওয়ার কোনো পথ নেই।');
    }

    /**
     * ⛔ আর প্রথম পাতায় সবকিছু থাকে না — এটাই ভাগ হওয়ার আসল মানে।
     */
    public function test_the_first_page_does_not_hold_everything(): void
    {
        $codes = $this->manyFlows()->pluck('code');

        $html = (string) $this->get(route('approval.flow.index'))->assertOk()->getContent();

        $shown = $codes->filter(fn (string $code) => str_contains($html, $code))->count();

        $this->assertLessThan(
            $codes->count(),
            $shown,
            'প্রথম পাতাতেই সব ছক দেখা যাচ্ছে — পাতা ভাগ আসলে হচ্ছে না।',
        );
    }

    /**
     * ⭐ খোঁজা মডিউলের নাম ধরে কাজ করে, আর অন্যগুলো ছেঁকে ফেলে।
     *
     * ⚠️ দুইটা দিকই দরকার। ⓘ কেবল "যেটা খুঁজেছি সেটা আছে" দেখলে ছাঁকনি
     * পুরোপুরি অকেজো হলেও সবুজ থাকত — সব সারি দেখালে খোঁজারটাও থাকে।
     */
    public function test_searching_by_module_keeps_only_that_module(): void
    {
        $made = $this->manyFlows();

        $html = (string) $this->get(route('approval.flow.index', ['q' => 'purchase']))
            ->assertOk()->getContent();

        /*
         * ⚠️ দাবিটা সংকেত ধরে, মডিউলের নাম ধরে নয়।
         *
         * ⛔ প্রথমে `assertStringNotContainsString('inventory', …)` লেখা
         * ছিল, আর সেটা লাল হয়েছে — কারণ শব্দটা **পাশের মেনুতেও** আছে।
         * ⓘ অর্থাৎ দাবিটা তালিকা নয়, গোটা পাতা মাপছিল, আর ঐভাবে কখনো
         * সবুজ হত না, ছাঁকনি যতই ঠিক থাকুক।
         */
        $wanted = $made->where('module', 'purchase')->pluck('code');
        $unwanted = $made->where('module', 'inventory')->pluck('code');

        $this->assertTrue(
            $wanted->contains(fn (string $code) => str_contains($html, $code)),
            'যে মডিউলটা খোঁজা হলো তার একটা ছকও পর্দায় নেই।',
        );

        $this->assertFalse(
            $unwanted->contains(fn (string $code) => str_contains($html, $code)),
            'অন্য মডিউলের ছকও রয়ে গেছে।',
        );
    }

    /**
     * ⭐ আর সইকারীর নাম ধরেও — *"রফিক কোথায় কোথায় সই করেন"*।
     *
     * ⓘ এটাই প্রশ্নটার সবচেয়ে স্বাভাবিক রূপ, আর নামটা ছকের নিজের কোনো
     * ঘরে নেই — সে বসে ধাপের সারিতে, রোল বা ব্যবহারকারীর আইডি হয়ে।
     */
    public function test_searching_by_the_signer_name_finds_the_rule(): void
    {
        $role = Role::query()->firstOrFail();
        $made = $this->manyFlows();
        $flow = $made->first();

        ApprovalFlowStep::query()->where('approval_flow_id', $flow->id)->delete();
        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_ROLE,
            'approver_id' => $role->id,
        ]);

        $html = (string) $this->get(route('approval.flow.index', ['q' => $role->name]))
            ->assertOk()->getContent();

        $this->assertStringContainsString(
            (string) $flow->code,
            $html,
            'সইকারীর নাম ধরে খুঁজে ছকটা পাওয়া যাচ্ছে না।',
        );

        /*
         * ⚠️ দ্বিতীয় দিকটা ছাড়া দাবিটা **কখনো লাল হত না**।
         *
         * ⓘ মেপে দেখা: ছাঁকনিটা পুরোপুরি বন্ধ করে দিলেও এই দাবি সবুজ
         * থাকত, কারণ তখন **সব** ছক দেখা যায় — খোঁজারটাও তার মধ্যে।
         * ⛔ অর্থাৎ দাবিটা "খুঁজে পাওয়া গেল" মাপত, "ছেঁকে আনা হলো" নয়।
         */
        $others = $made->reject(fn (ApprovalFlow $f) => $f->id === $flow->id)->pluck('code');

        $this->assertFalse(
            $others->contains(fn (string $code) => str_contains($html, $code)),
            'যাঁর নাম খোঁজা হয়নি, তাঁর ছকও পর্দায় রয়ে গেছে।',
        );
    }

    /**
     * ⛔ কিছু না মিললে পর্দা **"কিছু পাওয়া যায়নি"** বলে।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * খালি ফল আর সত্যিই কোনো ছক না থাকা — দুইটা আলাদা কথা। ⓘ পুরনো
     * বার্তাটা ("এখনো কোনো ছক বসানো হয়নি") পড়ে মানুষ নতুন একটা বানাতে
     * যেতেন, অথচ নিয়মটা আছে — কেবল তাঁর লেখা শব্দের সাথে মেলেনি।
     */
    public function test_a_search_with_no_match_says_so(): void
    {
        $this->manyFlows();

        $html = (string) $this->get(route('approval.flow.index', ['q' => 'zzzznotathing']))
            ->assertOk()
            ->assertDontSee(__('approval::message.no_flows'))
            ->getContent();

        /*
         * ⛔ প্রথমে দাবিটা `core.search.nothing_found` দেখত, আর সেটা
         * **কখনো লাল হতে পারত না**: ঐ লেখাটা কমান্ড-প্যালেট প্রতিটা
         * পাতায় ছাপে ([[command-center.blade.php]])।
         *
         * ⚠️ প্রোব বসিয়ে ধরা পড়েছে — ছাঁকনি কাজ করার পরেও পাতায়
         * ওটা ছিল, অর্থাৎ দাবিটা তালিকা নয়, খোলসটা মাপছিল। ⭐ তাই
         * খালি ফলের নিজের একটা বার্তা, আর দাবিটা সেটার উপর।
         */
        $this->assertStringContainsString(
            __('approval::message.no_match'),
            $html,
            'কিছু না মিললেও পর্দা সেটা বলছে না।',
        );

        preg_match_all('/AN\d{3,}/', $html, $found);

        $this->assertSame([], array_unique($found[0]), 'কিছু মেলেনি, তবু ছক দেখানো হচ্ছে।');
    }

    /**
     * ⭐ তালিকাটা ফাইল হয়েও নামে — CSV।
     *
     * ── ⓘ কেন কার্ডের তালিকা থেকেও এটা করা গেল ──────────────────────
     * রপ্তানির ফাইল সাধারণত `x-ui.table` ধরিয়ে দেয়, আর এই পর্দায় কোনো
     * ছক নেই। ⚠️ কার্ডগুলোকে ছকে বদলানো যেত না — একটা ছকের নিচে তার
     * ধাপগুলো বসে, আর সেটা এক সারিতে ধরে না। ⭐ তাই কন্ট্রোলার নিজেই
     * টেবিলটা বানায়, আর ধাপগুলো এক ঘরে জোড়া লেগে যায়।
     */
    public function test_the_list_comes_down_as_a_file(): void
    {
        $this->manyFlows();

        $response = $this->get(route('approval.flow.index', ['export' => 'csv']))->assertOk();

        $this->assertStringContainsString(
            'text/csv',
            (string) $response->headers->get('content-type'),
            'CSV চাওয়ার পরেও ফাইল নয়, পাতাই ফিরেছে।',
        );

        // ⓘ উত্তরটা স্ট্রিম নয়, সাধারণ Response — মিডলওয়্যার পুরো দেহটা একবারে বসায়
        $csv = (string) $response->getContent();

        $this->assertStringContainsString(__('approval::field.code'), $csv, 'ফাইলে কলামের শিরোনামই নেই।');
        $this->assertStringContainsString('AN', $csv, 'ফাইলে একটাও সংকেত নেই।');
    }

    /**
     * ⛔ আর খোঁজা দিয়ে ছেঁকে নিলে **ছাঁকা তালিকাটাই** নামে।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * উপরেরটা কেবল বলে "ফাইল আসে"। ⓘ ছাঁকনিটা রপ্তানির পথে না পৌঁছালে
     * ফাইলে **গোটা তালিকা** নামত, আর মানুষ সেটা খুলে ভাবতেন খোঁজাটা
     * কাজ করেনি — বা আরো খারাপ, ভুল তালিকা নিয়ে কাজ করতেন।
     */
    public function test_the_file_carries_only_what_the_search_kept(): void
    {
        $made = $this->manyFlows();

        $response = $this->get(route('approval.flow.index', ['export' => 'csv', 'q' => 'purchase']))->assertOk();

        /*
         * ⚠️ আগে এই দাবিটা সরাসরি দেহটা পড়ত, আর সেটা **কখনো লাল হত না**।
         *
         * ⓘ মেপে দেখা: capture-টা সরিয়ে দিলে উত্তরটা আর CSV থাকে না,
         * ফিরে আসে সাধারণ HTML পাতা — আর ঐ পাতাতেও ছাঁকা তালিকাই থাকে।
         * ⛔ অর্থাৎ দাবিটা ফাইল নয়, পর্দা মাপত, আর রপ্তানি পুরোপুরি ভাঙা
         * থাকলেও সবুজ দেখাত।
         */
        $this->assertStringContainsString(
            'text/csv',
            (string) $response->headers->get('content-type'),
            'ছাঁকা তালিকা চাওয়ার পর ফাইল নয়, পাতাই ফিরেছে।',
        );

        $csv = (string) $response->getContent();

        $wanted = $made->where('module', 'purchase')->pluck('code');
        $unwanted = $made->where('module', 'inventory')->pluck('code');

        $this->assertTrue(
            $wanted->contains(fn (string $code) => str_contains($csv, $code)),
            'ছাঁকা তালিকার একটা ছকও ফাইলে নেই।',
        );

        $this->assertFalse(
            $unwanted->contains(fn (string $code) => str_contains($csv, $code)),
            'ছাঁকনির বাইরের ছকও ফাইলে নেমেছে।',
        );
    }

    /**
     * ⭐ বন্ধ করে রাখা নিয়ম তালিকাতেই চেনা যায়।
     *
     * ── ⛔ কী ভাঙা ছিল, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────────
     * বন্ধ নিয়মটা দেখতে চালুটার মতোই লাগত। ⚠️ কথাটা লেখা থাকত সীমার
     * পাশে, ছোট ধূসর অক্ষরে — আর শব্দটা ছিল **"প্রত্যাহৃত"**, যেটা
     * একটা *অনুরোধ* প্রত্যাহারের শব্দ, *নিয়ম* বন্ধ করার নয়।
     *
     * ⛔ অর্থাৎ পর্দাটা কেবল চুপ ছিল না, **ভুল কথা বলত** — আর সেটা পড়ে
     * মালিক ভাবতেন নিয়মটা কেউ প্রত্যাহার করেছে, অথচ ওটা তিনি নিজেই
     * বন্ধ করে রেখেছেন।
     *
     * ── ⚠️ কেন দাবিটা গুনতির পার্থক্য ধরে, উপস্থিতি ধরে নয় ──────────
     * চিহ্নের শব্দটা এক অক্ষরের কাছাকাছি ছোট — *"বন্ধ"* — আর সেটা
     * পাতার অন্য জায়গাতেও বসে (মেনু, বোতাম)। ⛔ তাই
     * `assertDontSee('বন্ধ')` **কখনো সবুজ হত না**, চিহ্নটা যতই ঠিক
     * থাকুক; আর `assertSee` **সবসময়** সবুজ থাকত, চিহ্নটা না বসলেও।
     *
     * ⓘ একই পর্দা দুইবার এনে **পার্থক্য** মাপলে ঐ পটভূমিটা দুই দিকেই
     * সমান, আর তখন যা বাড়ে সেটা কেবল চিহ্নটাই।
     */
    public function test_a_switched_off_rule_is_marked_and_a_live_one_is_not(): void
    {
        $flow = $this->manyFlows()->first();
        $mark = __('approval::message.coverage_off');
        $url = route('approval.flow.index', ['q' => $flow->code]);

        $live = substr_count((string) $this->get($url)->assertOk()->getContent(), $mark);

        $flow->update(['is_active' => false]);

        $off = substr_count((string) $this->get($url)->assertOk()->getContent(), $mark);

        $this->assertGreaterThan(
            $live,
            $off,
            'নিয়মটা বন্ধ করার পরেও পর্দায় কিছুই বদলায়নি — চালুটার মতোই দেখাচ্ছে।',
        );
    }

    /**
     * ⛔ আর পুরনো ভুল শব্দটা আর কোথাও নেই।
     *
     * ⚠️ দাবিটা আলাদা, কারণ উপরেরটা কেবল নতুন চিহ্নটা মাপে। ⓘ দুইটা
     * একসাথে বসে থাকলেও ওটা সবুজ থাকত, আর তখন পর্দায় দুইটা
     * পরস্পরবিরোধী কথা পাশাপাশি লেখা থাকত।
     */
    public function test_the_wrong_word_is_gone(): void
    {
        $flow = $this->manyFlows()->first();
        $flow->update(['is_active' => false]);

        $this->get(route('approval.flow.index', ['q' => $flow->code]))
            ->assertOk()
            ->assertDontSee(__('approval::status.cancelled'));
    }

    /**
     * এক পাতার চেয়ে বেশি ছক — ঘোষিত কাজের তালিকা থেকে নেওয়া।
     *
     * ⚠️ সেবা স্তর দিয়ে নয়, সরাসরি — সেবা স্তর ঘোষিত জোড়া ছাড়া কিছু
     * বসাতে দেয় না, আর ঘোষিত জোড়া ২৪টার কম হতে পারে। ⓘ এখানে দরকার
     * **সংখ্যা**, আর সংখ্যাটাই পাতা ভাগের বিষয়।
     *
     * @return Collection<int, ApprovalFlow>
     */
    private function manyFlows(): Collection
    {
        $made = collect();

        for ($i = 1; $i <= self::MORE_THAN_ONE_PAGE; $i++) {
            $made->push(ApprovalFlow::create([
                'module' => $i % 2 === 0 ? 'purchase' : 'inventory',
                'action' => 'made_up_'.$i,
                'document_type' => '',
                'is_active' => true,
            ]));
        }

        return $made;
    }
}
