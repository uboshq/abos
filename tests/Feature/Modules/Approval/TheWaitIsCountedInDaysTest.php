<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Dashboard\ApprovalDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * অনুমোদনের ড্যাশবোর্ড — "কত দিন ধরে অপেক্ষায়" (মালিকের ড্যাশবোর্ড নকশা, ২ অক্টোবর ২০২৬)।
 *
 * ⭐ প্রতিটা অপেক্ষমাণ অনুমোদন ঠিক একটা বয়সের ভাগে, আর ভাগের যোগফল উপরের "অপেক্ষমাণ" সংখ্যার সমান।
 * ⛔ যেটার সিদ্ধান্ত হয়ে গেছে (অনুমোদিত), সেটা অপেক্ষার হিসাবে নেই।
 */
final class TheWaitIsCountedInDaysTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_pending_approval_falls_in_one_age_and_the_ages_add_up_to_the_pending_count(): void
    {
        $company = Company::create(['code' => 'WAI', 'name_en' => 'Wait Co']);
        CompanyContext::set($company->id);

        $owner = User::factory()->create(['current_company_id' => $company->id]);
        $owner->companies()->attach($company->id);
        $this->actingAs($owner);

        foreach ([[2, Approval::PENDING], [30, Approval::PENDING], [100, Approval::PENDING], [240, Approval::PENDING], [300, Approval::PENDING], [5, Approval::APPROVED]] as $i => [$hoursAgo, $status]) {
            Approval::query()->create([
                'company_id' => $company->id, 'approvable_type' => 'test', 'approvable_id' => $i + 1,
                'module' => 'sales', 'action' => 'discount', 'amount' => '100', 'status' => $status,
                'current_level' => 1, 'requested_by' => $owner->id, 'requested_at' => now()->subHours($hoursAgo),
            ]);
        }

        $dashboard = ApprovalDashboard::dashboard();
        $ages = array_column($dashboard->panels[0]->parts, 'value', 'label');

        $this->assertSame([
            __('approval::dashboard.under_a_day') => '1',
            __('approval::dashboard.one_to_three') => '1',
            __('approval::dashboard.three_to_seven') => '1',
            __('approval::dashboard.over_a_week') => '2',
        ], $ages, '⛔ অপেক্ষার বয়সের ভাগ ভুল — কেউ দুই ভাগে, কেউ বাদ, বা অনুমোদিতটাও গোনায়।');

        $this->assertSame($dashboard->stats[0]->value, (string) array_sum(array_map('intval', $ages)),
            'বয়সের ভাগের যোগফল উপরের "অপেক্ষমাণ" সংখ্যার সমান নয়।');
    }
}
