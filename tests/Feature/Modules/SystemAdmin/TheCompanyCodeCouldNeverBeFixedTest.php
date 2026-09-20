<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\NumberSeries;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কোম্পানির কোড বদলানো যায় — প্রথম কাগজ বেরোনোর আগ পর্যন্ত।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * মালিক একটা খালি কোম্পানির নাম বদলে দেখলেন কোডটা আগের প্রতিষ্ঠানেরই রয়ে
 * গেছে: *"code poriborton hoyna keno?"* ⓘ নিয়মটা ছিল "কখনো নয়", আর কারণটা
 * ন্যায্য — ছাপা কাগজে, রপ্তানি ফাইলে আর ব্যাংকের বিবরণীতে কোডটা বসে যায়।
 *
 * ⭐ কিন্তু ঐ কারণটা **কেবল তখনই সত্যি যখন কাগজ বেরিয়েছে**। তাই নিয়মটা
 * এখন শর্তসাপেক্ষ: একটা নম্বরও ইস্যু হয়নি আর খাতায় একটা সারিও নেই —
 * ততক্ষণ বদলানো যায়।
 */
final class TheCompanyCodeCouldNeverBeFixedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->admin = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_an_untouched_company_can_still_have_its_code_fixed(): void
    {
        $company = $this->aFreshCompany();

        $this->assertTrue($company->canChangeCode(), 'খালি কোম্পানির কোডও তালাবদ্ধ।');

        $this->put(route('system_admin.company.update', $company->id), [
            'code' => 'REAL',
            'name_en' => 'Real Business Limited',
        ])->assertRedirect();

        $this->assertSame('REAL', $company->fresh()->code, 'কোডটা বদলায়নি।');
    }

    /** ⛔ একটা নম্বর ইস্যু হলেই তালা — ঐ নম্বর কারো হাতে চলে গেছে। */
    public function test_once_a_number_is_issued_the_code_locks(): void
    {
        $company = $this->aFreshCompany();

        NumberSeries::query()->create([
            'company_id' => $company->id,
            'module' => 'sales',
            'doc_type' => 'INV',
            'prefix' => 'INV',
            'padding' => 4,
            'next_number' => 2,
        ]);

        $this->assertFalse($company->fresh()->canChangeCode(), 'নম্বর ইস্যুর পরেও কোড খোলা।');

        /*
         * ⚠️ পরীক্ষাটা প্রথমে ধরে নিয়েছিল অনুরোধটা চুপচাপ কোডটুকু ফেলে দেবে
         * আর নামটা বসিয়ে দেবে। ⓘ কিন্তু ডেমোর মালিক সুপার অ্যাডমিন, আর
         * তাঁর জন্য দরজাটা খোলা — কেবল পুরনো কোড লিখে নিশ্চিত করতে হয়।
         * ⛔ না লিখলে গোটা অনুরোধই থামে, নামসহ; আর সেটাই ঠিক, কারণ অর্ধেক
         * বদল সংরক্ষণ করা মানে ব্যবহারকারীকে মিথ্যা বলা।
         */
        $this->put(route('system_admin.company.update', $company->id), [
            'code' => 'NOPE',
            'name_en' => 'Renamed Anyway',
        ])->assertSessionHasErrors('code_confirm');

        $this->assertSame($company->code, $company->fresh()->code, 'তালা সত্ত্বেও কোড বদলে গেছে।');
    }

    /** খাতায় একটা সারি থাকলেও তালা — কাগজ না ছাপলেও হিসাব বেরিয়ে গেছে। */
    public function test_a_ledger_row_locks_it_too(): void
    {
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $this->assertTrue(LedgerEntry::query()->where('company_id', $company->id)->exists(),
            'ডেমোর খাতায় কোনো সারি নেই — মাপটা তখন অর্থহীন।');

        $this->assertFalse($company->canChangeCode(), 'খাতায় সারি থাকা সত্ত্বেও কোড খোলা।');
    }

    /**
     * ⭐ কাগজ বেরিয়ে যাওয়ার পরেও সুপার অ্যাডমিন বদলাতে পারেন — পুরনো কোডটা
     * হুবহু লিখে। ⓘ মালিকের আসল কোম্পানিতে পড়ে ছিল কেবল পরীক্ষার দুইটা সারি,
     * আর তাতেই আসল ব্যবসার নামের সাথে ভুল কোড চিরকাল বসে থাকত।
     */
    public function test_a_super_admin_can_change_it_by_typing_the_old_code(): void
    {
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $this->assertFalse($company->canChangeCode(), 'এই কোম্পানিতে তো কাজ হয়েছে — কোড খোলা থাকার কথা নয়।');

        /* ⛔ পুরনো কোড না লিখলে কিছুই বদলায় না */
        $this->put(route('system_admin.company.update', $company->id), [
            'code' => 'NEWCODE',
            'name_en' => $company->name_en,
        ])->assertSessionHasErrors('code_confirm');

        $this->assertSame('TDEPOT', $company->fresh()->code, 'নিশ্চিত না করেই কোড বদলে গেছে।');

        $this->put(route('system_admin.company.update', $company->id), [
            'code' => 'NEWCODE',
            'code_confirm' => 'TDEPOT',
            'name_en' => $company->name_en,
        ])->assertRedirect();

        $this->assertSame('NEWCODE', $company->fresh()->code, 'সুপার অ্যাডমিনও বদলাতে পারলেন না।');
    }

    private function aFreshCompany(): Company
    {
        return Company::query()->create([
            'code' => 'TMP',
            'name_en' => 'Temporary Name',
            'is_active' => true,
        ]);
    }
}
