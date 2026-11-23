<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\SalaryHeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ বেতনের খাত নিজে বলে সে ধারা ২(৪৫)-এর মজুরি কি না (fe-র উত্তর ১, ২১ অক্টোবর ২০২৬; [[EarnedLeave::monthlyWages()]]; a6)।
 *
 * ── দাবি ───────────────────────────────────────────────────────────────
 *  · প্রমিত খাতে যাতায়াত ভাতা (CONVEY) মজুরির বাইরে, মূল আর বাড়িভাড়া ভেতরে।
 *  · খাতের পর্দায় টিক দিলে বাইরে, টিক তুললে আবার ভেতরে।
 */
final class ASalaryHeadSaysWhetherItIsWagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_standard_travel_allowance_is_outside_and_the_box_turns_it_on_and_off(): void
    {
        $company = Company::create(['code' => 'HRSW', 'name_en' => 'Wages Co']);
        CompanyContext::set($company->id);
        app(SalaryHeadService::class)->installDefaults();

        $outside = SalaryHead::query()->pluck('outside_wages', 'code');
        $this->assertTrue((bool) $outside['CONVEY'], '⛔ প্রমিত যাতায়াত ভাতা মজুরির ভেতরে');
        $this->assertFalse((bool) $outside['BASIC']);
        $this->assertFalse((bool) $outside['HRA']);

        Permission::findOrCreate('hr.salary.manage', 'web');
        $user = User::factory()->create(['current_company_id' => $company->id]);
        $user->companies()->attach($company->id);
        $user->givePermissionTo('hr.salary.manage');

        $medical = SalaryHead::query()->where('code', 'MEDICAL')->firstOrFail();
        $form = ['code' => 'MEDICAL', 'name_en' => 'Medical Allowance', 'kind' => SalaryHead::EARNING, 'calculation' => SalaryHead::FIXED];

        $this->actingAs($user)->put(route('hr.salary_head.update', $medical->id), $form + ['outside_wages' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($medical->fresh()->outside_wages, '⛔ টিক দিয়েও মজুরির বাইরে হল না');

        $this->actingAs($user)->put(route('hr.salary_head.update', $medical->id), $form)->assertSessionHasNoErrors();
        $this->assertFalse($medical->fresh()->outside_wages, '⛔ টিক তুলেও মজুরির বাইরে রয়ে গেল');
    }
}
