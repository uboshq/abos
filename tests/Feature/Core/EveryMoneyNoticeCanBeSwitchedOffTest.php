<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Core\Support\NotificationKinds;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Services\DueNotices;
use App\Modules\Finance\Services\RentalContractService;
use App\Modules\Finance\Services\RentalNotices;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ অর্থের দশটা খবর কেউ বন্ধ করতে পারতেন না (২৪ অক্টোবর ২০২৬)।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * জমার শেষ সাত দিন, DPS কিস্তি, ব্যাংক ঋণের কিস্তি ও নবায়ন, বীমার নবায়ন ও প্রিমিয়াম, ভাড়া শেষের দুই ধাপ, বকেয়া ভাড়া আর ভাড়ার
 * বর্ষপূর্তি — সব পাঠানো হত, কিন্তু খবরের ধরনের তালিকায় ([[NotificationKinds::all()]]) ছিল না। ⚠️ তালিকায় না থাকলে সেটিংসের
 * পর্দায় টিক নেই, তাই বন্ধ করার উপায় নেই; আর যে খবর বন্ধ করা যায় না, মানুষ একদিন সবগুলোই না-দেখা শেখেন।
 * ⚠️ বর্ষপূর্তির ধরন ছিল বছর ধরে আলাদা (`…_2026`), আর বন্ধ রাখা হুবহু ধরন মেলায় — তালিকায় যেকোনো একটা বসালেও পরের বছরেরটা ধরা
 * পড়ত না। এখন ধরন একটাই, বছরে একবার আসে চাবি আর নীরব দিনের হিসাব থেকে।
 *
 * ── ⭐ দাবি ──────────────────────────────────────────────────────────
 * প্রতিটা ধরন তালিকায় আছে, দুই ভাষার নামসহ; সেটিংসের পর্দায় টিক না দিলে সেই খবর আর লেখা হয় না; আর সত্যিকারের বর্ষপূর্তির
 * খবর বন্ধ করা মানুষের কাছে যায় না, অথচ বাকিদের কাছে যায়।
 */
final class EveryMoneyNoticeCanBeSwitchedOffTest extends TestCase
{
    use RefreshDatabase;

    private const ADDED = [
        DueNotices::MATURING_SOON,
        DueNotices::DPS_DUE,
        DueNotices::BANK_INSTALMENT_DUE,
        DueNotices::BANK_RENEWAL_DUE,
        DueNotices::INSURANCE_RENEWAL_DUE,
        DueNotices::INSURANCE_PREMIUM_DUE,
        RentalNotices::ENDING.'60',
        RentalNotices::ENDING.'30',
        RentalNotices::OVERDUE,
        RentalNotices::ANNIVERSARY,
    ];

    private Company $company;

    private User $owner;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        // ⓘ নতুন একজন, সব শাখা — বীজের মানুষের শাখার দেয়াল থাকলে খবর এমনিতেই আটকাত, আর "বন্ধ" কিছুই মাপত না
        $this->clerk = $this->person(['finance.rental.view']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_money_notice_is_listed_with_a_name_in_both_languages(): void
    {
        $kinds = NotificationKinds::all();

        foreach (self::ADDED as $type) {
            $this->assertArrayHasKey($type, $kinds, "⛔ {$type} খবরের তালিকায় নেই — সেটিংসে টিক নেই, বন্ধ করা যায় না।");

            foreach (['bn', 'en'] as $locale) {
                $this->assertNotSame($kinds[$type], __($kinds[$type], [], $locale), "⛔ {$type}-এর {$locale} নাম নেই — পর্দায় কাঁচা চাবি।");
                $this->assertNotSame($kinds[$type].'_note', __($kinds[$type].'_note', [], $locale), "⛔ {$type}-এর {$locale} ব্যাখ্যা নেই — পর্দায় কাঁচা চাবি।");
            }
        }

        // ⓘ ভাড়া শেষের ধাপ তালিকা থেকে নেওয়া — ধাপ বদলালে তালিকাও বদলাতে হয়, নাহলে নতুন ধাপ আবার বন্ধ করা যেত না
        foreach (\App\Modules\Finance\Services\RentalDues::WINDOWS as $window) {
            $this->assertArrayHasKey(RentalNotices::ENDING.$window, $kinds, "⛔ ভাড়া শেষের {$window} দিনের ধাপ তালিকায় নেই।");
        }
    }

    public function test_switching_each_off_on_the_settings_page_stops_it(): void
    {
        $this->actingAs($this->clerk);
        $keep = array_values(array_diff(array_keys(NotificationKinds::all()), self::ADDED));
        $saved = $this->call('PUT', route('notifications.settings.update'), ['kinds' => $keep]);
        $this->assertSame(302, $saved->status(), 'প্রস্তুতিটাই ভুল — সেটিংস সংরক্ষণ হয়নি।');

        // ⓘ একই চাবির আরেকজন, সুইচ চালু — প্রতিটা ধরনে তাঁর কাছে যাওয়া প্রমাণ করে কর্মীর কাছে না যাওয়াটা সুইচের কারণেই
        $listener = $this->person(['finance.rental.view']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner);

        foreach (self::ADDED as $type) {
            app(NotificationService::class)->send($listener, $type, 'পরীক্ষার খবর');
            $this->assertSame(1, Notification::query()->where('user_id', $listener->id)->where('type', $type)->count(),
                "প্রস্তুতিটাই ভুল — {$type} সুইচ চালু রাখা মানুষের কাছেও যায়নি, তাহলে \"বন্ধ\" কিছুই মাপে না।");
            app(NotificationService::class)->send($this->clerk, $type, 'পরীক্ষার খবর');
            $this->assertSame(0, Notification::query()->where('user_id', $this->clerk->id)->where('type', $type)->count(),
                "⛔ {$type} বন্ধ করা সত্ত্বেও খবরটা লেখা হয়েছে।");
        }

        app(NotificationService::class)->send($this->clerk, 'approval.approved', 'চালু খবর');
        $this->assertSame(1, Notification::query()->where('user_id', $this->clerk->id)->where('type', 'approval.approved')->count(),
            'প্রস্তুতিটাই ভুল — চালু রাখা ধরনও আসেনি, তাহলে "বন্ধ" কিছুই মাপেনি।');
    }

    /**
     * ⭐ সত্যিকারের বর্ষপূর্তি — বন্ধ করা মানুষের কাছে যায় না, মালিকের কাছে যায়; আর একই বর্ষপূর্তি আবার বাজে না।
     */
    public function test_a_real_anniversary_notice_obeys_the_switch_and_rings_once(): void
    {
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        Carbon::setTestNow(now()->startOfMonth()->addDays(9));

        $this->actingAs($this->clerk);
        $keep = array_values(array_diff(array_keys(NotificationKinds::all()), [RentalNotices::ANNIVERSARY]));
        $this->assertSame(302, $this->call('PUT', route('notifications.settings.update'), ['kinds' => $keep])->status(),
            'প্রস্তুতিটাই ভুল — সেটিংস সংরক্ষণ হয়নি।');
        // ⓘ একই চাবির আরেকজন, সুইচ চালু — তাঁর কাছে যাওয়া প্রমাণ করে কর্মীর কাছে না যাওয়াটা সুইচের কারণেই
        $listener = $this->person(['finance.rental.view']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner);
        app(RentalContractService::class)->open([
            'counterparty' => 'Rising Landlord', 'subject' => 'Rising Landlord place', 'deposit_amount' => '0', 'monthly_rent' => '20000',
            'monthly_adjustment' => '0', 'term_months' => 36, 'starts_on' => now()->subYear()->addDays(20)->toDateString(), 'rent_day' => 5,
            'increase_percent' => '5',
        ]);

        $this->app['auth']->forgetGuards();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(RentalNotices::class)->anniversaries();
        app(RentalNotices::class)->anniversaries();

        $told = fn (User $u) => Notification::query()->where('user_id', $u->id)->where('type', RentalNotices::ANNIVERSARY)->count();
        $this->assertSame(1, $told($listener), 'প্রস্তুতিটাই ভুল — সুইচ চালু রাখা একই চাবির মানুষও খবর পাননি, তাহলে কর্মীর না-পাওয়া কিছুই মাপে না।');
        $this->assertSame(1, $told($this->owner), '⛔ বর্ষপূর্তির খবর মালিকের কাছে একবার যায়নি (যায়নি, নয়তো দুইবার)।');
        $this->assertSame(0, $told($this->clerk), '⛔ বর্ষপূর্তির খবর বন্ধ করা সত্ত্বেও কর্মীর কাছে গেল।');
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
