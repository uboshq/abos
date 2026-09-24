<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\InterCompanyTransfer;
use App\Modules\Accounts\Services\InterCompanyService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * এক কোম্পানি আরেকজনকে টাকা দিল — আর দুই খাতাই সেটা বলে।
 *
 * ── ⭐ মালিকের প্রশ্ন, ২৫ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"গ্রুপের অ্যাকাউন্ট থেকে টাকা নিলে?"*
 *
 * ── ⚠️ সবচেয়ে সূক্ষ্ম দাবিটা কোনটা ─────────────────────────────────
 * পাওয়ার দিকের দাখিলা **অন্য কোম্পানির প্রসঙ্গে** বসে। ⛔ যদি প্রসঙ্গটা
 * ফিরে না আসে, এর পরের প্রতিটা কোয়েরি ভুল কোম্পানিতে চলবে — অনুরোধের
 * বাকি অংশ জুড়ে, কোনো ত্রুটিবার্তা ছাড়াই। ⓘ তাই প্রসঙ্গ ফেরার দাবিটা
 * আলাদা করে মাপা হয়।
 */
final class OneCompanyPaidForAnotherAndNeitherBookSaidSoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
    }

    /**
     * দুই খাতায় দুইটা দাখিলা, আর তারা একে অন্যের আয়না।
     */
    public function test_both_books_get_the_entry(): void
    {
        [$owner, $alpha, $beta] = $this->cast();

        CompanyContext::set((int) $alpha->id);

        $transfer = app(InterCompanyService::class)->record($owner, [
            'counter_company_id' => $beta->id,
            'trx_date' => now()->toDateString(),
            'amount' => '5000.0000',
            'purpose' => 'ভাড়ার টাকা',
            'from_account_id' => $this->money($alpha)->id,
            'to_account_id' => $this->money($beta)->id,
        ]);

        $this->assertTrue($transfer->isBalanced(), implode(PHP_EOL, [
            'দুই পাশের ভাউচার বসেনি।',
            '',
            '⛔ এক পাশ বসে অন্যটা না বসলে খাতা মেলে না, আর সারিটা তবুও',
            '"হয়ে গেছে" বলত।',
        ]));

        /*
         * ⭐ আয়নার পরীক্ষা: আমাদের খাতায় চলতি হিসাব **ডেবিট**,
         * তাদের খাতায় ঠিক ততটাই **ক্রেডিট**।
         *
         * ⚠️ কেবল "দুইটা ভাউচার আছে" যথেষ্ট নয় — ভুল দিকে বসলেও
         * দুইটাই থাকত, আর সংখ্যাটা দেখতে সঠিকের মতোই লাগত।
         */
        $ours = $this->controlLine($alpha, (int) $transfer->out_voucher_id);
        $theirs = $this->controlLine($beta, (int) $transfer->in_voucher_id);

        $this->assertSame(0, bccomp((string) $ours->debit, '5000', 4),
            'আমাদের খাতায় চলতি হিসাব ডেবিট হয়নি — পেলাম '.$ours->debit);

        $this->assertSame(0, bccomp((string) $theirs->credit, '5000', 4),
            'তাদের খাতায় চলতি হিসাব ক্রেডিট হয়নি — পেলাম '.$theirs->credit);
    }

    /**
     * ⛔ অন্য কোম্পানিতে লিখতে গিয়ে প্রসঙ্গটা যেন ওখানেই থেকে না যায়।
     */
    public function test_the_company_context_comes_back(): void
    {
        [$owner, $alpha, $beta] = $this->cast();

        CompanyContext::set((int) $alpha->id);

        app(InterCompanyService::class)->record($owner, [
            'counter_company_id' => $beta->id,
            'trx_date' => now()->toDateString(),
            'amount' => '100.0000',
            'purpose' => 'পরীক্ষা',
            'from_account_id' => $this->money($alpha)->id,
            'to_account_id' => $this->money($beta)->id,
        ]);

        $this->assertSame((int) $alpha->id, CompanyContext::id(), implode(PHP_EOL, [
            'লেখার পরে প্রসঙ্গটা ফিরে আসেনি।',
            '',
            '⛔ এটাই সবচেয়ে নীরব ভুল: এর পরের প্রতিটা কোয়েরি ভুল',
            'কোম্পানিতে চলত, আর কিছুই লাল হত না।',
            '',
            '⭐ CompanyContext::forCompany() ব্যবহার করুন, ::set() নয় —',
            'প্রথমটা finally-তে আগেরটা ফিরিয়ে দেয়।',
        ]));

        /*
         * ⓘ আর সারিটা সত্যিই আমাদের কোম্পানির নামে বসেছে কি না —
         * স্কোপ ধরে গুনে দেখা।
         */
        $this->assertSame(1, InterCompanyTransfer::query()->count(),
            'নিজের কোম্পানির স্কোপে সারিটা পাওয়া যাচ্ছে না।');
    }

    /**
     * ⛔ দুইটা কোম্পানিতেই সদস্যপদ না থাকলে কিছুই লেখা হয় না।
     */
    public function test_someone_outside_the_pair_is_refused(): void
    {
        [, $alpha, $beta] = $this->cast();

        CompanyContext::set((int) $alpha->id);

        /* ⓘ হিসাবরক্ষক — DemoSeeder তাঁকে কেবল alpha-তে বসায় */
        $onlyAlpha = User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey($alpha->id))
            ->whereDoesntHave('companies', fn ($q) => $q->whereKey($beta->id))
            ->firstOrFail();

        $this->expectException(ValidationException::class);

        app(InterCompanyService::class)->record($onlyAlpha, [
            'counter_company_id' => $beta->id,
            'trx_date' => now()->toDateString(),
            'amount' => '100.0000',
            'purpose' => 'হওয়ার কথা নয়',
            'from_account_id' => $this->money($alpha)->id,
            'to_account_id' => $this->money($alpha)->id,
        ]);
    }

    /**
     * ⛔ পর্দাটা নিজের চাবি ছাড়া খোলে না।
     *
     * ⚠️ দাবিটা আলাদা করে দরকার, কারণ `accounts.%` ঢালাও নিয়মে চাবিটা
     * হিসাবরক্ষকের হাতে চলে যেতে পারে — আজ গ্রুপ রিপোর্টে ঠিক সেটাই
     * হয়েছিল, আর ধরা পড়েছিল কেবল এইরকম একটা দাবিতে।
     */
    public function test_the_screen_needs_its_own_key(): void
    {
        [$owner, $alpha, $beta] = $this->cast();

        CompanyContext::set((int) $alpha->id);

        $this->actingAs($owner);
        $this->get(route('accounts.inter_company.index'))->assertOk();

        $lesser = User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey($alpha->id))
            ->whereDoesntHave('companies', fn ($q) => $q->whereKey($beta->id))
            ->firstOrFail();

        $lesser->givePermissionTo('accounts.report.final');
        $lesser->forgetCachedPermissions();

        $this->actingAs($lesser);
        $this->get(route('accounts.inter_company.index'))->assertForbidden();
    }

    /** @return array{0: User, 1: Company, 2: Company} */
    private function cast(): array
    {
        return [
            User::query()->where('email', 'owner@abos.test')->firstOrFail(),
            Company::query()->where('code', 'TDEPOT')->firstOrFail(),
            Company::query()->where('code', 'FMART')->firstOrFail(),
        ];
    }

    /** ⓘ ঐ কোম্পানির প্রথম টাকার খাত — নাম টাইপ করা হয় না। */
    private function money(Company $company): Account
    {
        return CompanyContext::forCompany((int) $company->id, fn () => Account::query()
            ->whereIn('money_kind', Account::MONEY_KINDS)
            ->where('is_group', false)
            ->orderBy('code')
            ->firstOrFail());
    }

    /** ভাউচারের সেই লাইনটা যেটা চলতি হিসাবের খাতে বসেছে। */
    private function controlLine(Company $company, int $voucherId): object
    {
        return CompanyContext::forCompany((int) $company->id, function () use ($voucherId) {
            $control = StandardChart::find(StandardChart::INTER_COMPANY);

            return DB::table('voucher_lines')
                ->where('voucher_id', $voucherId)
                ->where('account_id', $control?->id)
                ->firstOrFail();
        });
    }
}
