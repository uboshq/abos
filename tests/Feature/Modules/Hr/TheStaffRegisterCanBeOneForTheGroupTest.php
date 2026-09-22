<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Setting;
use App\Modules\Hr\Models\Employee;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * এক গ্রুপ, এক কর্মী-খাতা — যদি মালিক চান।
 *
 * ── ⭐ মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * *"HR প্রতিটা কোম্পানিতে আলাদা বসে — eta chailew keu korte parbe
 * tajonno control panel e bebosta rako"*।
 *
 * ⓘ একই মালিকের তিনটা কোম্পানি, আর কর্মীরা সবগুলোর জন্যই কাজ করেন।
 *
 * ── ⚠️ এই ফাইলের সবচেয়ে দামি দাবিটা "চালু" নয়, "বন্ধ" ──────────────
 * ⛔ দেয়াল চওড়া করার সুইচে ভুল হলে সেটা ত্রুটি নয়, **নীরব ফাঁস** —
 * এক প্রতিষ্ঠানের বেতন আরেক প্রতিষ্ঠানের পর্দায়, আর কোথাও কিছু লাল
 * হয় না। ⓘ তাই প্রথম দাবিটাই: সুইচ বন্ধ থাকলে কিছুই বদলায় না।
 */
final class TheStaffRegisterCanBeOneForTheGroupTest extends TestCase
{
    use RefreshDatabase;

    private Company $here;

    private Company $there;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->here = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->there = Company::query()->where('code', '!=', 'TDEPOT')->firstOrFail();

        CompanyContext::set($this->here->id, $this->here->defaultBranch()?->id);
    }

    /**
     * ⛔ সুইচ বন্ধ — দেয়ালটা আগের মতোই দাঁড়িয়ে।
     *
     * ⚠️ এটাই আসল পাহারা। ⓘ ফিচারটা কাজ না করলে কেউ অভিযোগ করবেন;
     * দেয়ালটা ভাঙলে **কেউ কোনোদিন জানবেন না**।
     */
    public function test_with_the_switch_off_the_wall_stands(): void
    {
        $theirs = $this->anEmployeeIn($this->there);

        $this->assertNull(Employee::query()->find($theirs->id), implode("\n", [
            '⛔ সুইচ বন্ধ, তবু অন্য কোম্পানির কর্মী দেখা যাচ্ছে।',
            '',
            '⚠️ এটা ফিচারের ত্রুটি নয়, টেন্যান্টের দেয়াল ভাঙা — আর ওটা',
            'এই ব্যবস্থার অলঙ্ঘনীয় শর্ত।',
        ]));
    }

    /** ⭐ আর নিজের কোম্পানির কর্মী তখনো দেখা যায় — নাহলে উপরের দাবিটা ফাঁকা। */
    public function test_with_the_switch_off_your_own_staff_are_still_there(): void
    {
        $mine = $this->anEmployeeIn($this->here);

        $this->assertNotNull(Employee::query()->find($mine->id),
            '⛔ নিজের কোম্পানির কর্মীও দেখা যাচ্ছে না — তাহলে উপরের দাবিটা কিছুই প্রমাণ করে না।');
    }

    /** ⭐ সুইচ চালু — গোটা গ্রুপের কর্মী এক খাতায়। */
    public function test_with_the_switch_on_the_group_shares_one_register(): void
    {
        $theirs = $this->anEmployeeIn($this->there);

        $this->openTheWall();

        $this->assertNotNull(Employee::query()->find($theirs->id),
            '⛔ সুইচ চালু, তবু অন্য কোম্পানির কর্মী দেখা যাচ্ছে না — সুইচটা একটা মৃত বোতাম।');
    }

    /**
     * ⭐ সুইচটা ফেরানো যায় — বন্ধ করলেই দেয়াল ফিরে আসে।
     *
     * ⛔ ফেরানো যায় না এমন সুইচ আসলে সুইচ নয়, একটা একমুখী দরজা।
     */
    public function test_closing_it_again_puts_the_wall_back(): void
    {
        $theirs = $this->anEmployeeIn($this->there);

        $this->openTheWall();
        $this->assertNotNull(Employee::query()->find($theirs->id), 'ⓘ খোলার ধাপটাই কাজ করেনি।');

        app(SettingsService::class)->set('hr.shared_across_companies', false);

        $this->assertNull(Employee::query()->find($theirs->id),
            '⛔ সুইচ বন্ধ করার পরেও দেয়ালটা ফেরেনি।');
    }

    /**
     * ⭐ লেখা চওড়া হয় না — নতুন কর্মী চলতি কোম্পানিরই থাকেন।
     *
     * ⛔ লেখাও চওড়া হলে সুইচ বন্ধ করার দিন ঐ সারিগুলোর কোনো ঘর থাকত না,
     * আর তাঁরা নীরবে অদৃশ্য হয়ে যেতেন।
     */
    public function test_a_new_person_still_belongs_to_the_company_that_hired_them(): void
    {
        $this->openTheWall();

        $made = $this->anEmployeeIn($this->here);

        $this->assertSame($this->here->id, (int) $made->company_id,
            '⛔ সুইচ চালু থাকায় নতুন কর্মীর কোম্পানিও ঘোলা হয়ে গেছে।');
    }

    /**
     * ⛔ সুইচটা গোটা ব্যবস্থার — একটা কোম্পানি একা খুলতে পারে না।
     *
     * ── ⚠️ কেন এটা সবচেয়ে সূক্ষ্ম বিপদ ─────────────────────────────
     * সেটিং সাধারণত কোম্পানি ধরে বসে। ⓘ ওভাবে বসলে TCL সুইচটা চালু
     * করে **DEM-এর কর্মী দেখত, আর DEM দেখত না TCL-এর** — অথচ DEM
     * কখনো রাজি হয়নি।
     *
     * ⭐ এক পাশ থেকে খোলা দেয়াল সুবিধা নয়, ফাঁস।
     */
    public function test_one_company_cannot_open_the_wall_only_for_itself(): void
    {
        $this->openTheWall();

        CompanyContext::set($this->there->id, $this->there->defaultBranch()?->id);

        $this->assertTrue(app(SettingsService::class)->get('hr.shared_across_companies'),
            '⛔ সুইচটা কোম্পানি ধরে বসেছে — এক পাশ থেকে খোলা দেয়াল।');

        $rows = Setting::query()->where('key', 'hr.shared_across_companies')->get();

        $this->assertCount(1, $rows, '⛔ সুইচটার একাধিক সারি — কোম্পানি ধরে লেখা হচ্ছে।');
        $this->assertNull($rows->first()->company_id,
            '⛔ সারিটা একটা কোম্পানির নামে বসেছে, গোটা ব্যবস্থার নামে নয়।');
    }

    /**
     * ⭐ আর HR-এর **প্রতিটা** কোম্পানি-ছাঁকা মডেল সুইচটা মানে।
     *
     * ── ⛔ কেন এটা ছাড়া ফিচারটা আধখানা ─────────────────────────────
     * নয়টা মডেল কোম্পানি ধরে ছাঁকা। ⚠️ কয়েকটায় বসিয়ে বাকিগুলো ভুলে
     * গেলে কর্মীর তালিকা চওড়া হত আর তাঁর বেতনশিট হত না — পর্দায় নাম,
     * কিন্তু খুললে কিছু নেই। ⓘ আর ভুলটা ধরা পড়ত কেবল বেতনের দিনে।
     *
     * ⭐ পাহারাটা নাম ধরে নয়, **নিয়ম ধরে**: যে মডেলই কোম্পানি ধরে
     * ছাঁকে, তাকেই সুইচটা মানতে হবে। ⓘ তাই কাল নতুন একটা HR মডেল
     * লেখা হলে সেদিনই ধরা পড়বে।
     */
    public function test_every_company_scoped_hr_model_obeys_the_switch(): void
    {
        $missing = [];
        $checked = 0;

        foreach (glob(__DIR__.'/../../../../app/Modules/Hr/Models/*.php') ?: [] as $file) {
            $class = 'App\\Modules\\Hr\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $traits = class_uses_recursive($class);

            if (! in_array(BelongsToCompany::class, $traits, true)) {
                continue;
            }

            $checked++;

            if (! in_array(SharedAcrossCompaniesWhenAsked::class, $traits, true)) {
                $missing[] = (new ReflectionClass($class))->getShortName();
            }
        }

        $this->assertGreaterThan(4, $checked,
            'HR-এর কোম্পানি-ছাঁকা মডেল প্রায় কিছুই পাওয়া গেল না — পাহারাটা শূন্য জায়গা দেখছে।');

        $this->assertSame([], $missing, implode("\n", [
            '⛔ এই HR মডেলগুলো সুইচটা মানে না: '.implode(', ', $missing),
            '',
            '⚠️ ফলে কর্মীর তালিকা চওড়া হবে আর তাঁর কাগজ হবে না — পর্দায়',
            'নাম, কিন্তু খুললে কিছু নেই। ⓘ ধরা পড়বে বেতনের দিনে।',
        ]));
    }

    private function openTheWall(): void
    {
        app(SettingsService::class)->set('hr.shared_across_companies', true);
    }

    /**
     * ⓘ ফ্যাক্টরি নয়, হাতে — [[Employee]]-এর কোনো ফ্যাক্টরি নেই, আর
     * এই কাজের জন্য একটা বানানো মানে সিডারের সাথে দ্বিতীয় একটা সত্য
     * তৈরি করা।
     *
     * ⚠️ `branch_id` লাগে, আর সেটা **ঐ কোম্পানিরই** শাখা হতে হবে —
     * অন্য কোম্পানির শাখা বসালে সারিটা নিজেই অসংলগ্ন হত, আর দাবিটা
     * ভুল কারণে সবুজ বা লাল হতে পারত।
     */
    private function anEmployeeIn(Company $company): Employee
    {
        return CompanyContext::forCompany($company->id, fn () => Employee::create([
            'branch_id' => $company->defaultBranch()?->id,
            'code' => 'EMP-'.$company->code.'-'.Str::random(6),
            'name_en' => 'Test Person '.$company->code,
            'name_bn' => 'পরীক্ষার কর্মী '.$company->code,
            'joining_date' => now()->subYear()->toDateString(),
            'payment_method' => 'cash',
            'is_active' => true,
        ]));
    }
}
