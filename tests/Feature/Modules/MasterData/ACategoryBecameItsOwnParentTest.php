<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\MoneyCategory;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * টাকার শ্রেণি নিজেই নিজের মা হত, আর গাছ তিন স্তরে নামত — মাস্টার লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ মায়ের ড্রপডাউনে সম্পাদনার সারিটাও থাকত, আর যার শাখা আছে তাকেও অন্যের শাখা বানানো যেত — তখন নিচের সারি পর্দার
 * কোনো তালিকায় উঠত না। ⓘ শাখাহীন শ্রেণিকে অন্যের শাখা বানানো আগের মতোই চলে।
 */
final class ACategoryBecameItsOwnParentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_category_is_not_its_own_parent_and_a_parent_does_not_become_a_child(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $make = fn (string $code, ?int $parent = null) => MoneyCategory::query()->create([
            'code' => $code, 'name_en' => $code, 'context' => MoneyCategory::BOTH, 'parent_id' => $parent, 'is_active' => true,
        ]);
        $a = $make('TREEA');
        $b = $make('TREEB');
        $make('TREEC', $a->id);

        $put = fn (MoneyCategory $row, int $parent) => $this->put('/master-data/money-categories/'.$row->id, [
            'code' => $row->code, 'name_en' => $row->name_en, 'context' => $row->context, 'parent_id' => $parent,
        ]);

        $put($b, $b->id)->assertSessionHasErrors('parent_id');
        $this->assertNull($b->fresh()->parent_id, 'শ্রেণিটা নিজের মা হয়ে গেল।');

        $put($a, $b->id)->assertSessionHasErrors('parent_id');
        $this->assertNull($a->fresh()->parent_id, 'শাখাওয়ালা শ্রেণি অন্যের শাখা হল — গাছ তিন স্তরে।');

        $put($b, $a->id)->assertSessionHasNoErrors();
        $this->assertSame((int) $a->id, (int) $b->fresh()->parent_id, 'শাখাহীন শ্রেণিও শাখা হতে পারল না — বেশি বন্ধ হয়েছে।');
    }
}
