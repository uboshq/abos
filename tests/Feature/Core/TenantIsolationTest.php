<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * অলঙ্ঘনীয় শর্ত ৪-এর প্রথম অংশ: এক কোম্পানির ব্যবহারকারী আরেক কোম্পানির
 * ডাটা কখনো দেখবে না।
 *
 * এটাই সবচেয়ে দামি টেস্ট। একটা মাত্র কোয়েরিতে স্কোপ বাদ পড়লে গ্রাহকের
 * ডাটা অন্য গ্রাহকের কাছে চলে যায়, আর সেটা কেউ টের পায় না যতক্ষণ না
 * ক্ষতি হয়ে যায়।
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Company::create(['code' => 'ALPHA', 'name_en' => 'Alpha Traders', 'name_bn' => 'আলফা ট্রেডার্স']);
        $this->beta = Company::create(['code' => 'BETA', 'name_en' => 'Beta Enterprise', 'name_bn' => 'বিটা এন্টারপ্রাইজ']);

        CompanyContext::forCompany($this->alpha->id, function () {
            Branch::create(['code' => 'A-MAIN', 'name_en' => 'Alpha Main', 'is_default' => true]);
            Branch::create(['code' => 'A-DEPOT', 'name_en' => 'Alpha Depot']);
        });

        CompanyContext::forCompany($this->beta->id, function () {
            Branch::create(['code' => 'B-MAIN', 'name_en' => 'Beta Main', 'is_default' => true]);
        });
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_query_only_sees_the_company_in_context(): void
    {
        CompanyContext::set($this->alpha->id);
        $this->assertSame(2, Branch::query()->count());
        $this->assertEqualsCanonicalizing(['A-MAIN', 'A-DEPOT'], Branch::query()->pluck('code')->all());

        CompanyContext::set($this->beta->id);
        $this->assertSame(1, Branch::query()->count());
        $this->assertSame(['B-MAIN'], Branch::query()->pluck('code')->all());
    }

    public function test_finding_another_companys_record_by_id_returns_nothing(): void
    {
        $alphaBranchId = CompanyContext::forCompany(
            $this->alpha->id,
            fn () => Branch::query()->where('code', 'A-MAIN')->value('id'),
        );

        CompanyContext::set($this->beta->id);

        // আসল আক্রমণটা এভাবেই হয়: URL-এ অন্য কোম্পানির id বসিয়ে দেওয়া।
        $this->assertNull(Branch::query()->find($alphaBranchId));
    }

    public function test_a_new_record_takes_the_company_from_context_without_being_told(): void
    {
        CompanyContext::set($this->beta->id);

        $branch = Branch::create(['code' => 'B-2', 'name_en' => 'Beta Second']);

        $this->assertSame($this->beta->id, $branch->company_id);
    }

    public function test_across_all_companies_is_the_only_way_past_the_scope(): void
    {
        CompanyContext::set($this->alpha->id);

        $this->assertSame(2, Branch::query()->count());
        $this->assertSame(3, Branch::acrossAllCompanies()->count());
    }

    public function test_switching_company_moves_the_branch_too(): void
    {
        $user = User::create([
            'name' => 'Rahim',
            'email' => 'rahim@example.test',
            'password' => 'secret-not-used',
        ]);

        $user->companies()->attach([$this->alpha->id, $this->beta->id]);

        $user->switchCompany($this->alpha->id);
        $alphaBranch = $user->fresh()->current_branch_id;

        $user->switchCompany($this->beta->id);
        $betaBranch = $user->fresh()->current_branch_id;

        $this->assertNotNull($alphaBranch);
        $this->assertNotNull($betaBranch);
        $this->assertNotSame($alphaBranch, $betaBranch);

        // শাখাটা সত্যিই নতুন কোম্পানির — পুরনোটা ধরে রাখলে পরের এন্ট্রি
        // ভুল কোম্পানির শাখায় বসত।
        $this->assertSame(
            $this->beta->id,
            Branch::acrossAllCompanies()->whereKey($betaBranch)->value('company_id'),
        );
    }

    public function test_a_user_cannot_switch_into_a_company_they_do_not_belong_to(): void
    {
        $user = User::create([
            'name' => 'Karim',
            'email' => 'karim@example.test',
            'password' => 'secret-not-used',
        ]);

        $user->companies()->attach($this->alpha->id);

        /*
         * ২ সেপ্টেম্বর ২০২৬-এ দাবিটা বদলেছে — শিথিল হয়নি।
         *
         * আগে এখানে `RuntimeException` উঠত, অর্থাৎ পর্দায় একটা ৫০০।
         * কিন্তু এটা ব্যবস্থার ভুল নয়: কারও ট্যাব খোলা ছিল, ইতিমধ্যে
         * তাঁকে ওই কোম্পানি থেকে সরানো হয়েছে, আর তিনি পুরনো তালিকা
         * থেকেই বেছেছেন। তিনি দেখতেন "কিছু ভেঙে গেছে", অথচ কথাটা ছিল
         * "আপনার আর ওখানে ঢোকার অধিকার নেই"।
         *
         * **দেয়ালটা একই জায়গায়** — কেবল অস্বীকারটা ব্যবহারকারীর ভাষায়।
         */
        $this->expectException(ValidationException::class);
        $user->switchCompany($this->beta->id);
    }

    public function test_the_company_choice_survives_being_reloaded(): void
    {
        $user = User::create([
            'name' => 'Sultana',
            'email' => 'sultana@example.test',
            'password' => 'secret-not-used',
        ]);

        $user->companies()->attach([$this->alpha->id, $this->beta->id]);
        $user->switchCompany($this->beta->id);

        // সেশন নয়, ডাটাবেজ — তাই নতুন করে পড়লেও পছন্দটা থাকে।
        // DMS-এ ঠিক এই জায়গাতেই সুইচ হারিয়ে যেত।
        $reloaded = User::query()->find($user->id);

        $this->assertSame($this->beta->id, $reloaded->current_company_id);
    }
}
