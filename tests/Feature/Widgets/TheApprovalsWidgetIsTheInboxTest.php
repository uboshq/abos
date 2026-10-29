<?php

declare(strict_types=1);

namespace Tests\Feature\Widgets;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Approval\Services\ApprovalFlowService;
use App\Modules\Approval\Widgets\ApprovalsSummary;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Services\DeliveryOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনের হোম-স্ক্রিনে "সইয়ের অপেক্ষা" — উইজেট `approvals` (উইজেটের চুক্তি §৩; মালিকের আদেশ, ২০ অক্টোবর ২০২৬; [[ApprovalsSummary]])।
 *
 * দাবি:
 *  - সারিগুলো ফোনের সই-বাক্সের (`GET /api/v1/approvals/pending`) কাগজই — একই নম্বর, একই অঙ্ক, ক্রম ঠিক উল্টো (নতুন আগে),
 *    সর্বোচ্চ ৮টা; আর `count` সই-বাক্সের মোট কাগজ;
 *  - ⛔ যিনি এই কাগজের সইকারী নন (চাবি থাকলেও) — `count` ০, সারি নেই;
 *  - ⛔ সই বন্ধ কোম্পানি (সব ছক বন্ধ) — সই-বাক্স খালি, উইজেটও খালি;
 *  - গ্রাহকের সারি "নাম · পয়েন্ট · শাখা" ([[Customer::nameWithPoint()]]), নাম/পয়েন্ট/শাখা আলাদা ঘরেও; কাগজের ধরন বাংলায়;
 *  - আসল দরজা (`GET /api/v1/widgets/summary`): সইকারী চাবিটা পান, চাবি (`approval.decide`) ছাড়া কেউ পান না।
 */
final class TheApprovalsWidgetIsTheInboxTest extends TestCase
{
    use RefreshDatabase;

    /** ৮-এর বেশি — যাতে সারির সীমা আর মোট গোনা দুটোই ধরা পড়ে */
    private const PAPERS = 10;

    private Company $company;

    private User $owner;

    private User $signer;

    private Customer $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);

        $point = Location::query()->create([
            'company_id' => $this->company->id, 'parent_id' => null, 'code' => 'PT-WAP',
            'name_en' => 'Kawran Bazar', 'name_bn' => 'কারওয়ান বাজার', 'level' => Location::POINT, 'is_active' => true,
        ]);
        $this->shop = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->shop->forceFill(['location_id' => $point->id, 'branch_id' => $this->company->defaultBranch()?->id])->save();

        $this->signer = $this->decider();
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'sales', 'action' => DeliveryOrderService::APPROVAL_ACTION, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);

        $product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $service = app(DeliveryOrderService::class);
        for ($i = 1; $i <= self::PAPERS; $i++) {
            $service->submit($service->create(['customer_id' => $this->shop->id, 'trx_date' => now()->toDateString()],
                [['product_id' => $product->id, 'qty' => (string) $i]], $this->owner), $this->owner);
        }
        $this->fresh();
    }

    public function test_the_rows_are_the_signers_inbox_newest_first_capped_at_eight_and_the_count_is_the_whole_inbox(): void
    {
        $inbox = $this->inbox($this->signer);
        $this->assertCount(self::PAPERS, $inbox, 'প্রস্তুতিটাই ভুল — সইকারীর সই-বাক্সে সব কাগজ নেই।');

        $widget = $this->widget($this->signer);
        $this->assertSame(self::PAPERS, $widget['count'], '⛔ count সই-বাক্সের মোট নয়।');
        $this->assertCount(ApprovalsSummary::ROWS, $widget['rows'], '⛔ সারির সীমা (৮) মানা হয়নি।');

        $expected = array_slice(array_reverse($inbox), 0, ApprovalsSummary::ROWS);
        $this->assertSame(array_column($expected, 'documentNo'), array_column($widget['rows'], 'number'),
            '⛔ উইজেটের কাগজ সই-বাক্সের কাগজ নয়, বা ক্রম নতুন-আগে নয়।');
        $this->assertSame(array_column($expected, 'requestedAmount'), array_column($widget['rows'], 'amount'), '⛔ অঙ্ক সই-বাক্সের সইয়ের অঙ্ক নয়।');
        $this->assertNotContains(null, array_column($widget['rows'], 'number'));

        $newest = Approval::query()->wherePublicId($expected[0]['id'])->firstOrFail();
        $this->assertSame(route('approval.inbox.show', ['approval' => $newest->id], false), $widget['rows'][0]['link'], '⛔ সারির link ঐ কাগজের ওয়েবের সই-পাতা নয়।');
        $this->assertSame(route('approval.inbox.index', [], false), $widget['link']);
        $this->assertStringStartsWith('/', $widget['link'], '⛔ link ওয়েবের পথ নয়।');
        $this->assertArrayNotHasKey('as_of', $widget, '⛔ তথ্যে সময় — দরজার ETag আর কখনো ৩০৪ দেবে না।');
    }

    public function test_someone_who_is_not_the_signer_gets_nothing_even_with_the_key(): void
    {
        $stranger = $this->decider();

        $this->assertSame([], $this->inbox($stranger), 'প্রস্তুতিটাই ভুল — অন্যজনের সই-বাক্সে কাগজ।');
        $widget = $this->widget($stranger);
        $this->assertSame(0, $widget['count'], '⛔ সইকারী নন, তবু উইজেটে কাগজ গোনা হলো।');
        $this->assertSame([], $widget['rows'], '⛔ সইকারী নন, তবু উইজেটে সারি।');
    }

    public function test_with_approvals_off_neither_the_inbox_nor_the_widget_shows_anything(): void
    {
        // ⓘ সই বন্ধ করার উপায় — কোম্পানির সব ছক বন্ধ (UB, ৩ অক্টোবর ২০২৬); ইনবক্স যেটা পড়ে
        ApprovalFlow::query()->where('company_id', $this->company->id)->update(['is_active' => false]);
        $this->fresh();

        $this->assertSame([], $this->inbox($this->signer), 'প্রস্তুতিটাই ভুল — সই বন্ধ, তবু সই-বাক্সে কাগজ।');
        $widget = $this->widget($this->signer);
        $this->assertSame(0, $widget['count'], '⛔ সই বন্ধ, তবু উইজেট কাগজ গুনল।');
        $this->assertSame([], $widget['rows'], '⛔ সই বন্ধ, তবু উইজেটে সারি।');
    }

    public function test_a_customer_row_reads_name_point_and_branch_and_the_kind_in_bengali(): void
    {
        $row = $this->widget($this->signer)['rows'][0];
        $shop = $this->shop->fresh()->load(Customer::PLACE);
        $this->assertNotNull($shop->branchName(), 'প্রস্তুতিটাই ভুল — দোকানের শাখা নেই।');

        $this->assertSame($shop->nameWithPoint(), $row['party']['label'] ?? null, '⛔ গ্রাহকের লেবেল "নাম · পয়েন্ট · শাখা" নয়।');
        $this->assertSame($shop->name().' · কারওয়ান বাজার · '.$shop->branchName(), $row['party']['label']);
        $this->assertSame($shop->name(), $row['party']['name']);
        $this->assertSame('কারওয়ান বাজার', $row['party']['point']);
        $this->assertSame($shop->branchName(), $row['party']['branch']);
        $this->assertSame('customer', $row['party']['kind']);

        $was = app()->getLocale();
        app()->setLocale('bn');
        $kind = app(ApprovalFlowService::class)->labels()['sales.'.DeliveryOrderService::APPROVAL_ACTION] ?? null;
        app()->setLocale($was);
        $this->assertNotNull($kind);
        $this->assertSame($kind, $row['kind_label'], '⛔ কাগজের ধরন সই-বাক্সের বাংলা নাম নয়।');
    }

    public function test_the_door_gives_the_widget_to_a_signer_and_not_to_someone_without_the_key(): void
    {
        $this->phone($this->signer);
        $widgets = $this->getJson('/api/v1/widgets/summary')->assertOk()->json('widgets');
        $this->assertArrayHasKey('approvals', $widgets, '⛔ সইকারী দরজায় উইজেটটা পেলেন না।');
        $this->assertSame(self::PAPERS, $widgets['approvals']['count']);

        $nobody = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $nobody->companies()->attach($this->company->id, ['is_active' => true]);
        $this->phone($nobody);
        $this->assertArrayNotHasKey('approvals', (array) $this->getJson('/api/v1/widgets/summary')->assertOk()->json('widgets'),
            '⛔ সইয়ের চাবি ছাড়াই উইজেটের তথ্য গেল।');
    }

    /** সইয়ের চাবিওয়ালা নতুন একজন — কোনো রোল নয়, তাই কেবল ছকে বসালে কাগজ পান */
    private function decider(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate('approval.decide', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** @return list<array<string, mixed>> সই-বাক্সের সব সারি, ফোনের দরজা দিয়ে, তার নিজের ক্রমে */
    private function inbox(User $user): array
    {
        $this->phone($user);

        return $this->getJson('/api/v1/approvals/pending?limit=100')->assertOk()->json('rows');
    }

    /** @return array<string, mixed> আসল দরজা দিয়ে */
    private function widget(User $user): array
    {
        $this->phone($user);
        $widgets = $this->getJson('/api/v1/widgets/summary?keys=approvals')->assertOk()->json('widgets');
        $this->assertArrayHasKey('approvals', (array) $widgets, 'প্রস্তুতিটাই ভুল — চাবিওয়ালা দরজায় উইজেট পেলেন না।');

        return $widgets['approvals'];
    }

    /** ইঞ্জিন ছকগুলো মনে রাখে — বদলের পর নতুন করে পড়ুক */
    private function fresh(): void
    {
        app()->forgetInstance(ApprovalEngine::class);
        app()->forgetScopedInstances();
    }

    private function phone(User $user): void
    {
        $this->fresh();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);
    }
}
