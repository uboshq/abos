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
 * ভাউচার বলত না কেন টাকা নড়ল — ভাউচারের আন্তর্জাতিক পরিকল্পনা, অংশ ৩খ, ৭ অক্টোবর ২০২৬: "বিবরণ বাধ্যতামূলক, সব ধরনে"।
 *
 * ⭐ ভাউচারের পর্দা থেকে বিবরণ ছাড়া কোনো ভাউচার জমা হয় না ([[VoucherRequest]])।
 */
final class AVoucherSaidNothingAboutWhyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_a_voucher_without_a_narration_is_refused_in_every_type(): void
    {
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->orderBy('code')->take(2)->get()->all();

        foreach (['', '   '] as $blank) {
            $this->post(route('accounts.voucher.store', 'journal'), [
                'type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => $blank, 'save_as_draft' => '1',
                'lines' => [['account_id' => $a->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '100']],
            ])->assertSessionHasErrors('narration');
        }

        $this->post(route('accounts.voucher.store', 'receipt'), ['type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'amount' => '100'])
            ->assertSessionHasErrors('narration');

        $this->assertSame(0, Voucher::query()->count(), '⛔ বিবরণ ছাড়া ভাউচার জমা হলো।');

        $this->post(route('accounts.voucher.store', 'journal'), [
            'type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'মাসশেষের সমন্বয়', 'save_as_draft' => '1',
            'lines' => [['account_id' => $a->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '100']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, Voucher::query()->count(), '⛔ বিবরণসহ ভাউচারও থামল।');
    }

    /** ⓘ সেটিং পর্দার "ভাউচারে বিবরণ বাধ্যতামূলক" — বন্ধে ঐচ্ছিক, আবার চালুতে আটকায় (fe, ৭ অক্টোবর ২০২৬) */
    public function test_the_settings_switch_decides_off_then_on_again(): void
    {
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->orderBy('code')->take(2)->get()->all();
        $blank = fn () => $this->post(route('accounts.voucher.store', 'journal'), [
            'type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => '', 'save_as_draft' => '1',
            'lines' => [['account_id' => $a->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '100']],
        ]);

        app(\App\Core\Services\SettingsService::class)->set('accounts.require_narration', false);
        $blank()->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Voucher::query()->count(), '⛔ সুইচ বন্ধ, তবু বিবরণ ছাড়া ভাউচার থামল।');

        app(\App\Core\Services\SettingsService::class)->set('accounts.require_narration', true);
        $blank()->assertSessionHasErrors('narration');
        $this->assertSame(1, Voucher::query()->count(), '⛔ সুইচ আবার চালু, তবু বিবরণ ছাড়া ভাউচার জমা হলো।');
    }
}
