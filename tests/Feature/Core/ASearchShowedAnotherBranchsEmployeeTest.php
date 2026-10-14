<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Search\SearchEngine;
use App\Core\Engines\Search\SearchHit;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Documents\Services\DocumentLinks;
use App\Modules\Hr\Models\Employee;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ শাখায় সীমিত HR কর্মী খোঁজায় আর কাগজ-জোড়ায় অন্য শাখার কর্মী পান না — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬
 * ([[SearchEngine::describe()]], [[DocumentLinks]])।
 *
 * ⓘ কর্মীর রুটে সাধারণ চাবি (`can:hr.employee.view`), শাখার দেয়াল কেবল পলিসিতে ([[EmployeePolicy]])। খোঁজা সারি যাচাই করত কেবল
 * পলিসি-রূপের রুটে, তাই অন্য শাখার কর্মীর নাম, কোড আর মোবাইল Ctrl+K-এ আসত — চাপলে ৪০৩; কাগজ-জোড়ার তালিকাতেও আসত, জোড়াও যেত।
 */
final class ASearchShowedAnotherBranchsEmployeeTest extends TestCase
{
    use RefreshDatabase;

    private User $clerk;

    private Employee $near;

    private Employee $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $mine = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $theirs = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();

        $this->near = Employee::query()->create(['code' => 'EMP-NEAR', 'name_en' => 'Zebulon Near', 'branch_id' => $mine->id,
            'mobile' => '01999000222', 'joining_date' => now()->subYear()->toDateString()]);
        $this->far = Employee::query()->create(['code' => 'EMP-FAR', 'name_en' => 'Zebulon Far', 'branch_id' => $theirs->id,
            'mobile' => '01999000111', 'joining_date' => now()->subYear()->toDateString()]);

        $this->clerk = User::factory()->create(['current_company_id' => $company->id]);
        $this->clerk->companies()->attach($company->id, ['is_active' => true]);
        $this->clerk->givePermissionTo(['hr.employee.view', 'documents.view']);
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $this->clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mine->id]);
        app(DataScope::class)->forget();
    }

    public function test_the_search_does_not_show_another_branchs_employee(): void
    {
        // ⓘ নিয়ন্ত্রণ — পাতা নিজেই বন্ধ, আর নিজের শাখার কর্মী খোঁজায় আসে
        $this->assertFalse($this->clerk->can('view', $this->far));
        $this->assertContains('EMP-NEAR', $this->found('Zebulon'), 'নিজের শাখার কর্মীটাই খোঁজায় এল না — নিচের দাবি তাহলে ফাঁকা।');

        $this->assertNotContains('EMP-FAR', $this->found('Zebulon'), '⛔ খোঁজা অন্য শাখার কর্মীর নাম দেখাল।');
        $this->assertNotContains('EMP-FAR', $this->found('01999000111'), '⛔ মোবাইল নম্বর দিয়ে অন্য শাখার কর্মী পাওয়া গেল।');
    }

    public function test_a_document_cannot_be_linked_to_another_branchs_employee(): void
    {
        $links = app(DocumentLinks::class);
        $offered = array_column($links->candidates('employee', 'Zebulon', $this->clerk), 'no');

        $this->assertContains('EMP-NEAR', $offered, 'নিজের শাখার কর্মী জোড়ার তালিকায় নেই।');
        $this->assertNotContains('EMP-FAR', $offered, '⛔ জোড়ার তালিকায় অন্য শাখার কর্মী।');

        // ⓘ জোড়া বসানো আর পাতায় নাম দেখানো — দুটোই এই একই প্রশ্ন জিজ্ঞেস করে
        $this->assertFalse(app(SearchEngine::class)->maySee($this->clerk, $this->far), '⛔ অন্য শাখার কর্মীতে কাগজ জোড়া যায়।');
        $this->assertTrue(app(SearchEngine::class)->maySee($this->clerk, $this->near));
    }

    /** @return list<string> */
    private function found(string $term): array
    {
        return array_map(
            fn (SearchHit $hit) => $hit->documentNo,
            array_values(array_filter(
                app(SearchEngine::class)->search($term, $this->clerk),
                fn (SearchHit $hit) => $hit->slug === 'employee',
            )),
        );
    }
}
