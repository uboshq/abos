<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ WPPF-এর খাত দুটো (৫২২৩ খরচ, ২১৩৩ দেনা) সিস্টেমের — বাকি আইনি বেতনের খাতের মতো: নিষ্ক্রিয় করা যায় না, ধরন বদলায় না (fe-র
 * সিদ্ধান্ত ৪, ২১ অক্টোবর ২০২৬; [[StandardChart::SYSTEM_CODES]], [[HrLawAccounts]])।
 *
 * ⓘ WPPF-এর জাবেদা কোড ধরে খাত খোঁজে; খাতটা নিষ্ক্রিয় হলে বা দেনা থেকে সম্পদে সরলে মুনাফার ৫% ভুল জায়গায় বসত, আর স্থিতিপত্রে
 * শ্রমিকের পাওনা হারাত। আগে এ দুটো চিহ্নহীন বসত, আর ছকের পর্দা থেকে যে কেউ চাবিতে তা করতে পারতেন।
 *
 * দাবি (ছকের পর্দার দরজায়, মালিক হয়ে):
 *  - নতুন বসানো ছকে দুটোই সিস্টেমের; "মুছুন" (নিষ্ক্রিয়) ৪০৩, খাত সচল; ধরন বদল ফেরে সিস্টেমের খাতের কারণে, ধরন অক্ষত।
 *  - পুরনো কোম্পানিতে চিহ্নহীন দুটো `abos:hr-law-accounts` চালালে সিস্টেমের হয়; তারপর দরজা একই কথা বলে; আবার চালালে কিছুই নয়।
 */
final class TheWppfAccountsAreSystemAccountsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_fresh_chart_marks_both_and_the_chart_screen_refuses_to_delete_or_retype_them(): void
    {
        foreach ([StandardChart::WPPF_EXPENSE, StandardChart::WPPF_PAYABLE] as $code) {
            $this->assertTrue((bool) StandardChart::find($code)?->is_system, "⛔ {$code} নতুন ছকে সিস্টেমের নয়।");
        }

        $this->assertRefusedAtTheDoor(StandardChart::WPPF_EXPENSE, Account::ASSET);
        $this->assertRefusedAtTheDoor(StandardChart::WPPF_PAYABLE, Account::ASSET);
    }

    public function test_the_installer_marks_existing_unmarked_wppf_accounts_as_system_once(): void
    {
        // ⓘ সিস্টেমের তালিকায় আসার আগের কোম্পানি — দুটো খাত আছে, চিহ্ন নেই
        DB::table('accounts')->where('company_id', $this->company->id)
            ->whereIn('code', [StandardChart::WPPF_EXPENSE, StandardChart::WPPF_PAYABLE])->update(['is_system' => false]);
        $this->assertFalse((bool) StandardChart::find(StandardChart::WPPF_PAYABLE)->is_system, 'প্রস্তুতি — চিহ্ন সরল না।');
        $ids = Account::query()->whereIn('code', [StandardChart::WPPF_EXPENSE, StandardChart::WPPF_PAYABLE])->orderBy('code')->pluck('id')->all();

        $this->artisan('abos:hr-law-accounts')->assertSuccessful();

        foreach ([StandardChart::WPPF_EXPENSE, StandardChart::WPPF_PAYABLE] as $code) {
            $this->assertTrue((bool) StandardChart::find($code)?->is_system, "⛔ কমান্ডের পরেও {$code} সিস্টেমের নয়।");
            $this->assertSame(1, Account::query()->where('code', $code)->count(), "⛔ {$code} আবার বসল।");
        }
        $this->assertSame($ids, Account::query()->whereIn('code', [StandardChart::WPPF_EXPENSE, StandardChart::WPPF_PAYABLE])->orderBy('code')
            ->pluck('id')->all(), '⛔ চিহ্ন দিতে গিয়ে খাত নতুন করে বসল — পুরনো দাখিলা অন্য খাতে।');

        $this->assertRefusedAtTheDoor(StandardChart::WPPF_PAYABLE, Account::ASSET);
        $this->assertRefusedAtTheDoor(StandardChart::WPPF_EXPENSE, Account::LIABILITY);

        $this->artisan('abos:hr-law-accounts')->expectsOutputToContain('সব কোম্পানিতে খাতগুলো আগে থেকেই আছে')->assertSuccessful();
    }

    private function assertRefusedAtTheDoor(string $code, string $otherType): void
    {
        $account = StandardChart::find($code);
        $type = (string) $account->type;

        $this->actingAs($this->owner)->delete(route('accounts.coa.destroy', $account))->assertForbidden();
        $this->assertTrue((bool) $account->fresh()->is_active, "⛔ {$code} ছকের পর্দা থেকে নিষ্ক্রিয় হলো।");

        $response = $this->actingAs($this->owner)->from(route('accounts.coa.edit', $account))->put(route('accounts.coa.update', $account), [
            'code' => $account->code, 'name_en' => $account->name_en, 'name_bn' => $account->name_bn, 'parent_id' => $account->parent_id,
            'type' => $otherType,
        ]);
        $response->assertSessionHasErrors(['type' => __('accounts::validation.system_account_locked', ['name' => $account->fresh()->name()])]);
        $this->assertSame($type, (string) $account->fresh()->type, "⛔ {$code}-এর ধরন ছকের পর্দা থেকে বদলাল।");
    }
}
