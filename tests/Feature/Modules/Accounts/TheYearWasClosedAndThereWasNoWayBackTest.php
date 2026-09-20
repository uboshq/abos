<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\YearEndService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * বন্ধ বছর আবার খোলা যায় — কেবল সুপার অ্যাডমিনের হাতে।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * মালিক লাইভে ২০২৬-২০২৭ বন্ধ করে দেখলেন ফেরার কোনো পথ নেই: *"অর্থবছরগুলো
 * বন্ধ korechi calur option nai keno. super admin er kache seta thakte
 * hobe"*। ⓘ বন্ধ করা এক-মুখী বলেই ভুলটা সহজে হয়, আর হয়ে গেলে গোটা বছরের
 * কাজ আটকে থাকে।
 *
 * ⭐ সমাপনীর দাখিলা উল্টে দেওয়া হয়, মোছা হয় না — খাতায় গর্ত রাখা যায় না।
 */
final class TheYearWasClosedAndThereWasNoWayBackTest extends TestCase
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
    }

    public function test_a_super_admin_can_reopen_the_year_and_the_closing_entry_is_reversed(): void
    {
        $year = $this->currentYear();

        app(YearEndService::class)->close($year);

        $this->assertTrue($year->fresh()->is_closed, 'বছরটা বন্ধই হয়নি।');

        $closingRows = LedgerEntry::query()
            ->where('source_type', YearEndService::CLOSE_SOURCE)
            ->where('source_id', $year->id)
            ->count();

        app(YearEndService::class)->reopen($year->fresh(), $this->owner);

        $fresh = $year->fresh();

        $this->assertFalse($fresh->is_closed, 'বছরটা খোলেনি।');
        $this->assertTrue($fresh->is_current, 'খোলা বছরটা চলতি হয়নি।');
        $this->assertNull($fresh->closed_at, 'বন্ধের তারিখটা রয়ে গেছে।');

        /* ⓘ সমাপনীর সারিগুলো থেকে যায়, সাথে সমান সংখ্যক উল্টো সারি */
        $this->assertSame($closingRows, LedgerEntry::query()
            ->where('source_type', YearEndService::CLOSE_SOURCE)
            ->where('source_id', $year->id)
            ->count(), 'সমাপনীর সারি মুছে ফেলা হয়েছে — ইতিহাসে গর্ত।');

        $this->assertSame($closingRows, LedgerEntry::query()
            ->where('source_type', YearEndService::CLOSE_SOURCE.':reversal')
            ->where('source_id', $year->id)
            ->count(), 'উল্টো দাখিলাটা বসেনি।');
    }

    /** ⛔ রোজকার কেউ নয় — অনুমতি থাকলেও নয়, কেবল সুপার অ্যাডমিন। */
    public function test_anyone_else_is_refused(): void
    {
        $year = $this->currentYear();
        app(YearEndService::class)->close($year);

        $clerk = User::query()
            ->where('id', '!=', $this->owner->id)
            ->get()
            ->first(fn (User $u) => ! $u->roles->contains('name', PermissionSyncer::SUPER_ADMIN_ROLE));

        $this->assertNotNull($clerk, 'ডেমোতে সুপার অ্যাডমিন ছাড়া আর কেউ নেই।');

        $this->expectException(ValidationException::class);

        app(YearEndService::class)->reopen($year->fresh(), $clerk);
    }

    /** ⚠️ পুরনো বছর খুললে তার পরের সমাপনীগুলো ভিত্তিহীন হত। */
    public function test_only_the_year_closed_last_can_be_reopened(): void
    {
        $first = $this->currentYear();
        $second = app(YearEndService::class)->close($first);
        app(YearEndService::class)->close($second->fresh());

        $this->expectException(ValidationException::class);

        app(YearEndService::class)->reopen($first->fresh(), $this->owner);
    }

    /** নামটা হুবহু না লিখলে ফর্মটা থামে — বন্ধ করার মতোই। */
    public function test_the_screen_asks_for_the_name_before_reopening(): void
    {
        $year = $this->currentYear();
        app(YearEndService::class)->close($year);

        $this->post(route('accounts.year_end.reopen', $year), ['confirm' => 'ভুল নাম'])
            ->assertSessionHasErrors('confirm');

        $this->assertTrue($year->fresh()->is_closed, 'ভুল নাম লিখেও বছরটা খুলে গেছে।');

        $this->post(route('accounts.year_end.reopen', $year), ['confirm' => $year->name])
            ->assertRedirect(route('accounts.year_end.index'));

        $this->assertFalse($year->fresh()->is_closed, 'ঠিক নাম লিখেও বছরটা খোলেনি।');
    }

    private function currentYear(): FinancialYear
    {
        return FinancialYear::query()->where('is_current', true)->firstOrFail();
    }
}
