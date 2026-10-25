<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationRule;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AdjustingReversals;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ এক আটকে-থাকা উল্টো জাবেদার খবরে নিয়মের বাড়তি প্রাপক N-টা কপি পেতেন (cb-র রিভিউ, cloud/notification-management মেশানোর আগে,
 * ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ মডিউল চাবিধারী প্রত্যেককে আলাদা আলাদা পাঠাত, চাবি ছাড়া — প্রতিটা ডাক নতুন ঘটনা, আর নিয়ম প্রতিটায় নিজের প্রাপক যোগ করত। এখন সবার জন্য
 * একটাই চাবি, একটাই ঘটনা: চাবিধারীরা একটা করে, নিয়মের মানুষও একটা।
 */
final class OneStuckReversalIsOneNoticeForARulesPeopleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rules_extra_recipient_gets_one_notice_however_many_key_holders_are_told(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $holders = collect(['a', 'b', 'c'])->map(function (string $tag) use ($company): User {
            $user = User::factory()->create(['name' => 'holder '.$tag, 'current_company_id' => $company->id, 'is_active' => true]);
            $user->companies()->attach($company->id, ['is_active' => true]);
            // ⓘ খবর যায় কেবল যিনি জাবেদাটা খুলতে পারেন — মাসের চাবি আর ভাউচার দেখার চাবি দুটোই ([[ANoticeKnowsItsPaperTest]], ২৪ অক্টোবর ২০২৬)
            CompanyContext::forCompany($company->id, fn () => $user->givePermissionTo(
                Permission::findOrCreate(AdjustingReversals::WHO_HEARS, 'web'), Permission::findOrCreate('accounts.report', 'web')));

            return $user;
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $watcher = User::factory()->create(['name' => 'rule watcher', 'current_company_id' => $company->id, 'is_active' => true]);
        $watcher->companies()->attach($company->id, ['is_active' => true]);
        NotificationRule::query()->create(['name' => 'stuck watch', 'module' => 'accounts', 'event' => AdjustingReversals::STUCK,
            'recipients' => ['users' => [$watcher->id]], 'is_active' => true, 'version' => 1]);

        // ⓘ ডেমোতে ভাউচার নেই — একটা জাবেদা বসানো (যার উল্টো আটকে আছে বলে খবর যাবে)
        $vouchers = app(VoucherService::class);
        $voucher = $vouchers->post($vouchers->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'stuck reversal probe'],
            [['account_id' => \App\Modules\Accounts\Models\Account::query()->money()->postable()->active()->firstOrFail()->id, 'debit' => '100', 'credit' => '0'],
                ['account_id' => \App\Modules\Accounts\Models\Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail()->id, 'debit' => '0', 'credit' => '100']],
        ));
        $tell = new \ReflectionMethod(AdjustingReversals::class, 'tellStuck');
        $tell->invoke(app(AdjustingReversals::class), (int) $voucher->id, 'the month is closed');

        $count = fn (User $u) => Notification::query()->withoutGlobalScopes()->where('user_id', $u->id)->where('type', AdjustingReversals::STUCK)->count();
        foreach ($holders as $holder) {
            $this->assertSame(1, $count($holder), '⛔ চাবিধারী একটা করে খবর পেলেন না।');
        }
        $this->assertSame(1, $count($watcher), '⛔ নিয়মের মানুষ একই খবরের একাধিক কপি পেলেন।');
    }
}
