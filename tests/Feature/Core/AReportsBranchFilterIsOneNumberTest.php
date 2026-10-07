<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * রিপোর্টের শাখার ছাঁকনি একটাই পূর্ণসংখ্যা — অডিট ⛔৭ (৬ অক্টোবর ২০২৬)।
 *
 * ⛔ `branch_id[]=B` পাঠালে নাগালের যাচাই `(int) array` = ১ দেখত, আর কোয়েরি `where(col, [B])` শাখা B পড়ত — শাখা A-তে
 * সীমিত মানুষ যেকোনো শাখার রিপোর্ট খুলতে পারতেন (ReportEngine::normaliseFilters)।
 * দাবি, শাখা A-তে সীমিত মানুষ: `[B]` ফেরে; `B` (আগের মতো) ফেরে; `A`, `0` আর খালি চলে — দাবিটা সত্যিই তাকায়।
 */
final class AReportsBranchFilterIsOneNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_array_branch_filter_cannot_climb_over_the_wall(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();

        $clerk = User::factory()->create(['current_company_id' => $company->id, 'current_branch_id' => null, 'is_active' => true]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $a->id,
        ]);
        CompanyContext::set($company->id, null);
        $this->actingAs($clerk);
        app(DataScope::class)->forget();

        $run = fn ($branch) => app(ReportEngine::class)->run('customer.ageing', ['to' => now()->toDateString(), 'branch_id' => $branch]);

        foreach ([[$b->id], [(string) $b->id], [$a->id, $b->id], $b->id, 'abc', '-3'] as $bad) {
            try {
                $run($bad);
                $this->fail('⛔ শাখার ছাঁকনি '.json_encode($bad).' দিয়ে দেয়ালের ওপারে যাওয়া গেল।');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('branch_id', $e->errors());
            }
        }

        foreach ([$a->id, (string) $a->id, 0, '0', null, ''] as $good) {
            $this->assertNotNull($run($good), 'নিজের শাখা বা "শাখা বাছা নেই" চলে না — দাবি অন্ধ।');
        }
    }
}
