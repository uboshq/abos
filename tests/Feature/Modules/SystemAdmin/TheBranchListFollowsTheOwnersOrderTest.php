<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\ShellFacts;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\SystemAdmin\Services\BranchDesk;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * শাখার তালিকা মালিকের ক্রমে — মালিক, ৫ অক্টোবর ২০২৬: আদি কর্পোরেশনে "সুপার, লায়ন, গোল্ড, জাবেদ, হোলসেল"।
 *
 * দাবি:
 *  · ক্রম দেওয়া শাখা আগে, ছোট সংখ্যা আগে — নামের বর্ণক্রম যা-ই বলুক।
 *  · ক্রম না দেওয়া (০) শাখা শেষে, নামের বর্ণক্রমে।
 *  · শাখার ফর্ম থেকে ক্রম বসে, খালি দিলে ০।
 */
final class TheBranchListFollowsTheOwnersOrderTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_switcher_lists_branches_in_the_owners_order_and_unordered_ones_last(): void
    {
        $desk = app(BranchDesk::class);
        Branch::query()->update(['sort_order' => 0]);

        $zeta = $desk->open($this->company, ['code' => 'ZSUPER', 'name_en' => 'Zeta Super', 'sort_order' => 1]);
        $alpha = $desk->open($this->company, ['code' => 'ALION', 'name_en' => 'Alpha Lion', 'sort_order' => 2]);
        $plain = $desk->open($this->company, ['code' => 'AAPLAIN', 'name_en' => 'AA Plain', 'sort_order' => null]);

        $this->assertSame(0, (int) $plain->fresh()->sort_order, 'খালি ক্রম ০ হয়নি।');

        $codes = app(ShellFacts::class)->branches()->pluck('code')->all();

        $this->assertSame(['ZSUPER', 'ALION'], array_slice($codes, 0, 2), '⛔ ক্রম দেওয়া শাখা আগে আসেনি।');
        $unordered = array_slice($codes, 2);
        $sorted = $unordered;
        $names = Branch::query()->whereIn('code', $unordered)->pluck('name_en', 'code')->all();
        usort($sorted, fn ($a, $b) => strcmp($names[$a], $names[$b]));
        $this->assertSame($sorted, $unordered, '⛔ ক্রম না দেওয়া শাখা নামের বর্ণক্রমে নয়।');
        $this->assertContains('AAPLAIN', $unordered);

        // ⭐ ফর্ম দিয়ে বদলালেও খাটে
        $desk->update($plain, ['code' => 'AAPLAIN', 'name_en' => 'AA Plain', 'sort_order' => 3]);
        $fresh = new ShellFacts;
        $this->assertSame(['ZSUPER', 'ALION', 'AAPLAIN'], array_slice($fresh->branches()->pluck('code')->all(), 0, 3));
    }

    /**
     * ⭐ মুছে ফেলা শাখার কোড আবার নেওয়া যায় — মালিক, ৬ অক্টোবর ২০২৬ (হোলসেলকে "AVA" করতে গিয়ে ৫০০)।
     * ⛔ অনন্য সূচি মুছে ফেলা সারিও ধরত, পাহারা দেখত না; চালু শাখার কোড আগের মতোই আটকায়, বার্তাসহ।
     */
    public function test_a_deleted_branchs_code_can_be_used_again_but_a_live_ones_cannot(): void
    {
        $desk = app(BranchDesk::class);

        $old = $desk->open($this->company, ['code' => 'AVA', 'name_en' => 'Old Ava']);
        $old->delete();
        $shop = $desk->open($this->company, ['code' => 'WHOLE', 'name_en' => 'Wholesale']);

        $desk->update($shop, ['code' => 'ava', 'name_en' => 'AVA TRADE']);
        $this->assertSame('AVA', $shop->fresh()->code, '⛔ মুছে ফেলা শাখার কোড নেওয়া গেল না।');
        $this->assertNotSame('AVA', Branch::onlyTrashed()->findOrFail($old->id)->code, '⛔ মুছে ফেলা শাখা কোড ছাড়েনি।');

        $live = $desk->open($this->company, ['code' => 'LIVEX', 'name_en' => 'Live X']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $desk->update($live, ['code' => 'AVA', 'name_en' => 'Live X']);
    }
}
