<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * অনুমোদনের ছকে প্রতিটা ধাপ "১" — পাতা-ঝাড়ু, ধাপ ০ (১০ অক্টোবর ২০২৬)।
 *
 * ⓘ সংখ্যাগুলো ঠিকই ছিল (পাঁচজন একই স্তর ১-এ; নিচের "১" কয়জনের সই), কিন্তু ঘরের নাম কেবল aria-label-এ — পর্দায় কিছু লেখা নেই।
 * ⭐ প্রতিটা ধাপের প্রতিটা ঘরের মাথায় দেখা যায় এমন নাম: স্তর, ধাপের নাম, অনুমোদনকারী, কয়জনের সই, সময়সীমা, সতর্ক, উপরে পাঠাও, দেরি হলে কার কাছে।
 */
final class TheFlowStepsWereNumbersWithoutNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_step_field_has_a_visible_name(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $html = $this->get(route('approval.flow.create'))->assertOk()->getContent();
        $rows = substr_count($html, 'name="steps[') > 0 ? preg_match_all('/name="steps\[\d+\]\[level\]"/', $html) : 0;
        $this->assertGreaterThan(0, $rows, 'দৃশ্যটাই বানানো যায়নি — ধাপের সারি নেই।');

        foreach (['level', 'step_name', 'approver', 'min_approvals', 'sla_hours', 'warn_hours', 'escalate_hours', 'escalate_to'] as $field) {
            $visible = preg_match_all('#<span class="text-2xs[^"]*">\s*'.preg_quote(e(__('approval::field.'.$field)), '#').'\s*</span>#u', $html);
            $this->assertSame($rows, $visible, '⛔ "'.__('approval::field.'.$field).'" ঘরের নাম প্রতিটা ধাপে পর্দায় নেই।');
        }
    }
}
