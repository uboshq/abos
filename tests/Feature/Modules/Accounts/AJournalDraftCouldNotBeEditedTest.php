<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খসড়া জাবেদা সম্পাদনা করে জমা দিলে ৫০০ — ৭ অক্টোবর ২০২৬, ভাউচারের পরিকল্পনা ৩গ-র পরীক্ষায় ধরা পড়ল।
 *
 * ⛔ জাবেদার পর্দা `lines` পাঠায়; [[VoucherService::update()]] পুরো তথ্য মাথায় ঢালত, আর `lines` মাথার ঘর নয় — তাই
 * সম্পাদনা কখনো সংরক্ষণ হতো না। ⭐ এখন মাথায় কেবল মাথার ঘর যায়, সারি যায় সারিতে।
 */
final class AJournalDraftCouldNotBeEditedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_journal_draft_edited_on_the_screen_keeps_the_new_lines(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();
        $form = fn (string $amount, string $narration) => [
            'type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => $narration, 'save_as_draft' => '1',
            'lines' => [['account_id' => $a->id, 'debit' => $amount, 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => $amount]],
        ];

        $this->post(route('accounts.voucher.store', 'journal'), $form('100', 'প্রথম লেখা'))->assertSessionHasNoErrors();
        $voucher = Voucher::query()->latest('id')->firstOrFail();

        $this->put(route('accounts.voucher.update', $voucher), $form('250', 'আবার লেখা'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('accounts.voucher.show', $voucher));

        $fresh = $voucher->fresh(['lines']);
        $this->assertSame('আবার লেখা', $fresh->narration, '⛔ খসড়া জাবেদার সম্পাদনা সংরক্ষণ হলো না।');
        $this->assertCount(2, $fresh->lines);
        $this->assertEqualsWithDelta(250.0, (float) $fresh->lines->sum('debit'), 0.0001, '⛔ নতুন সারি বসেনি।');
        $this->assertEqualsWithDelta(250.0, (float) $fresh->lines->sum('credit'), 0.0001, '⛔ নতুন সারি বসেনি।');
        $this->assertTrue($fresh->isDraft());
    }
}
