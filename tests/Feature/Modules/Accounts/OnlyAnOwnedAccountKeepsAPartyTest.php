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
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * হেডারের পক্ষ প্রতিটা সারিতে নেমে যেত।
 *
 * ── কী ঘটত ───────────────────────────────────────────────────────────
 * পোস্ট করার সময় প্রতিটা সারির পক্ষ ঠিক হত এভাবে:
 *
 *     $line->party_type ?? $voucher->party_type
 *
 * ⛔ কোনো শর্ত নেই। ফলে হেডারে একজন সরবরাহকারীর নাম থাকলে সেটা
 * **নগদের সারিতেও** বসত, **খরচের খাতেও** বসত:
 *
 *     Dr ৫১০০  খরচ    →  ⛔ সরবরাহকারী
 *     Cr ২১১১  প্রদেয়  →  ✅ সরবরাহকারী
 *     Cr ১১০১  নগদ    →  ⛔ সরবরাহকারী
 *
 * ⚠️ **আর ওটাই টাকাটা দুইবার গোনাত:** "এই সরবরাহকারীর সাথে লেনদেন"
 * খুঁজলে দেনার সারিটাও আসত, টাকা দেওয়ার সারিটাও — একই টাকা, দুইবার।
 *
 * ── কেন কেউ টের পায়নি ────────────────────────────────────────────────
 * হেডারের পক্ষের ঘরটা কোনো পর্দা ব্যবহার করত না। ৪ সেপ্টেম্বর ২০২৬-এ
 * মাপা: পক্ষসহ **পোস্ট করা ভাউচার শূন্য**। ⓘ বাকিতে খরচের পর্দাই তার
 * প্রথম ব্যবহারকারী — অর্থাৎ বাগটা সুপ্ত ছিল, আর ওই পর্দাটা যেদিন
 * চালু হত সেদিনই জাগত।
 *
 * ── এই পাহারাটা কী দেখে ──────────────────────────────────────────────
 * ⚠️ "পক্ষ বসেছে কি না" নয় — **কোন সারিতে কোন পক্ষ**। ⓘ কেবল সংখ্যা
 * গুনলে গার্ডটা অন্ধ হত: দুইটা সারিতে একই ভুল নাম বসেও "পক্ষ আছে"
 * সত্যি থাকত।
 */
final class OnlyAnOwnedAccountKeepsAPartyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /*
         * কোম্পানিটা **ব্যবহারকারীর কাছ থেকে** নেওয়া হয়, কোড ধরে নয়।
         *
         * ⚠️ এখানে আগে একটা ভুল ব্যাখ্যা লেখা ছিল — *"DemoSeeder
         * মানুষগুলোকে অন্য কোম্পানিতে বসায়"*। **সেটা মিথ্যা ছিল, আর
         * আমি যাচাই না করেই লিখেছিলাম**: সিডারের `$alpha` ভেরিয়েবলটার
         * কোডই `TDEPOT` (DemoSeeder:47-48), তাই কোনো অমিল কোনোদিন
         * ছিল না।
         *
         * ⓘ তবু ব্যবহারকারীর কোম্পানিই ধরা হয়, আর কারণটা আলাদা: HTTP
         * অনুরোধে কোম্পানি ঠিক হয় লগইন করা মানুষের
         * `current_company_id` থেকে। **সিডার কাল অন্য কোম্পানি বাছলে
         * এই টেস্ট তবু চলবে**, কারণ সে কিছু ধরে নেয় না।
         *
         * ⛔ আসল লালটা ছিল অন্য কারণে — `back()`-এর referer, যা
         * `from(...)` দিয়ে সারানো হয়েছে।
         */
        $company = Company::query()->findOrFail($this->owner->current_company_id);

        CompanyContext::set(
            $company->id,
            $this->owner->current_branch_id ?? $company->defaultBranch()?->id,
        );

        $this->actingAs($this->owner);
    }

    /**
     * প্রদেয়ের সারিতে পক্ষ বসে, খরচের সারিতে বসে না।
     */
    public function test_the_party_lands_only_on_the_payable_line(): void
    {
        $payable = $this->aPostableUnder(StandardChart::PAYABLE_GROUP);
        $expense = $this->anExpenseAccount();

        $lines = $this->postTwoLines($payable, $expense, 'supplier', $this->aSupplierId());

        $this->assertSame(
            ['supplier', $this->aSupplierId()],
            [$lines[$payable]['party_type'], (int) $lines[$payable]['party_id']],
            'প্রদেয়ের সারিতে পক্ষটা বসেনি — তাহলে "কাকে কত দিতে হবে" কেউ বলতে পারবে না।',
        );

        $this->assertNull(
            $lines[$expense]['party_type'],
            'খরচের খাতে পক্ষ বসে গেছে — খরচের খাত কারো নামে বসে থাকে না, '
            .'আর এতে ওই পক্ষের লেনদেনের তালিকায় টাকাটা দুইবার আসত।',
        );
    }

    /**
     * নগদেও বসে না — টাকার খাতের কোনো মালিক নেই।
     */
    public function test_money_never_carries_a_party(): void
    {
        $money = (int) Account::query()->money()->postable()->active()
            ->orderBy('code')->value('id');
        $expense = $this->anExpenseAccount();

        $lines = $this->postTwoLines($money, $expense, 'supplier', $this->aSupplierId());

        $this->assertNull(
            $lines[$money]['party_type'],
            'নগদের সারিতে পক্ষ বসে গেছে — টাকার খাত কারো নামে বসে থাকে না।',
        );
    }

    /**
     * সারিতে নিজের পক্ষ লেখা থাকলে সেটাই জেতে, হেডার নয়।
     *
     * ⓘ এটা না থাকলে উপরের দুইটা "পক্ষ কখনোই বসবে না" লিখেও সবুজ হত।
     */
    public function test_a_party_written_on_the_line_wins(): void
    {
        $payable = $this->aPostableUnder(StandardChart::PAYABLE_GROUP);
        $expense = $this->anExpenseAccount();
        $supplier = $this->aSupplierId();

        $voucher = app(VoucherService::class)->create(
            [
                'type' => Voucher::JOURNAL,
                'trx_date' => now()->toDateString(),
                'party_type' => 'supplier',
                'party_id' => $supplier,
                'narration' => 'PARTY-GUARD-LINE',
            ],
            [
                ['account_id' => $expense, 'debit' => '400', 'credit' => '0'],
                [
                    'account_id' => $payable, 'debit' => '0', 'credit' => '400',
                    // সারির নিজের পক্ষ — হেডারেরটা থেকে আলাদা
                    'party_type' => 'customer', 'party_id' => $this->aCustomerId(),
                ],
            ],
        );

        $lines = $this->ledgerOf(app(VoucherService::class)->post($voucher));

        $this->assertSame(
            ['customer', $this->aCustomerId()],
            [$lines[$payable]['party_type'], (int) $lines[$payable]['party_id']],
            'সারিতে লেখা পক্ষটা হেডারেরটার নিচে চাপা পড়েছে।',
        );
    }

    /**
     * একটা দুই-সারির ভাউচার পোস্ট করে খতিয়ানের সারিগুলো ফেরত দেয়।
     *
     * @return array<int, array{party_type: ?string, party_id: ?int}>
     */
    private function postTwoLines(int $credit, int $debit, string $partyType, int $partyId): array
    {
        $voucher = app(VoucherService::class)->create(
            [
                'type' => Voucher::JOURNAL,
                'trx_date' => now()->toDateString(),
                'party_type' => $partyType,
                'party_id' => $partyId,
                'narration' => 'PARTY-GUARD',
            ],
            [
                ['account_id' => $debit, 'debit' => '400', 'credit' => '0'],
                ['account_id' => $credit, 'debit' => '0', 'credit' => '400'],
            ],
        );

        return $this->ledgerOf(app(VoucherService::class)->post($voucher));
    }

    /**
     * @return array<int, array{party_type: ?string, party_id: ?int}>
     */
    private function ledgerOf(Voucher $voucher): array
    {
        $rows = DB::table('ledger_entries')
            ->where('source_type', Voucher::SOURCE_TYPES[$voucher->type])
            ->where('source_id', $voucher->id)
            ->get(['account_id', 'party_type', 'party_id']);

        $this->assertCount(2, $rows, 'ভাউচারটা খতিয়ানে দুইটা সারি লেখেনি।');

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->account_id] = [
                'party_type' => $row->party_type,
                'party_id' => $row->party_id,
            ];
        }

        return $out;
    }

    /**
     * এই দলের নিচে দাখিলা বসানো যায় এমন একটা খাত।
     *
     * ⚠️ `selfAndDescendants()` কেবল `id` ও `parent_id` আনে — তাই
     * `is_group` ওখান থেকে দেখা যায় না, আসল সারিটা আবার পড়তে হয়।
     */
    private function aPostableUnder(string $code): int
    {
        $ids = StandardChart::find($code)->selfAndDescendants()
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return (int) Account::query()->whereIn('id', $ids)
            ->postable()->active()->orderBy('code')->value('id');
    }

    private function anExpenseAccount(): int
    {
        return (int) Account::query()->postable()->active()
            ->where('type', Account::EXPENSE)->orderBy('code')->value('id');
    }

    private function aSupplierId(): int
    {
        return (int) DB::table('suppliers')
            ->where('company_id', CompanyContext::id())->orderBy('id')->value('id');
    }

    private function aCustomerId(): int
    {
        return (int) DB::table('customers')
            ->where('company_id', CompanyContext::id())->orderBy('id')->value('id');
    }
}
