<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
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
 * ⛔ তালা দিয়ে আবার পড়া ([[ReadsTheRowUnderLock::lockFresh()]]) দেখার শাখার দেয়ালে আটকাত (fe, ১৩ অক্টোবর ২০২৬)।
 *
 * ⚠️ `newQuery()` মডেলের সব দেয়াল বসায় — এক শাখার ভাউচার, আর হেডারে অন্য শাখা বাছা মানুষ: হাতে থাকা কাগজটাই "No query results",
 * পাকা করা ([[VoucherWriter]]), বিল, রসিদ, জমা, বেতন — ২৩টা সেবার তালা মাঝপথে থামত। ⭐ এখন শাখার দেয়াল ছাড়া, কোম্পানির দেয়াল রেখে।
 */
final class ALockedReReadSeesTheRowInHandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_locked_re_read_finds_the_row_in_hand_whatever_branch_is_being_looked_at(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $vouchers = app(VoucherService::class);
        $voucher = $vouchers->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'lock probe'],
            [['account_id' => Account::query()->money()->postable()->active()->firstOrFail()->id, 'debit' => '100', 'credit' => '0'],
                ['account_id' => Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail()->id, 'debit' => '0', 'credit' => '100']],
        );
        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->whereKeyNot((int) $voucher->branch_id)->orderBy('id')->firstOrFail();

        $owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $other->id])->save();
        CompanyContext::set($company->id, $other->id);
        app(DataScope::class)->forget();
        $this->actingAs($owner->fresh());
        $this->assertNull(Voucher::query()->find($voucher->id), 'দৃশ্যটাই বানানো যায়নি — ভাউচার দেখার দেয়ালের ভিতরে');

        // ⓘ হাতের কপি বাসি — ডাটাবেজে বর্ণনা বদলেছে
        DB::table('vouchers')->where('id', $voucher->id)->update(['narration' => 'changed under us']);

        $reader = new class
        {
            use ReadsTheRowUnderLock;

            public function read(Voucher $voucher): void
            {
                DB::transaction(fn () => $this->lockFresh($voucher));
            }
        };

        try {
            $reader->read($voucher);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->fail('⛔ অন্য শাখা দেখানো অবস্থায় হাতের ভাউচারটাই তালায় "নেই"।');
        }

        $this->assertSame('changed under us', $voucher->narration, 'তালার পড়া হাতের কপিতে বসেনি।');
    }
}
