<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\ReportResult;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\SystemAdmin\Services\ScheduledReportRunner;
use App\Modules\SystemAdmin\Services\ScheduleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * রিপোর্ট শাখার দেয়াল পেরিয়ে গিয়েছিল — অডিট ২৭ সেপ্টেম্বর ২০২৬, §৩।
 *
 * ── ⛔ যা ভাঙা ছিল ─────────────────────────────────────────────────────
 * প্রতিটা রিপোর্ট `DB::table()` দিয়ে লেখা, তাই মডেলের শাখা-ছাঁকনি
 * ([[App\Core\Concerns\ScopedToUserBranch]]) সেখানে কোনোদিন পৌঁছায় না। ⚠️ আর
 * ইঞ্জিন `branch_id` নিত ঠিকানা থেকে, না দিলে "সব শাখা" — ফলে ময়মনসিংহে
 * আটকানো একজন কর্মী নেত্রকোনার বিক্রয়ও দেখতেন: পর্দায়, ফোনে, আর নির্ধারিত
 * ফাইলে, তিন জায়গাতেই।
 *
 * ⭐ প্রতিটা দাবি **একই মানুষ দুইবার** — সীমা থাকলে এক শাখা, ঐ মানুষেরই সীমা
 * তুলে নিলে দুই শাখা ([[a-door-claim-needs-one-actor-twice]])। দুইজন আলাদা
 * মানুষ হলে তফাতটা অনুমতি বা সদস্যপদ থেকেও আসতে পারত।
 *
 * ⓘ বিক্রয়গুলো আসল পথে বসে — [[SalesInvoiceService]] দিয়ে বানানো ও নিশ্চিত
 * করা, দুই শাখার দুই গুদাম থেকে। হাতে বসানো সারি হলে দাবিটা কোয়েরির কথা
 * বলত, ব্যবসার নয়।
 */
final class AReportCrossedTheBranchWallTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sales.by_customer';

    private const SLUG = 'by-customer';

    /** ময়মনসিংহে বেচা হয় — কর্মীর নিজের শাখা */
    private const OURS = 'Branchwall Mymensingh Buyer';

    /** নেত্রকোনায় বেচা হয় — কর্মীর নাগালের বাইরে */
    private const THEIRS = 'Branchwall Netrakona Buyer';

    private const OURS_AMOUNT = '700';

    private const THEIRS_AMOUNT = '300';

    private Company $company;

    private Branch $mymensingh;

    private Branch $netrakona;

    private User $owner;

    private User $clerk;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        $this->mymensingh = $this->branch('MMS');
        $this->netrakona = $this->branch('NTK');
        $this->day = now()->toDateString();

        [$ours, $theirs] = Customer::query()->orderBy('id')->take(2)->get()->all();
        $ours->forceFill(['name_en' => self::OURS, 'name_bn' => self::OURS])->save();
        $theirs->forceFill(['name_en' => self::THEIRS, 'name_bn' => self::THEIRS])->save();

        $this->sell($ours, $this->mymensingh, self::OURS_AMOUNT);
        $this->sell($theirs, $this->netrakona, self::THEIRS_AMOUNT);

        $this->clerk = User::factory()->create([
            'current_company_id' => $this->company->id,
            'current_branch_id' => $this->mymensingh->id,
            'is_active' => true,
        ]);
        $this->clerk->companies()->attach($this->company->id, [
            'is_active' => true,
            'default_branch_id' => $this->mymensingh->id,
        ]);
        CompanyContext::forCompany($this->company->id,
            fn () => $this->clerk->givePermissionTo(Permission::findOrCreate('sales.report', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->limitClerkTo($this->mymensingh);
    }

    // ── পর্দা ────────────────────────────────────────────────────────────

    /**
     * ⭐ একই কর্মী: ময়মনসিংহে আটকানো থাকলে পর্দায় কেবল ময়মনসিংহের বিক্রয় আর
     * যোগফল; সীমা তুলে নিলে দুই শাখাই।
     */
    public function test_a_clerk_limited_to_one_branch_sees_only_that_branch_on_the_web_until_the_limit_is_lifted(): void
    {
        $result = $this->web()->assertOk()->viewData('result');

        $this->assertSame([self::OURS], $this->names($result), '⛔ শাখায় আটকানো কর্মী অন্য শাখার বিক্রয় দেখেছেন।');
        $this->assertSame(0, bccomp($result->totals['total'], self::OURS_AMOUNT, 2),
            '⛔ সারি ঢাকা, অথচ যোগফলে অন্য শাখার টাকা ঢুকেছে।');

        $this->liftClerksLimit();

        $result = $this->web()->assertOk()->viewData('result');

        $this->assertSame([self::OURS, self::THEIRS], $this->names($result),
            'সীমা তোলার পরেও দুই শাখা দেখা যায়নি — দাবিটা কিছু মাপছে না।');
        $this->assertSame(0, bccomp($result->totals['total'], bcadd(self::OURS_AMOUNT, self::THEIRS_AMOUNT, 2), 2));
    }

    /**
     * ⛔ নাগালের বাইরের শাখাটা ঠিকানায় লিখে চাইলে — ফেরত, খালি পাতা নয়;
     * ⭐ ঐ কর্মীকেই শাখাটা দিলে — খোলে, আর কেবল ঐ শাখা।
     */
    public function test_asking_the_web_for_a_branch_outside_the_limit_is_refused_until_that_branch_is_given(): void
    {
        $this->web(['branch_id' => $this->netrakona->id])->assertSessionHasErrors('branch_id');

        $own = $this->web(['branch_id' => $this->mymensingh->id])->assertOk()->viewData('result');
        $this->assertSame([self::OURS], $this->names($own), 'নিজের শাখা চেয়েও পাওয়া যায়নি — দাবিটা কিছু মাপছে না।');

        $this->limitClerkTo($this->netrakona);

        $theirs = $this->web(['branch_id' => $this->netrakona->id])->assertOk()->viewData('result');
        $this->assertSame([self::THEIRS], $this->names($theirs));
    }

    /**
     * ⭐ দুই শাখায় আটকানো কর্মী কিছু না বেছে দুইটাই দেখেন — ইঞ্জিন একটা
     * শাখা চাপিয়ে দেয় না, সীমার পুরো তালিকাটা বসায়।
     */
    public function test_a_clerk_limited_to_two_branches_sees_both_without_choosing(): void
    {
        $this->limitClerkTo($this->netrakona);

        $result = $this->web()->assertOk()->viewData('result');

        $this->assertSame([self::OURS, self::THEIRS], $this->names($result));
    }

    /**
     * ⭐ শাখাহীন সারি দেয়ালের ভেতরে — [[DataScope::allows()]]-এর একই নিয়ম।
     * ⓘ একই কর্মী, একই বিল: নেত্রকোনার থাকলে ঢাকা; শাখা মুছে দিলে দেখা যায়।
     * ⛔ `whereIn` একা বসালে শাখাহীন কাগজ (প্রধান অফিসের) আটকানো মানুষের
     * কাছে চুপচাপ হারিয়ে যেত।
     */
    public function test_a_row_with_no_branch_is_seen_by_a_branch_limited_clerk(): void
    {
        $this->assertSame([self::OURS], $this->names($this->web()->assertOk()->viewData('result')));

        DB::table('sal_invoices')
            ->where('company_id', $this->company->id)
            ->where('branch_id', $this->netrakona->id)
            ->update(['branch_id' => null]);

        $this->assertSame([self::OURS, self::THEIRS], $this->names($this->web()->assertOk()->viewData('result')),
            '⛔ শাখাহীন বিলটা শাখায় আটকানো কর্মীর কাছে হারিয়ে গেছে।');
    }

    /**
     * ⭐ শাখার তালিকায় কেবল নাগালের শাখা — বাছলেই লাল হবে এমন পছন্দ পর্দা দেয় না।
     * ⓘ একই কর্মী: আটকানো থাকলে নেত্রকোনা তালিকায় নেই; সীমা তুলে নিলে আছে।
     */
    public function test_the_branch_dropdown_offers_only_the_clerks_branches_until_the_limit_is_lifted(): void
    {
        $option = fn (Branch $branch): string => '<option value="'.$branch->id.'"';

        $html = $this->web()->assertOk()->getContent();
        $this->assertStringContainsString($option($this->mymensingh), $html, 'নিজের শাখাই তালিকায় নেই — দাবিটা কিছু মাপছে না।');
        $this->assertStringNotContainsString($option($this->netrakona), $html, '⛔ নাগালের বাইরের শাখা তালিকায় দেখানো হয়েছে।');

        $this->liftClerksLimit();

        $this->assertStringContainsString($option($this->netrakona), $this->web()->assertOk()->getContent());
    }

    // ── ফোন ──────────────────────────────────────────────────────────────

    /**
     * ⭐ ফোনেও একই দেয়াল — একই কর্মী, আটকানো থাকলে এক শাখা, নাগালের বাইরের শাখা
     * চাইলে ৪২২; সীমা তুলে নিলে দুই শাখা, আর ঐ শাখাটাও খোলে।
     */
    public function test_the_phone_carries_only_the_clerks_branch_and_refuses_another_until_the_limit_is_lifted(): void
    {
        $body = $this->phone()->assertOk()->json();

        $this->assertSame([self::OURS], array_column($body['rows'], 'customer_name'),
            '⛔ ফোনে অন্য শাখার বিক্রয় এসেছে।');
        $this->assertSame(0, bccomp((string) $body['totals']['total'], self::OURS_AMOUNT, 2));

        $this->phone(['branch_id' => $this->netrakona->id])->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $this->liftClerksLimit();

        $this->assertSame([self::OURS, self::THEIRS], array_column($this->phone()->assertOk()->json('rows'), 'customer_name'));
        $this->assertSame([self::THEIRS], array_column(
            $this->phone(['branch_id' => $this->netrakona->id])->assertOk()->json('rows'), 'customer_name'));
    }

    // ── নির্ধারিত ফাইল ────────────────────────────────────────────────────

    /**
     * ⭐ নির্ধারিত ফাইলও মালিকের সীমার ভেতরে — ক্রন কারো পর্দা নয়, তবু ফাইলটা
     * তাঁর নামে চলে। ⓘ একই সূচি, একই কর্মী: আটকানো থাকলে এক শাখা, সীমা
     * তুলে নিলে দুই শাখা।
     */
    public function test_a_scheduled_file_carries_only_the_owners_branches_until_the_limit_is_lifted(): void
    {
        $schedule = $this->scheduleAsClerk([]);

        $text = $this->runSchedule($schedule);
        $this->assertStringContainsString(self::OURS, $text, 'নিজের শাখার বিক্রয়ই ফাইলে নেই — দাবিটা কিছু মাপছে না।');
        $this->assertStringNotContainsString(self::THEIRS, $text, '⛔ নির্ধারিত ফাইলে অন্য শাখার বিক্রয় গেছে।');

        $this->liftClerksLimit();

        $text = $this->runSchedule($schedule);
        $this->assertStringContainsString(self::OURS, $text);
        $this->assertStringContainsString(self::THEIRS, $text);
    }

    /**
     * ⛔ সূচিতে নাগালের বাইরের শাখা লেখা থাকলে ফাইলই হয় না — চুপচাপ অন্য
     * শাখার সংখ্যা পাঠানো বা নিজের শাখায় নামিয়ে আনা, দুইটাই ভুল উত্তর।
     */
    public function test_a_schedule_naming_a_branch_outside_the_owners_limit_makes_no_file(): void
    {
        $schedule = $this->scheduleAsClerk(['branch_id' => $this->netrakona->id]);

        try {
            $this->runner()->runOne($schedule->fresh());
            $this->fail('⛔ নাগালের বাইরের শাখার সূচি চলে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('branch_id', $e->errors());
        } finally {
            Auth::logout();
        }

        $this->assertSame(0, ReportRun::query()->withoutGlobalScopes()->where('report_schedule_id', $schedule->id)->count());

        $this->liftClerksLimit();

        $this->assertStringContainsString(self::THEIRS, $this->runSchedule($schedule));
    }

    // ── যে রিপোর্ট শাখায় ভাগ হয় না ────────────────────────────────────────

    /**
     * ⛔ গোটা কোম্পানির হিসাব (সরবরাহকারী ধরে নিষ্পত্তি) শাখায় ভাগ করা যায়
     * না, তাই শাখায় আটকানো মানুষ ওটা পান না; ⭐ ঐ মানুষেরই সীমা তুলে নিলে পান।
     */
    public function test_a_whole_company_report_is_refused_to_a_branch_limited_user_until_the_limit_is_lifted(): void
    {
        $this->actingAs($this->clerk);
        app(DataScope::class)->forget();

        try {
            app(ReportEngine::class)->run('purchase.settlement', ['from' => $this->day, 'to' => $this->day]);
            $this->fail('⛔ শাখায় আটকানো মানুষ গোটা কোম্পানির নিষ্পত্তি পেয়েছেন।');
        } catch (AuthorizationException) {
            // ⭐ ঠিক এটাই
        }

        $this->liftClerksLimit();
        $this->actingAs($this->clerk);

        $this->assertInstanceOf(ReportResult::class,
            app(ReportEngine::class)->run('purchase.settlement', ['from' => $this->day, 'to' => $this->day]));
    }

    /**
     * ⭐ কোম্পানির সব শাখা যাঁর হাতে, তিনি সীমিত নন — লাইভে ধরা, ২৮ সেপ্টেম্বর ২০২৬: মালিক
     * নিজের ব্যবহারকারীতে কোম্পানির একমাত্র শাখা টিক দিয়েছিলেন, আর অনুমোদনের সব রিপোর্ট
     * ৪০৩ দিল।
     *
     * ⚠️ দুই দিক, একই মানুষ: এক শাখা বাদে সব → এখনো সীমিত, গোটা কোম্পানির রিপোর্ট নয়;
     * শেষ শাখাটাও দিলে → পান। ⛔ "প্রায় সব" কে অসীম ধরলে যে একটা শাখা বাদ, তার সংখ্যা
     * গোটা-কোম্পানির যোগফলে ঢুকে দেখা যেত।
     */
    public function test_a_user_holding_every_branch_is_not_limited_but_one_short_still_is(): void
    {
        $every = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->pluck('id')->all();
        $this->assertGreaterThan(1, count($every), 'প্রস্তুতিটাই ভুল — কোম্পানির একটাই শাখা।');

        $this->liftClerksLimit();
        $last = array_pop($every);

        foreach ($every as $id) {
            $this->limitClerkTo(Branch::query()->withoutGlobalScopes()->findOrFail($id));
        }

        $this->actingAs($this->clerk);

        try {
            app(ReportEngine::class)->run('purchase.settlement', ['from' => $this->day, 'to' => $this->day]);
            $this->fail('⛔ এক শাখা বাদ থাকা মানুষ গোটা কোম্পানির নিষ্পত্তি পেয়েছেন।');
        } catch (AuthorizationException) {
            // ⭐ ঠিক এটাই — এখনো সীমিত
        }

        $this->limitClerkTo(Branch::query()->withoutGlobalScopes()->findOrFail($last));
        $this->actingAs($this->clerk);

        $this->assertInstanceOf(ReportResult::class,
            app(ReportEngine::class)->run('purchase.settlement', ['from' => $this->day, 'to' => $this->day]),
            '⛔ সব শাখা হাতে থাকা মানুষকেও গোটা কোম্পানির রিপোর্ট দেওয়া হল না।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', $code)
            ->firstOrFail();
    }

    /** আসল পথে একটা নিশ্চিত বিল — ঐ শাখার নিজের গুদাম থেকে। */
    private function sell(Customer $customer, Branch $branch, string $amount): void
    {
        CompanyContext::set($this->company->id, $branch->id);

        $warehouse = Warehouse::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('branch_id', $branch->id)
            ->orderBy('id')
            ->value('id');

        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse,
                'trx_date' => $this->day,
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => $amount]],
        );

        app(SalesInvoiceService::class)->confirm($invoice);

        $this->assertSame($branch->id, (int) $invoice->fresh()->branch_id, 'বিলটা ঠিক শাখায় বসেনি — দাবিটা কিছু মাপছে না।');

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    /** @param array<string, mixed> $query */
    private function web(array $query = []): TestResponse
    {
        app(DataScope::class)->forget();

        return $this->actingAs($this->clerk)->get(route('sales.report.show', [
            'slug' => self::SLUG,
            'from' => $this->day,
            'to' => $this->day,
            ...$query,
        ]));
    }

    /** @param array<string, mixed> $query */
    private function phone(array $query = []): TestResponse
    {
        app(DataScope::class)->forget();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->clerk->fresh(), [AuthController::APP]);

        return $this->getJson('/api/v1/reports/'.self::KEY.'?'.http_build_query([
            'from' => $this->day,
            'to' => $this->day,
            ...$query,
        ]));
    }

    /** @param array<string, mixed> $filters */
    private function scheduleAsClerk(array $filters): ReportSchedule
    {
        $this->actingAs($this->clerk);

        $schedule = app(ScheduleService::class)->create([
            'report_key' => self::KEY,
            'frequency' => 'daily',
            'format' => 'csv',
            'filters' => ['from' => $this->day, 'to' => $this->day, ...$filters],
        ]);

        Auth::logout();

        return $schedule;
    }

    private function runSchedule(ReportSchedule $schedule): string
    {
        app(DataScope::class)->forget();

        $run = $this->runner()->runOne($schedule->fresh());
        $this->assertNotNull($run, 'সূচিটা চলেনি — দাবিটা কিছু মাপছে না।');

        return (string) Storage::disk('local')->get((string) $run->file_path);
    }

    private function runner(): ScheduledReportRunner
    {
        return app(ScheduledReportRunner::class);
    }

    /** @return list<string> সারিগুলোর ক্রেতা, নাম ধরে সাজানো */
    private function names(ReportResult $result): array
    {
        $names = array_map(fn (array $row): string => (string) $row['customer_name'], $result->rows);
        sort($names);

        return $names;
    }

    private function limitClerkTo(Branch $branch): void
    {
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->clerk->id,
            'scope_type' => UserDataScope::BRANCH,
            'scope_id' => $branch->id,
        ]);
        app(DataScope::class)->forget();
    }

    private function liftClerksLimit(): void
    {
        UserDataScope::query()->withoutGlobalScopes()
            ->where('user_id', $this->clerk->id)
            ->where('scope_type', UserDataScope::BRANCH)
            ->delete();
        app(DataScope::class)->forget();
    }
}
