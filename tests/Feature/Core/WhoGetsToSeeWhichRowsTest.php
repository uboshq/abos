<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Voucher;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কে কোন সারি দেখবেন — ভাগ চ (RLS)।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `BelongsToCompany` কোম্পানি আলাদা রাখে, আর কঠোরভাবেই রাখে। কিন্তু
 * কোম্পানির **ভেতরে** কোনো দেয়াল ছিল না: নেত্রকোনার প্রতিনিধি লগইন
 * করলে ঢাকার প্রতিটা বিল, প্রতিটা আদায়, প্রতিটা বকেয়া দেখতে পেতেন।
 *
 * ── এই ফাইলের সবচেয়ে জরুরি পরীক্ষা ──────────────────────────────────
 * প্রথমটা: সীমা না বসালে সব দেখা যায়।
 *
 * উল্টো ধরলে (সারি না থাকা মানে কিছুই দেখা যায় না) এই ফিচারটা চালুর
 * মুহূর্তে প্রতিটা ব্যবহারকারী অন্ধ হয়ে যেতেন — মালিকসহ, আর তাঁকে
 * ঢুকিয়ে ঠিক করারও উপায় থাকত না, কারণ তিনিও কিছু দেখতেন না।
 */
class WhoGetsToSeeWhichRowsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $dhaka;

    private Branch $netrokona;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->dhaka = $this->company->defaultBranch();
        $this->netrokona = Branch::query()->create([
            'company_id' => $this->company->id,
            'code' => 'RLS-B',
            'name_en' => 'Second branch',
            'name_bn' => 'নেত্রকোনা',
            'is_active' => true,
        ]);
    }

    /** দুইটা শাখায় একটা করে ভাউচার। */
    private function seedVouchers(): void
    {
        foreach ([$this->dhaka, $this->netrokona] as $branch) {
            Voucher::query()->create([
                'company_id' => $this->company->id,
                'branch_id' => $branch->id,
                'financial_year_id' => FinancialYear::query()->value('id'),
                'type' => Voucher::JOURNAL,
                'document_no' => 'JV-'.$branch->code,
                'trx_date' => '2026-08-10',
                'amount' => '1000',
                'status' => DocumentStatus::DRAFT,
            ]);
        }
    }

    private function limitTo(User $user, Branch $branch): void
    {
        UserDataScope::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'scope_type' => UserDataScope::BRANCH,
            'scope_id' => $branch->id,
        ]);

        app(DataScope::class)->forget();
    }

    /**
     * সীমা না বসালে সব দেখা যায়।
     *
     * এটাই পুরো নকশার ভিত্তি। ভুল দিকে ধরলে ফিচারটা চালুর দিনেই সবাই
     * অন্ধ হয়ে যেতেন, আর কেউ ঢুকে ঠিকও করতে পারতেন না।
     */
    public function test_a_person_with_no_limit_set_sees_everything(): void
    {
        $this->seedVouchers();
        $this->actingAs($this->owner);

        $this->assertSame(2, Voucher::query()->whereIn('document_no', ['JV-'.$this->dhaka->code, 'JV-RLS-B'])->count());
        $this->assertFalse(app(DataScope::class)->isLimited($this->owner, UserDataScope::BRANCH));
    }

    public function test_a_limited_person_sees_only_their_branch(): void
    {
        $this->seedVouchers();
        $this->limitTo($this->owner, $this->netrokona);
        $this->actingAs($this->owner);

        $seen = Voucher::query()->pluck('document_no')->all();

        $this->assertContains('JV-RLS-B', $seen);
        $this->assertNotContains('JV-'.$this->dhaka->code, $seen,
            'সীমাবদ্ধ ব্যবহারকারী অন্য শাখার ভাউচার দেখতে পাচ্ছেন।');
    }

    /**
     * শাখাহীন সারি সবাই দেখেন।
     *
     * প্রধান অফিসের জাবেদা, কোম্পানি-স্তরের কাগজ — ওগুলোর কোনো শাখা
     * নেই। আটকালে সীমাবদ্ধ ব্যবহারকারী নিজের কাজের অর্ধেকই দেখতেন না,
     * আর কারণটা কেউ ধরতে পারত না।
     */
    public function test_a_row_with_no_branch_is_visible_to_everyone(): void
    {
        Voucher::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => null,
            'financial_year_id' => FinancialYear::query()->value('id'),
            'type' => Voucher::JOURNAL,
            'document_no' => 'JV-HEADOFFICE',
            'trx_date' => '2026-08-10',
            'amount' => '1000',
            'status' => DocumentStatus::DRAFT,
        ]);

        $this->limitTo($this->owner, $this->netrokona);
        $this->actingAs($this->owner);

        $this->assertContains('JV-HEADOFFICE', Voucher::query()->pluck('document_no')->all());
    }

    /**
     * কনসোলে কোনো ছাঁকনি নেই।
     *
     * ব্যাকআপ, মাস শেষের দৌড়, নির্ধারিত কাজ — ওখানে কারো "দেখার
     * অনুমতি" বলে কিছু নেই। ছাঁকনি বসালে ওগুলো অর্ধেক সারি নিয়ে চলত,
     * আর কেউ টের পেত না।
     */
    public function test_nothing_is_hidden_when_no_one_is_logged_in(): void
    {
        $this->seedVouchers();
        $this->limitTo($this->owner, $this->netrokona);

        auth()->logout();

        $this->assertSame(2, Voucher::query()
            ->whereIn('document_no', ['JV-'.$this->dhaka->code, 'JV-RLS-B'])->count());
    }

    /** সচেতনভাবে সীমা পেরোনো যায় — রিপোর্টের জন্য। */
    public function test_a_report_can_deliberately_look_across_branches(): void
    {
        $this->seedVouchers();
        $this->limitTo($this->owner, $this->netrokona);
        $this->actingAs($this->owner);

        $this->assertSame(2, Voucher::acrossBranches()
            ->whereIn('document_no', ['JV-'.$this->dhaka->code, 'JV-RLS-B'])->count());
    }

    /** একজনকে দুইটা শাখা দেওয়া যায়, আর দুইটাই দেখা যায়। */
    public function test_two_branches_can_be_given_to_one_person(): void
    {
        $this->seedVouchers();
        $this->limitTo($this->owner, $this->netrokona);
        $this->limitTo($this->owner, $this->dhaka);
        $this->actingAs($this->owner);

        $this->assertSame(2, Voucher::query()
            ->whereIn('document_no', ['JV-'.$this->dhaka->code, 'JV-RLS-B'])->count());
    }

    /** `allows()` একই উত্তর দেয়, সারি না ছুঁয়েই। */
    public function test_allows_answers_without_touching_the_rows(): void
    {
        $scope = app(DataScope::class);

        $this->actingAs($this->owner);
        $this->assertTrue($scope->allows($this->owner, UserDataScope::BRANCH, $this->dhaka->id));

        $this->limitTo($this->owner, $this->netrokona);

        $this->assertTrue($scope->allows($this->owner, UserDataScope::BRANCH, $this->netrokona->id));
        $this->assertFalse($scope->allows($this->owner, UserDataScope::BRANCH, $this->dhaka->id));

        // শাখাহীন সবসময় নাগালে
        $this->assertTrue($scope->allows($this->owner, UserDataScope::BRANCH, null));
    }

    /** একই মানুষকে একই শাখা দুইবার দেওয়া যায় না। */
    public function test_the_same_branch_cannot_be_given_twice(): void
    {
        $this->limitTo($this->owner, $this->netrokona);

        $this->expectException(QueryException::class);

        UserDataScope::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'scope_type' => UserDataScope::BRANCH,
            'scope_id' => $this->netrokona->id,
        ]);
    }
}
