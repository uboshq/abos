<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কে সেটিংটা বদলাল — প্রশ্নটার কোনো উত্তর ছিল না।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * [[Setting]] মডেলে [[IsAudited]] ছিল না, আর [[SettingsService]]ও নিজে
 * কিছু লিখত না। অর্থাৎ একটা সেটিং **কে বদলাল, কবে, আর কী থেকে কীসে** —
 * তিনটার একটারও উত্তর কোথাও ছিল না।
 *
 * ── কেন এটা তাত্ত্বিক নয় ─────────────────────────────────────────────
 * এই রিপোর নিজের খাতায় লেখা আছে (Findings, ৩০ আগস্ট ২০২৬):
 * *"এক ট্যাব সংরক্ষণ করায় ৩৪টা সেটিং নীরবে বন্ধ"*। কারণটা বের করতে
 * গোটা একটা সন্ধ্যা লেগেছিল — **জিজ্ঞেস করার মতো কোনো খাতা ছিল না**,
 * তাই কোডের ভেতর দিয়ে পিছিয়ে যেতে হয়েছিল।
 *
 * ওই ঘটনার পর সংরক্ষণের পথটা সারানো হয়েছে, কিন্তু **পরেরবার অন্য কিছু
 * ভাঙলে খোঁজার উপায়টা তখনো তৈরি হয়নি।** এই ফাইলটা সেটাই।
 *
 * ── কেন `reset()`-এর আলাদা পরীক্ষা আছে ───────────────────────────────
 * "ডিফল্টে ফেরা"ও পর্দার আচরণ ঠিক ততটাই বদলায় যতটা "বদলে দেওয়া"। কিন্তু
 * ওই পথটা কোয়েরি বিল্ডার দিয়ে মুছত, আর সেখানে মডেল-ঘটনা চলে না — তাই
 * ট্রেইট বসিয়েও অর্ধেকটা অন্ধ থাকত: বদল লেখা হত, ফেরানো হত না।
 */
class NobodyCouldSayWhoChangedTheSettingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);
    }

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    private function row(): Setting
    {
        return Setting::query()
            ->where('company_id', $this->company->id)
            ->where('key', 'accounts.backdate_days')
            ->firstOrFail();
    }

    /** কোম্পানি প্রথমবার নিজের মান বসালে সেটা খাতায় ওঠে। */
    public function test_setting_a_value_the_first_time_is_written_down(): void
    {
        $this->settings()->set('accounts.backdate_days', 30);

        $trail = AuditTrail::query()
            ->forRecord(Setting::class, $this->row()->id)
            ->firstOrFail();

        $this->assertSame(AuditTrail::CREATED, $trail->action);
        $this->assertSame($this->user->id, $trail->user_id);
        $this->assertSame($this->company->id, $trail->company_id);
    }

    /**
     * বদলালে পুরনো ও নতুন — দুইটাই।
     *
     * এটাই আসল দাবি। কেবল "কেউ কিছু বদলেছে" জানলে ৩০ আগস্টের রাতটা
     * এক মিনিটও ছোট হত না; **"৭ → ৩০" জানলে হত।**
     */
    public function test_changing_a_value_records_what_it_was_and_what_it_became(): void
    {
        $this->settings()->set('accounts.backdate_days', 7);
        $this->settings()->set('accounts.backdate_days', 30);

        $trail = AuditTrail::query()
            ->forRecord(Setting::class, $this->row()->id)
            ->where('action', AuditTrail::UPDATED)
            ->with('changes')
            ->firstOrFail();

        $change = $trail->changes->keyBy('field')['value'];

        $this->assertSame('7', $change->old_value);
        $this->assertSame('30', $change->new_value);
    }

    /** ডিফল্টে ফিরিয়ে দেওয়াও একটা ঘটনা, আর সেটাও লেখা হয়। */
    public function test_resetting_to_the_default_is_written_down_too(): void
    {
        $this->settings()->set('accounts.backdate_days', 30);
        $id = $this->row()->id;

        $this->settings()->reset('accounts.backdate_days');

        $this->assertDatabaseHas('audit_trails', [
            'auditable_type' => Setting::class,
            'auditable_id' => $id,
            'action' => AuditTrail::DELETED,
        ]);
    }

    /**
     * প্রোডাক্টের ডিফল্ট কোনো সারি নয়, তাই লেখার কিছু নেই।
     *
     * ── প্রথমে আমি উল্টোটা ধরে নিয়েছিলাম ────────────────────────────
     * এই পরীক্ষাটা প্রথমে লেখা হয়েছিল "`company_id` null সারিগুলো
     * অডিটে যায় না" প্রমাণ করতে, আর সেটা লাল হলো — **null-কোম্পানির
     * সারি একটাও নেই।** ডিফল্টগুলো ডাটাবেজে থাকেই না, সেগুলো প্রতিটা
     * `module.php`-তে ঘোষিত, আর `SettingsService` সারি না পেলে
     * ওখান থেকেই মান নেয়।
     *
     * তাই দাবিটা উল্টে লেখা হলো, কারণ এটাই সত্যি — আর এটা জানা
     * দরকার: **সেটিংসের প্রতিটা সারিই কোনো না কোনো কোম্পানির
     * সিদ্ধান্ত**, অর্থাৎ প্রতিটাই অডিটে যাওয়ার যোগ্য।
     */
    public function test_the_product_defaults_are_not_rows_at_all(): void
    {
        $this->assertSame(0, Setting::query()->whereNull('company_id')->count(),
            'কোম্পানিহীন সেটিংসের সারি পাওয়া গেল — তাহলে অডিটের নিয়মটা আবার ভাবতে হবে।');

        $this->settings()->set('accounts.backdate_days', 30);

        $this->assertNotNull($this->row()->company_id);
    }

    /**
     * কোম্পানির অডিট সারিতে কোনো শাখা বসে না।
     *
     * ── কেন এটা আলাদা করে পাহারা দেওয়া হয় ───────────────────────────
     * এই বাগটা **একা চালালে ধরা পড়ত না**। [[CompanyContext]] স্ট্যাটিক,
     * তাই আগের টেস্টের প্রসঙ্গ পরেরটায় রয়ে যায়। সিডার কোম্পানি তৈরি
     * করে **শাখার আগে**, আর তখন অডিট সারিতে পুরনো প্রসঙ্গের শাখাটা
     * বসত — যে শাখা তখনো তৈরিই হয়নি। বিদেশি চাবি সেটা মানেনি।
     *
     * ফল: তিনটা টেস্ট **কেবল পুরো সুইটে** লাল হত, একা চালালে সবুজ।
     * ওই অস্থিরতাটাই আসল বিপদ ছিল — কেউ ধরে নিত "ফ্লেকি টেস্ট"।
     *
     * এখানে প্রসঙ্গটা ইচ্ছে করে এমন একটা শাখায় বসানো হয় যার অস্তিত্ব
     * নেই, ঠিক যেভাবে ফাঁস হওয়া প্রসঙ্গ কাজ করত।
     */
    public function test_a_company_audit_row_carries_no_branch(): void
    {
        CompanyContext::set($this->company->id, 999_999);

        $other = Company::query()->where('id', '<>', $this->company->id)->firstOrFail();

        $other->update(['phone' => '01800000000']);

        $this->assertDatabaseHas('audit_trails', [
            'auditable_type' => Company::class,
            'auditable_id' => $other->id,
            'branch_id' => null,
        ]);
    }

    /**
     * কোম্পানির নিজের সম্পাদনা তার নিজের খাতাতেই বসে।
     *
     * [[Company]]-র নিজের `company_id` ঘর নেই, তাই [[AuditEngine]] আগে
     * চলতি প্রসঙ্গ ধরত। ফলে প্ল্যাটফর্ম-প্রশাসক এক কোম্পানিতে বসে
     * অন্যটার তথ্য বদলালে সারিটা **ভুল প্রতিষ্ঠানের খাতায়** বসত — আর
     * যার তথ্য বদলাল, তার পর্দায় কিছুই দেখা যেত না।
     */
    public function test_a_company_edit_lands_in_that_company_own_ledger(): void
    {
        $other = Company::query()->where('id', '<>', $this->company->id)->firstOrFail();

        $other->update(['phone' => '01700000000']);

        /*
         * `assertDatabaseHas`, Eloquent নয় — আর কারণটাই এখানকার বিষয়।
         *
         * [[AuditTrail]] নিজে টেন্যান্ট-ছাঁকা। তাই চলতি কোম্পানিতে বসে
         * `AuditTrail::query()` দিয়ে খুঁজলে **অন্য কোম্পানির সারিটা
         * কখনোই পাওয়া যেত না** — আর পরীক্ষাটা লাল হত ঠিক তখনই যখন
         * কোডটা ঠিক কাজ করছে।
         */
        $this->assertDatabaseHas('audit_trails', [
            'auditable_type' => Company::class,
            'auditable_id' => $other->id,
            'action' => AuditTrail::UPDATED,
            'company_id' => $other->id,
        ]);

        $this->assertDatabaseMissing('audit_trails', [
            'auditable_type' => Company::class,
            'auditable_id' => $other->id,
            'company_id' => $this->company->id,
        ]);
    }
}
