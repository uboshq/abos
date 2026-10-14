<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\NoticeLifecycle;
use App\Core\Support\CompanyContext;
use App\Core\Support\NoticePriority;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ অচেনা অগ্রাধিকার ফর্মের ভুল, ৫০০ নয় — Notice লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⓘ ফর্ম যেকোনো ১৬ অক্ষরের লেখা নিত; সম্পাদনায় সেটা enum-এর ঘরে বসতে গিয়ে পাতা ভেঙে পড়ত।
 */
final class ABadPriorityIsAFormErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_priority_is_refused_and_a_known_one_is_saved(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        $notice = app(NoticeLifecycle::class)->draft(['title' => 'খসড়া', 'body' => 'লেখা']);

        $this->put(route('system_admin.notice.update', $notice->id), ['title' => 'খসড়া', 'priority' => 'bogus'])
            ->assertSessionHasErrors('priority');

        $this->put(route('system_admin.notice.update', $notice->id), ['title' => 'খসড়া', 'priority' => NoticePriority::HIGH->value])
            ->assertSessionDoesntHaveErrors('priority');
        $this->assertSame(NoticePriority::HIGH, $notice->fresh()->priority);
    }
}
