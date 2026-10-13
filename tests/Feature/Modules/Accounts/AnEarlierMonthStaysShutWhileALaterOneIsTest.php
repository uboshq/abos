<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\PeriodLock;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ পরের মাস তালাবন্ধ থাকতে আগের মাস খোলে না — Accounts লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⓘ বন্ধের দিকে নিয়মটা ছিল (আগেরটা খোলা রেখে পরেরটা বন্ধ নয়, [[AnEarlierMonthStayedOpenTest]]), খোলার দিকে ছিল না: আগস্ট আর
 * সেপ্টেম্বর বন্ধ, আগস্ট খুলে বসালে সেপ্টেম্বর তবু "বন্ধ" দেখাত, অথচ তার ছাপা শুরুর জের বদলে গেছে।
 */
final class AnEarlierMonthStaysShutWhileALaterOneIsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_later_month_must_be_reopened_first(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $earlier = now()->subMonthsNoOverflow(2);
        $later = now()->subMonthNoOverflow();
        $lock = fn ($m) => PeriodLock::query()->create(['company_id' => $company->id, 'year' => (int) $m->year, 'month' => (int) $m->month,
            'locked_by' => $owner->id, 'locked_at' => now()]);
        $first = $lock($earlier);
        $second = $lock($later);

        $this->from(route('accounts.period.index'))
            ->post(route('accounts.period.reopen', $first), ['reason' => 'ভুল বিল ঠিক করতে'])
            ->assertSessionHasErrors(['reason' => __('accounts::validation.later_month_locked', ['later' => $second->label(), 'month' => $first->label()])]);

        $this->assertNotNull($first->fresh(), '⛔ পরের মাস তালাবন্ধ রেখে আগের মাস খুলে গেল');

        // ⓘ ক্রমে — আগে পরেরটা, তারপর আগেরটা
        $this->from(route('accounts.period.index'))->post(route('accounts.period.reopen', $second), ['reason' => 'ক্রমে খোলা'])->assertSessionHasNoErrors();
        $this->from(route('accounts.period.index'))->post(route('accounts.period.reopen', $first), ['reason' => 'ক্রমে খোলা'])->assertSessionHasNoErrors();
        $this->assertNull($first->fresh());
    }
}
