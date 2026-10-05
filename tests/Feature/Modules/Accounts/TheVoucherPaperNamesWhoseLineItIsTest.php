<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * ভাউচারের কাগজে প্রতিটা সারির পক্ষ — মালিক, ৫ অক্টোবর ২০২৬ (ডেমো JRN-0003)।
 *
 * ⛔ জাবেদায় Rahim Store-এর ১০০ টাকা Sujon Sumon-এর নামে সরানো হলো; কাগজে দুই সারিতেই কেবল "1110 প্রাপ্য হিসাব" —
 * কার থেকে কার নামে, কাগজ বলত না ("etaw ager motoi")।
 *
 * দাবি: সারির খাতের পাশে পক্ষের নাম, প্রতিটা সারিতে তার নিজের; পক্ষ ছাড়া সারিতে কিছু যোগ হয় না।
 */
final class TheVoucherPaperNamesWhoseLineItIsTest extends TestCase
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

    public function test_each_line_of_a_journal_names_its_own_party(): void
    {
        $customer = Customer::query()->create(['code' => 'VPN-1', 'name_en' => 'Rahim Store', 'name_bn' => 'Rahim Store', 'is_active' => true]);
        $person = Person::query()->create(['code' => 'VPN-P1', 'name_en' => 'Sujon Sumon', 'is_active' => true]);
        $receivable = (int) Account::query()->where('is_group', false)->where('code', 'like', StandardChart::RECEIVABLE.'%')->orderBy('code')->value('id');
        $income = (int) Account::query()->where('is_group', false)->where('type', 'income')->orderBy('code')->value('id');

        $voucher = app(VoucherService::class)->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
        ], [
            ['account_id' => $receivable, 'debit' => '0', 'credit' => '100', 'party_type' => 'customer', 'party_id' => $customer->id],
            ['account_id' => $receivable, 'debit' => '100', 'credit' => '0', 'party_type' => 'person', 'party_id' => $person->id],
        ]);

        $lines = $this->linesOnPaper($voucher);

        $this->assertCount(2, $lines);
        $this->assertStringEndsWith(' — Rahim Store', $lines[0]['account'], '⛔ প্রথম সারিতে গ্রাহকের নাম নেই।');
        $this->assertStringEndsWith(' — Sujon Sumon', $lines[1]['account'], '⛔ দ্বিতীয় সারিতে ব্যক্তির নাম নেই।');

        // ⓘ পক্ষ ছাড়া সারি আগের মতোই — কেবল খাত
        $plain = app(VoucherService::class)->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'narration' => 'পক্ষ ছাড়া',
        ], [
            ['account_id' => $receivable, 'debit' => '50', 'credit' => '0', 'party_type' => 'customer', 'party_id' => $customer->id],
            ['account_id' => $income, 'debit' => '0', 'credit' => '50'],
        ]);

        $this->assertStringNotContainsString(' — ', $this->linesOnPaper($plain)[1]['account']);
    }

    /** @return list<array<string, string>> */
    private function linesOnPaper(Voucher $voucher): array
    {
        $seen = [];
        // ⓘ নকশা ভেদে টেমপ্লেটের নাম বদলায় ([[VoucherDesigns]]) — তাই যে ভিউ খাতের সারি পায়, সেটাই ধরা
        View::composer('*', function ($view) use (&$seen) {
            $data = $view->getData();
            $doc = $data['doc'] ?? $data['voucher'] ?? $data;
            if (is_array($doc) && isset($doc['lines']) && $seen === []) {
                $seen = $doc;
            }
        });

        $this->get(route('accounts.voucher.print', $voucher))->assertOk();
        $this->assertNotSame([], $seen, 'ছাপার টেমপ্লেট ডাকাই হয়নি।');

        return array_values((array) $seen['lines']);
    }
}
