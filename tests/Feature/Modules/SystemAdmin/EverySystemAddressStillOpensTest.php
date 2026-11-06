<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Notice;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⭐ পুরনো ঠিকানা এখনো খোলে — কন্ট্রোল প্যানেলের পরিকল্পনা, মালিকের অনুমোদিত, ২১ অক্টোবর ২০২৬, দফা ১৫: "পুরনো ঠিকানা কাজ করার পরীক্ষা"।
 *
 * ⓘ দফা ১–১৪ মেনু, ঘর, ট্যাব আর পাতা বদলেছে, কিন্তু কোনো ঠিকানা সরায়নি — মানুষের বুকমার্ক, নোটিশের লিংক আর অ্যাপের ভিতরের
 * ওয়েব এই ঠিকানাগুলোতেই যায়। ⓘ তালিকাটা পরিকল্পনার আগের (753023740-এর আগে) সিস্টেম প্রশাসনের সব GET ঠিকানা, হাতে লেখা — নতুন
 * ঠিকানা যোগ হলে এখানে না থাকলেও চলে, কিন্তু একটা সরে গেলে বা ভেঙে গেলে এই দাবিই প্রথম বলে।
 */
final class EverySystemAddressStillOpensTest extends TestCase
{
    use RefreshDatabase;

    /** পরিকল্পনার আগের ঠিকানা (প্যারামিটার ছাড়া) */
    private const BEFORE_THE_PLAN = [
        '/import',
        '/system/branch-modules', '/system/branches', '/system/branches/create', '/system/companies', '/system/companies/create',
        '/system/control-panel', '/system/custom-fields', '/system/looks', '/system/looks/create',
        '/system/notices', '/system/notices/analytics', '/system/notices/categories', '/system/notices/new', '/system/notices/templates',
        '/system/ownership', '/system/print-control', '/system/print-control/invoice-info', '/system/print-control/preview',
        '/system/reports/schedules', '/system/reports/schedules/create', '/system/roles', '/system/roles/create', '/system/settings',
        '/system/users', '/system/users/create',
    ];

    /** দফা ৯ আর ১০-এর নতুন ঠিকানা */
    private const ADDED_BY_THE_PLAN = ['/system/login-security', '/system/roles/compare'];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_every_address_from_before_the_plan_and_the_new_ones_open_for_the_owner(): void
    {
        $this->actingAs($this->owner);
        $broken = [];

        foreach ([...self::BEFORE_THE_PLAN, ...self::ADDED_BY_THE_PLAN] as $uri) {
            $status = $this->get($uri)->status();

            // ⓘ 200 বা অন্য পাতায় পাঠানো (যেমন সেটআপ শেষ হলে) — দুটোই "খোলে"; 404/403/500 নয়
            if ($status >= 400) {
                $broken[] = "{$uri} → {$status}";
            }
        }

        $this->assertSame([], $broken, "⛔ পুরনো বা নতুন ঠিকানা আর খোলে না:\n".implode("\n", $broken));

        // ⓘ প্রথমবারের সেটআপ ইচ্ছা করেই বন্ধ (404) সিস্টেম বসানোর পরে — নইলে কেউ আবার মালিক বানিয়ে নিত ([[SetupController::show()]])
        $this->assertSame(404, $this->get('/setup')->status(), '⛔ সেটআপ শেষ হওয়ার পরেও প্রথমবারের সেটআপ খোলা।');
    }

    public function test_the_edit_pages_of_each_kind_of_record_still_open(): void
    {
        $this->actingAs($this->owner);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $role = Role::query()->where('company_id', $company->id)->where('name', '!=', \App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
        $notice = Notice::create(['title' => 'ঠিকানার পরীক্ষা', 'body' => 'x', 'is_active' => true, 'created_by' => $this->owner->id]);

        $pages = [
            route('system_admin.user.edit', User::query()->where('email', 'accounts@abos.test')->firstOrFail()),
            route('system_admin.role.edit', $role),
            route('system_admin.company.edit', $company),
            route('system_admin.branch.edit', Branch::query()->where('company_id', $company->id)->firstOrFail()),
            route('system_admin.notice.show', $notice->id),
            route('module.dashboard', 'system_admin'),
        ];

        $broken = [];
        foreach ($pages as $url) {
            $status = $this->get($url)->status();
            if ($status !== 200) {
                $broken[] = "{$url} → {$status}";
            }
        }

        $this->assertSame([], $broken, "⛔ রেকর্ডের পাতা আর খোলে না:\n".implode("\n", $broken));
    }
}
