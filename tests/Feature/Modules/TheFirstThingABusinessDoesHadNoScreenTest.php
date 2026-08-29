<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CapitalEntry;
use App\Modules\Accounts\Services\CapitalService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * মূলধন ও বিনিয়োগ — ব্যবসার প্রথম কাজ, যার কোনো পর্দা ছিল না।
 *
 * ── কোথা থেকে এল ─────────────────────────────────────────────────────
 * ২৯ আগস্ট ২০২৬-এ মালিক ব্যবসার পথটা ক্রমে বললেন: **প্রথমে মূলধন, তারপর
 * বিনিয়োগ**, তারপর গুদাম, সরবরাহকারী, ক্রয়, মজুদ, গ্রাহক, বিক্রয়,
 * ডেলিভারি, হিসাব-নিকাশ। এগারোটা ধাপের দশটা ABOS-এ ছিল; প্রথমটা ছিল না।
 *
 * খাতও ছিল, ভাউচারও ছিল — কেবল পর্দা ছিল না। ফলে ব্যবসার সবচেয়ে প্রথম
 * কাজটা হত একটা হাতে লেখা জাবেদা, বিবরণে "ওপেনিং" লিখে, আর কে কত
 * দিয়েছেন তা কোথাও থাকত না।
 */
class TheFirstThingABusinessDoesHadNoScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->user);
    }

    /**
     * টাকা যেখানে সত্যিই বসে।
     *
     * ── কেন `1101` নয় ───────────────────────────────────────────────
     * `1101` "হাতে নগদ" একটা **মাথা**; ওর নিচে `1101-CASH` আসল খাত।
     * প্রথম লেখায় মাথাটাই দেওয়া হয়েছিল, আর সার্ভিস ঠিকই আটকে দিল —
     * "এটা একটা মাথা, খাত নয়"। পাহারাটা কাজ করেছে; পরীক্ষাটাই ভুল
     * ছিল।
     */
    private function cashAccount(): Account
    {
        return Account::query()
            ->postable()
            ->where('name_en', 'like', '%Cash%')
            ->orderBy('code')
            ->firstOrFail();
    }

    private function record(string $who, string $amount, string $kind = CapitalEntry::CONTRIBUTION): CapitalEntry
    {
        return app(CapitalService::class)->record([
            'contributor_name' => $who,
            'contributor_type' => CapitalEntry::OWNER,
            'entry_type' => $kind,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
        ]);
    }

    private function balanceOf(string $code): string
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        return (string) (DB::table('ledger_entries')
            ->where('company_id', CompanyContext::id())
            ->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')
            ->value('net') ?: '0');
    }

    /**
     * লেখা মানে খাতায় বসা নয়।
     *
     * "মালিক পাঁচ লাখ দেবেন" কথাটা যেদিন হয়, টাকাটা আসে অন্যদিন। এখনই
     * খাতায় বসালে ব্যবসার নগদ পাঁচ লাখ বেশি দেখাত, আর ওই টাকায় মাল
     * কেনার সিদ্ধান্ত নেওয়া হত।
     */
    public function test_recording_a_contribution_does_not_touch_the_books(): void
    {
        $before = $this->balanceOf($this->cashAccount()->code);

        $entry = $this->record('Md. Alamin', '500000');

        $this->assertSame(CapitalEntry::DRAFT, $entry->status);
        $this->assertNull($entry->voucher_id);

        $this->assertSame(0, bccomp($this->balanceOf($this->cashAccount()->code), $before, 4),
            'টাকা আসার আগেই নগদ বেড়ে গেছে।');
    }

    /**
     * পোস্ট করলে নগদ বাড়ে আর মূলধন বাড়ে — দুইটাই।
     */
    public function test_posting_puts_the_money_in_and_the_capital_up(): void
    {
        $cashBefore = $this->balanceOf($this->cashAccount()->code);
        $capitalBefore = $this->balanceOf(StandardChart::OWNER_CAPITAL);

        $entry = $this->record('Md. Alamin', '500000');

        app(CapitalService::class)->post($entry, $this->cashAccount());

        $this->assertSame(0, bccomp(
            $this->balanceOf($this->cashAccount()->code),
            bcadd($cashBefore, '500000', 4),
            4,
        ), 'নগদ বাড়েনি।');

        /* মূলধন ইকুইটি — ক্রেডিট প্রকৃতির, তাই ডেবিট − ক্রেডিট কমে */
        $this->assertSame(0, bccomp(
            $this->balanceOf(StandardChart::OWNER_CAPITAL),
            bcsub($capitalBefore, '500000', 4),
            4,
        ), 'মূলধন বাড়েনি।');

        $fresh = $entry->fresh();

        $this->assertSame(CapitalEntry::POSTED, $fresh->status);
        $this->assertNotNull($fresh->voucher_id, 'খাতার সাথে জোড়াটা লেখা হয়নি।');
        $this->assertNotNull($fresh->posted_at);
    }

    /**
     * দুইবার পোস্ট করা যায় না।
     *
     * ব্যস্ত সময়ে দুইটা ট্যাব, একটা দ্বিতীয় ক্লিক — আর তখন মালিকের
     * পাঁচ লাখ খাতায় দশ লাখ হয়ে যেত।
     */
    public function test_the_same_contribution_cannot_be_posted_twice(): void
    {
        $entry = $this->record('Md. Alamin', '100000');

        app(CapitalService::class)->post($entry, $this->cashAccount());

        $this->expectException(ValidationException::class);

        app(CapitalService::class)->post($entry->fresh(), $this->cashAccount());
    }

    /**
     * মাথায় টাকা বসানো যায় না।
     *
     * "নগদ ও ব্যাংক" একটা মাথা, খাত নয় — ওখানে বসানো টাকা কোনো
     * ব্যালেন্সে দেখাত না। আদায়ের পর্দায় এই একই বাধা আছে।
     */
    public function test_money_cannot_land_in_a_heading(): void
    {
        $entry = $this->record('Md. Alamin', '1000');

        $group = Account::query()->where('is_group', true)->firstOrFail();

        $this->expectException(ValidationException::class);

        app(CapitalService::class)->post($entry, $group);
    }

    /**
     * কে কোথায় দাঁড়িয়ে — আর খসড়া সেখানে গোনা হয় না।
     *
     * খসড়া মানে টাকা আসেনি। গুনলে কারও অংশ বেশি দেখাত, আর অংশীদারি
     * ব্যবসায় ওই সংখ্যাটা নিয়েই ঝগড়া হয়।
     */
    public function test_only_money_that_arrived_counts_towards_a_position(): void
    {
        $posted = $this->record('Karim', '300000');
        app(CapitalService::class)->post($posted, $this->cashAccount());

        $this->record('Karim', '200000');   // খসড়া — আসেনি

        $karim = collect(app(CapitalService::class)->positions())->firstWhere('name', 'Karim');

        $this->assertNotNull($karim);
        $this->assertSame(0, bccomp($karim['contributed'], '300000', 4),
            'খসড়াও গোনা হয়েছে — অংশটা বেশি দেখাচ্ছে।');
    }

    /**
     * পর্দাটা খোলে, আর নতুন সারি সেখান থেকেই বসে।
     */
    public function test_the_screen_records_and_posts(): void
    {
        $this->get(route('accounts.capital.index'))->assertOk();

        $this->post(route('accounts.capital.store'), [
            'contributor_name' => 'Rahim',
            'contributor_type' => CapitalEntry::PARTNER,
            'entry_type' => CapitalEntry::INVESTMENT,
            'trx_date' => now()->toDateString(),
            'amount' => '250000',
            'share_percent' => '40',
        ])->assertRedirect(route('accounts.capital.index'));

        $entry = CapitalEntry::query()->where('contributor_name', 'Rahim')->firstOrFail();

        $this->assertSame(CapitalEntry::INVESTMENT, $entry->entry_type);
        $this->assertSame(0, bccomp((string) $entry->share_percent, '40', 4));

        $this->post(route('accounts.capital.post', $entry), [
            'received_into_account_id' => $this->cashAccount()->id,
        ])->assertRedirect();

        $this->assertSame(CapitalEntry::POSTED, $entry->fresh()->status);
    }

    /**
     * শূন্য বা ঋণাত্মক মূলধন বলে কিছু নেই।
     *
     * টাকা ফেরত নেওয়া মূলধন নয়, উত্তোলন — আর ওটার নিজের খাত আছে
     * (`3200`)। ঋণাত্মক মূলধন লিখতে দিলে দুইটা আলাদা ঘটনা এক ঘরে
     * জমত, আর "কে কত দিয়েছেন" আর সত্যি বলত না।
     */
    public function test_capital_must_be_more_than_nothing(): void
    {
        $this->expectException(ValidationException::class);

        $this->record('Md. Alamin', '0');
    }
}
