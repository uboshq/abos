<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\BankReconciliation;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\TillHandover;
use App\Modules\Accounts\Services\CashTillService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * টাকার কাগজের তালিকায় শাখার দেয়াল — অডিট ⛔৪ (৬ অক্টোবর ২০২৬)।
 *
 * ⛔ টাকা বদলি, ক্যাশ গোনা, চেক, নোট, ঋণ, স্থায়ী সম্পদ, ব্যাংক মেলানো আর টিল হস্তান্তর কেবল কোম্পানির দেয়ালে ছিল;
 * এক শাখায় সীমিত মানুষ অন্য শাখার টাকার কাগজ দেখতেন।
 *
 * দাবি:
 *  - টাকা বদলি (দুই দিকের কাগজ): শাখা A-তে সীমিত মানুষ দেখেন B থেকে A-র টিলে আসা বদলি (নিজের টিলে আসা টাকা গ্রহণ
 *    করতে হয়), কিন্তু B থেকে B-র টিলে বদলি দেখেন না; সীমাহীন মালিক দুইটাই দেখেন।
 *  - বাকি সাতটা কাগজের প্রতিটার কোয়েরিতে সীমিত মানুষের জন্য শাখার শর্ত বসে, মালিকের জন্য বসে না।
 */
final class TheMoneyPapersStayBehindTheBranchWallTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_limited_person_sees_only_the_money_papers_in_reach(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($company->id, $a->id);
        $this->actingAs($owner);

        $till = function (string $code, int $branch): int {
            $t = app(CashTillService::class)->create(['code' => $code, 'name_en' => 'Wall till '.$code]);
            CashTill::query()->withoutGlobalScopes()->whereKey($t->id)->update(['branch_id' => $branch]);

            return (int) $t->id;
        };
        $tillA = $till('WTA', $a->id);
        $tillB = $till('WTB', $b->id);
        $tillB2 = $till('WTB2', $b->id);

        $year = (int) FinancialYear::query()->where('company_id', $company->id)->value('id');
        $transfer = fn (string $no, int $from, int $to) => DB::table('money_transfers')->insertGetId([
            'company_id' => $company->id, 'branch_id' => $b->id, 'financial_year_id' => $year, 'document_no' => $no,
            'trx_date' => now()->toDateString(), 'amount' => '100', 'from_till_id' => $from, 'to_till_id' => $to,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $intoA = $transfer('WT-INTO-A', $tillB, $tillA);
        $insideB = $transfer('WT-INSIDE-B', $tillB, $tillB2);

        $clerk = User::factory()->create(['current_company_id' => $company->id, 'current_branch_id' => null, 'is_active' => true]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $a->id,
        ]);

        // ── শাখা A-তে সীমিত মানুষ, "সব শাখা" ──
        $this->as($company, $clerk);
        $seen = MoneyTransfer::query()->pluck('id')->all();
        $this->assertContains($intoA, $seen, '⛔ নিজের টিলে আসা বদলি দেখা যায় না — গ্রহণই করা যেত না।');
        $this->assertNotContains($insideB, $seen, '⛔ অন্য শাখার ভেতরের বদলি দেখা গেল।');

        foreach ([CashCount::class, Cheque::class, Note::class, Loan::class, FixedAsset::class, BankReconciliation::class, TillHandover::class] as $model) {
            $sql = $model::query()->toSql();
            $this->assertStringContainsString('branch_id', $sql, "⛔ {$model}: সীমিত মানুষের কোয়েরিতে শাখার শর্ত নেই।");
        }

        // ── সীমাহীন মালিক — দুইটা বদলিই, আর কোয়েরিতে শাখার শর্ত নেই ──
        $this->as($company, $owner);
        $seen = MoneyTransfer::query()->pluck('id')->all();
        $this->assertContains($insideB, $seen, 'মালিক অন্য শাখার বদলি দেখেন না — দাবি অন্ধ।');
        $this->assertStringNotContainsString('branch_id', Cheque::query()->toSql(), 'মালিকের কোয়েরিতেও শাখার শর্ত — "সব শাখা" ভাঙল।');
    }

    private function as(Company $company, User $who): void
    {
        $who->forceFill(['current_company_id' => $company->id, 'current_branch_id' => null])->save();
        CompanyContext::set($company->id, null);
        $this->actingAs($who->fresh());
        app(DataScope::class)->forget();
    }
}
