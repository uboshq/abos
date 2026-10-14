<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ব্যবহারে থাকা বেতনের খাতের আকার বদলানো যেত, আর মূল খাত থেকে "মূল" তোলা যেত (ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬;
 * [[SalaryHeadService::update()]])।
 *
 * ⭐ দাবিগুলো:
 *   · কাঠামোয় থাকা নির্দিষ্ট ভাতা "মূলের শতাংশ" হয় না — নইলে ১,৫০০ হত ১,৫০০%
 *   · মূল খাতের "মূল" তোলা যায় না — নইলে শতাংশের প্রতিটা ভাতা শূন্য
 *   · নাম বদলানো চলে, আর কেউ না-ব্যবহার করা খাতের আকারও বদলায়
 */
final class ASalaryHeadInUseChangedItsShapeTest extends TestCase
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
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();
    }

    public function test_a_head_in_a_structure_keeps_its_shape_and_the_basic_stays_basic(): void
    {
        $medical = SalaryHead::query()->where('code', 'MEDICAL')->firstOrFail();
        $employee = app(EmployeeService::class)->create(['code' => 'EMP-S', 'name_en' => 'Shape', 'joining_date' => '2026-01-01', 'payment_method' => 'cash']);
        app(SalaryStructureService::class)->set($employee, $medical, '2026-01-01', '1500');

        try {
            app(SalaryHeadService::class)->update($medical, ['calculation' => SalaryHead::PERCENT_OF_BASIC]);
            $this->fail('⛔ কাঠামোয় থাকা ১,৫০০ ভাতা মূলের শতাংশ হলো — পরের বেতনশিটে ১,৫০০%');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('calculation', $e->errors());
        }
        $this->assertSame(SalaryHead::FIXED, $medical->fresh()->calculation);

        $basic = SalaryHead::query()->where('is_basic', true)->firstOrFail();

        try {
            app(SalaryHeadService::class)->update($basic, ['is_basic' => false]);
            $this->fail('⛔ মূল খাত থেকে "মূল" তোলা গেল — শতাংশের প্রতিটা ভাতা শূন্য হত');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('is_basic', $e->errors());
        }
        $this->assertTrue((bool) $basic->fresh()->is_basic);

        // ⓘ নাম বদলায়; কেউ না-ব্যবহার করা খাতের আকারও
        app(SalaryHeadService::class)->update($medical, ['name_en' => 'Medical']);
        $this->assertSame('Medical', $medical->fresh()->name_en);

        $convey = SalaryHead::query()->where('code', 'CONVEY')->firstOrFail();
        app(SalaryHeadService::class)->update($convey, ['calculation' => SalaryHead::PERCENT_OF_BASIC]);
        $this->assertSame(SalaryHead::PERCENT_OF_BASIC, $convey->fresh()->calculation);
    }
}
