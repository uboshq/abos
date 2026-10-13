<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Services\InstitutionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * বীমাকারীর ঘরে ব্যাংকের নাম লিখলে ব্যাংকটাই বীমাকারী হত (Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬, বীমা ⓘ৮;
 * [[InstitutionService::resolve()]])।
 *
 * ⭐ দাবিগুলো:
 *   · লেখা নামটা অন্য ধরনের প্রতিষ্ঠানের হলে থামে — বীমার ঘরে ব্যাংক নয়।
 *   · ব্যাংক আর NBFI ঋণদাতা হিসেবে একে অপরের জায়গায় চলে — আগের মতোই।
 */
final class ABankTypedAsTheInsurerBecameTheInsurerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_a_banks_name_typed_as_the_insurer_is_refused(): void
    {
        $bank = app(InstitutionService::class)->create(['kind' => Institution::BANK, 'name_en' => 'Pubali Test Bank']);

        $data = ['institution_new' => 'Pubali Test Bank'];

        try {
            app(InstitutionService::class)->resolve($data, Institution::INSURANCE);
            $this->fail('⛔ বীমার ঘরে ব্যাংকের নাম — ব্যাংকটাই পলিসির বীমাকারী হয়ে গেল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('institution_new', $e->errors());
        }

        $data = ['institution_new' => 'Pubali Test Bank'];
        $this->assertSame((int) $bank->id, app(InstitutionService::class)->resolve($data, Institution::NBFI), 'ব্যাংক ঋণদাতার ঘরে আগের মতোই চলে');

        $data = ['institution_new' => 'Pubali Test Bank'];
        $this->assertSame((int) $bank->id, app(InstitutionService::class)->resolve($data, Institution::BANK));
    }
}
