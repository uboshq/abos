<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\PartyRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AssetCategory;
use App\Modules\Accounts\Models\AssetEvent;
use App\Modules\Accounts\Models\AssetTransfer;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\AssetCategoryService;
use App\Modules\Accounts\Services\AssetEventService;
use App\Modules\Accounts\Services\DepreciationEngine;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * সম্পদের জন্ম আর মৃত্যু খাতা দেখত, মাঝের জীবন নয় — স্থায়ী সম্পদ ধাপ ৩ (মালিক, ১০ অক্টোবর ২০২৬; IAS 16, IAS 36)।
 *
 * ⭐ দাবিগুলো:
 *   · সংযোজনে দাম বাড়ে, আয়ু বাড়ে, আর পরের অবচয় বাকি দাম বাকি আয়ুতে ভাগ হয়।
 *   · মেরামত খরচে যায়, দামে নয়; অন্য কাগজে বসা মেরামত কেবল লেখা থাকে — খাতায় দুইবার নয়।
 *   · পুনর্মূল্যায়ন সুইচ ছাড়া নয়; বাড়লে উদ্বৃত্তে, কমলে আগে উদ্বৃত্ত তারপর লোকসান।
 *   · দাম পড়ার লোকসান শ্রেণির খাতে, সঞ্চিত ক্ষয়ে জমে; পরের অবচয় নতুন দামে।
 *   · একই শাখায় কর্মী বা জায়গা বদলে খাতায় কিছু বসে না; শাখা বদলালে বসে।
 *   · বিক্রি, বাতিল, হারানো — লাভ-লোকসান শ্রেণির নিজের খাতে, শেষ অবস্থা আলাদা।
 *   · টাকার প্রতিটা ঘটনা নিজের সই ছাড়া খাতায় যায় না।
 */
final class AnAssetLivedAndTheBooksSawOnlyItsBirthAndDeathTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private FixedAssetService $assets;

    private AssetEventService $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->assets = app(FixedAssetService::class);
        $this->events = app(AssetEventService::class);
    }

    public function test_an_addition_raises_the_cost_extends_the_life_and_spreads_what_is_left(): void
    {
        $van = $this->depreciatedVan();
        $cash = $this->cash();
        [$cashBefore, $costBefore] = [$this->balanceOf($cash), $this->balanceOf($van->asset_account_id)];

        $event = $this->events->addition($van, [
            'amount' => '18000', 'happened_on' => '2026-09-01', 'funded_by' => FixedAssetService::FUNDED_MONEY,
            'funding_account_id' => $cash, 'extend_months' => 12, 'reason' => 'New body',
        ]);

        $van->refresh();
        $this->assertSame(AssetEvent::POSTED, $event->status);
        $this->assertSame(0, bccomp((string) $van->cost, '138000', 4), '⛔ সংযোজনে দাম বাড়েনি।');
        $this->assertSame(72, (int) $van->life_months, '⛔ আয়ু বাড়েনি।');
        $this->assertSame(0, bccomp(bcsub($this->balanceOf($van->asset_account_id), $costBefore, 4), '18000', 4), '⛔ সম্পদের খাতে সংযোজন বসেনি।');
        $this->assertSame(0, bccomp(bcsub($cashBefore, $this->balanceOf($cash), 4), '18000', 4), '⛔ টাকার খাত থেকে টাকা বেরোয়নি।');
        $this->assertSame(1, $van->estimateChanges()->count(), 'আয়ু বাড়ানো অনুমান বদলের ইতিহাসে নেই।');

        // ⓘ বাকি ১,৩৪,০০০ (১,৩৮,০০০ − ৪,০০০) বাকি ৭০ মাসে (৭২ − ২)
        $this->assertSame(bcdiv('134000', '70', 4), app(DepreciationEngine::class)->amountFor($van, '2026-09-30')['amount'],
            '⛔ সংযোজনের পর অবচয় বাকি দাম বাকি আয়ুতে ভাগ হয়নি।');

        // ⓘ পুরনো মাসের দামে পরের সংযোজন ঢোকে না
        $this->assertSame(0, bccomp($van->costOn(now()->setDate(2026, 8, 31)), '120000', 4));
    }

    public function test_a_repair_is_an_expense_and_a_logged_repair_is_not_booked_twice(): void
    {
        $van = $this->depreciatedVan();
        $cash = $this->cash();
        $repairs = (int) Account::query()->postable()->where('code', '5206')->value('id');
        $before = $this->balanceOf($repairs);

        $this->events->repair($van, [
            'amount' => '3500', 'happened_on' => '2026-09-05', 'funded_by' => FixedAssetService::FUNDED_MONEY,
            'funding_account_id' => $cash, 'charge_account_id' => $repairs, 'reason' => 'Brake pads',
        ]);

        $this->assertSame(0, bccomp(bcsub($this->balanceOf($repairs), $before, 4), '3500', 4), '⛔ মেরামত খরচের খাতে বসেনি।');
        $this->assertSame(0, bccomp((string) $van->fresh()->cost, '120000', 4), '⛔ মেরামত সম্পদের দামে ঢুকে গেল।');

        $logged = $this->events->repair($van, ['amount' => '900', 'happened_on' => '2026-09-06', 'funded_by' => FixedAssetService::FUNDED_ALREADY]);
        $this->assertSame(AssetEvent::POSTED, $logged->status);
        $this->assertSame(0, LedgerEntry::query()->where('source_type', 'asset_event')->where('source_id', $logged->id)->count(),
            '⛔ অন্য কাগজে বসা মেরামত আবার খাতায় বসল।');

        $this->expectException(ValidationException::class);
        $this->events->repair($van, ['amount' => '500', 'happened_on' => '2026-09-06', 'funded_by' => FixedAssetService::FUNDED_MONEY,
            'funding_account_id' => $cash, 'charge_account_id' => $cash]);
    }

    public function test_revaluation_needs_the_switch_and_uses_the_surplus_before_the_profit_and_loss(): void
    {
        $category = $this->category();
        $van = $this->depreciatedVan($category);
        $surplus = $this->account('3410', 'Revaluation surplus', Account::EQUITY, Account::CREDIT);

        try {
            $this->events->revalue($van, ['amount' => '130000', 'account_id' => $surplus, 'happened_on' => '2026-09-01', 'reason' => 'Valuer']);
            $this->fail('⛔ সুইচ বন্ধ থাকতেও পুনর্মূল্যায়ন হলো।');
        } catch (ValidationException) {
        }

        app(SettingsService::class)->set(AssetEventService::REVALUATION, true);
        $accumulated = (int) StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)->id;
        $accBefore = $this->balanceOf($accumulated);

        // ⓘ খাতার দাম ১,১৬,০০০ → ১,৩০,০০০: উদ্বৃত্তে ১৪,০০০, সঞ্চিত ক্ষয় ৪,০০০ মোছে, দামে ১০,০০০
        $this->events->revalue($van, ['amount' => '130000', 'account_id' => $surplus, 'happened_on' => '2026-09-01', 'reason' => 'Valuer']);
        $van->refresh();
        $this->assertSame(0, bccomp($this->balanceOf($surplus), '-14000', 4), '⛔ বাড়তি দাম উদ্বৃত্তে যায়নি।');
        $this->assertSame(0, bccomp(bcsub($this->balanceOf($accumulated), $accBefore, 4), '4000', 4), '⛔ সঞ্চিত ক্ষয় মোছেনি।');
        $this->assertSame(0, bccomp($van->accumulated(), '0', 4));
        $this->assertSame(0, bccomp($van->bookValue(), '130000', 4));

        // ⓘ ১,৩০,০০০ → ১,১০,০০০: আগে উদ্বৃত্তের ১৪,০০০, বাকি ৬,০০০ লোকসান
        $loss = (int) $category->impairment_account_id;
        $this->events->revalue($van, ['amount' => '110000', 'account_id' => $surplus, 'happened_on' => '2026-09-02', 'reason' => 'Market fell']);
        $this->assertSame(0, bccomp($this->balanceOf($surplus), '0', 4), '⛔ কমতি আগে উদ্বৃত্ত খায়নি।');
        $this->assertSame(0, bccomp($this->balanceOf($loss), '6000', 4), '⛔ উদ্বৃত্তের বাইরের কমতি লোকসানে যায়নি।');
        $this->assertSame(0, bccomp($van->fresh()->bookValue(), '110000', 4));
    }

    public function test_an_impairment_is_a_loss_in_the_category_account_and_depreciation_follows_the_new_value(): void
    {
        $bare = $this->depreciatedVan();

        try {
            $this->events->impair($bare, ['amount' => '100000', 'happened_on' => '2026-09-01', 'reason' => 'Flood']);
            $this->fail('⛔ শ্রেণির দাম-পড়ার খাত ছাড়াই লোকসান বসল।');
        } catch (ValidationException) {
        }

        $category = $this->category();
        $van = $this->depreciatedVan($category, 'Second van');
        $accumulated = (int) StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)->id;
        $accBefore = $this->balanceOf($accumulated);

        $this->events->impair($van, ['amount' => '100000', 'happened_on' => '2026-09-01', 'reason' => 'Flood']);
        $van->refresh();

        $this->assertSame(0, bccomp($this->balanceOf((int) $category->impairment_account_id), '16000', 4), '⛔ ১,১৬,০০০ − ১,০০,০০০ লোকসান বসেনি।');
        $this->assertSame(0, bccomp(bcsub($accBefore, $this->balanceOf($accumulated), 4), '16000', 4), '⛔ সঞ্চিত ক্ষয়ে জমেনি।');
        $this->assertSame(0, bccomp($van->bookValue(), '100000', 4));
        $this->assertSame(bcdiv('100000', '58', 4), app(DepreciationEngine::class)->amountFor($van, '2026-09-30')['amount'],
            '⛔ পরের অবচয় নতুন দামে নয়।');

        $this->expectException(ValidationException::class);
        $this->events->impair($van, ['amount' => '100000', 'happened_on' => '2026-09-02', 'reason' => 'Again']);
    }

    public function test_a_custody_move_posts_nothing_and_a_branch_move_posts_both_branches(): void
    {
        $van = $this->depreciatedVan();
        $employee = collect(collect(app(PartyRegistry::class)->forPicker())->firstWhere('type', 'employee')['options'] ?? [])->first();
        $this->assertNotNull($employee, 'ডেমোতে কর্মী নেই।');

        $move = $this->assets->transfer($van, null, '2026-09-01', 'Handed over', [
            'custodian_id' => (int) $employee['id'], 'location' => 'Depot yard', 'department' => 'Delivery',
        ]);

        $this->assertFalse($move->movedBranch());
        $this->assertSame((int) $employee['id'], (int) $van->fresh()->custodian_id);
        $this->assertSame('Depot yard', $van->fresh()->location);
        $this->assertSame(0, LedgerEntry::query()->where('source_type', AssetTransfer::drillSourceType())->where('source_id', $move->id)->count(),
            '⛔ একই শাখায় সরানোয় দাখিলা বসল।');

        $other = Branch::query()->where('id', '!=', $van->branch_id)->firstOrFail();
        $away = $this->assets->transfer($van->fresh(), (int) $other->id, '2026-09-02');
        $this->assertSame(4, LedgerEntry::query()->where('source_type', AssetTransfer::drillSourceType())->where('source_id', $away->id)->count(),
            '⛔ শাখা বদলে দুই শাখার খাতায় বসেনি।');

        $this->expectException(ValidationException::class);
        $this->assets->transfer($van->fresh(), null, '2026-09-03', null, ['location' => 'Depot yard']);
    }

    public function test_sale_scrap_and_loss_book_gain_or_loss_in_the_category_accounts(): void
    {
        $category = $this->category();
        $cash = $this->cash();

        $sold = $this->depreciatedVan($category, 'Sold van');
        $this->assets->dispose($sold, '125000', $cash, '2026-09-10');
        $this->assertSame(FixedAsset::DISPOSED, $sold->fresh()->status);
        $this->assertSame(0, bccomp($this->balanceOf((int) $category->gain_account_id), '-9000', 4), '⛔ ১,২৫,০০০ − ১,১৬,০০০ লাভ শ্রেণির খাতে নয়।');

        $scrapped = $this->depreciatedVan($category, 'Scrapped van');
        $this->assets->dispose($scrapped, '1000', $cash, '2026-09-10', FixedAsset::WRITTEN_OFF, 'Engine seized');
        $this->assertSame(FixedAsset::WRITTEN_OFF, $scrapped->fresh()->status);
        $this->assertSame('Engine seized', $scrapped->fresh()->disposal_reason);
        $this->assertSame(0, bccomp($this->balanceOf((int) $category->loss_account_id), '115000', 4), '⛔ বাতিলের লোকসান শ্রেণির খাতে নয়।');

        $stolen = $this->depreciatedVan($category, 'Stolen van');
        $this->assets->dispose($stolen, '0', null, '2026-09-11', FixedAsset::LOST, 'Stolen at night');
        $this->assertSame(FixedAsset::LOST, $stolen->fresh()->status);
        $this->assertSame(0, bccomp($this->balanceOf((int) $category->loss_account_id), '231000', 4), '⛔ হারানোর লোকসান বসেনি।');
        $this->assertSame('not_in_service', app(DepreciationEngine::class)->amountFor($stolen->fresh(), '2026-09-30')['reason']);
    }

    public function test_every_money_event_waits_for_its_own_signature(): void
    {
        $category = $this->category();
        $van = $this->depreciatedVan($category);
        $cash = $this->cash();
        app(SettingsService::class)->set(AssetEventService::REVALUATION, true);
        $surplus = $this->account('3410', 'Revaluation surplus', Account::EQUITY, Account::CREDIT);

        foreach ([AccountsSignature::FIXED_ASSET_ADDITION, AccountsSignature::FIXED_ASSET_REPAIR, AccountsSignature::FIXED_ASSET_REVALUE,
            AccountsSignature::FIXED_ASSET_IMPAIR, AccountsSignature::FIXED_ASSET_DISPOSE] as $action) {
            $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => AccountsSignature::MODULE, 'action' => $action, 'is_active' => true]);
            ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id]);
        }

        // ⓘ অন্য কাগজে বসা মেরামতে টাকা নড়ে না — সইও নয়
        $logged = $this->events->repair($van, ['amount' => '900', 'happened_on' => '2026-09-01', 'funded_by' => FixedAssetService::FUNDED_ALREADY]);
        $this->assertSame(AssetEvent::POSTED, $logged->status);

        $steps = [
            [AccountsSignature::FIXED_ASSET_ADDITION, fn () => $this->events->addition($van->fresh(), ['amount' => '6000', 'happened_on' => '2026-09-02',
                'funded_by' => FixedAssetService::FUNDED_MONEY, 'funding_account_id' => $cash])],
            [AccountsSignature::FIXED_ASSET_REPAIR, fn () => $this->events->repair($van->fresh(), ['amount' => '700', 'happened_on' => '2026-09-03',
                'funded_by' => FixedAssetService::FUNDED_MONEY, 'funding_account_id' => $cash,
                'charge_account_id' => (int) Account::query()->postable()->where('code', '5206')->value('id')])],
            [AccountsSignature::FIXED_ASSET_REVALUE, fn () => $this->events->revalue($van->fresh(), ['amount' => '150000', 'happened_on' => '2026-09-04',
                'account_id' => $surplus, 'reason' => 'Valuer'])],
            [AccountsSignature::FIXED_ASSET_IMPAIR, fn () => $this->events->impair($van->fresh(), ['amount' => '90000', 'happened_on' => '2026-09-05',
                'reason' => 'Flood'])],
        ];

        foreach ($steps as [$action, $make]) {
            $event = $make();
            $this->assertTrue($event->isAwaiting(), "⛔ {$action} সই ছাড়াই খাতায় গেল।");
            $this->assertSame(0, LedgerEntry::query()->where('source_type', 'asset_event')->where('source_id', $event->id)->count());

            $approval = app(ApprovalEngine::class)->latestFor($event, $action);
            $this->assertSame(Approval::PENDING, $approval?->status, "⛔ {$action}-এর নিজের সই চাওয়া হয়নি।");
            app(ApprovalEngine::class)->approve($approval, $this->owner);

            $this->assertSame(AssetEvent::POSTED, $event->fresh()->status, "⛔ {$action}-এর শেষ সইয়ে কাগজটা খাতায় বসেনি।");
            $this->assertGreaterThan(0, LedgerEntry::query()->where('source_type', 'asset_event')->where('source_id', $event->id)->count());
        }

        $this->assertSame(0, bccomp($van->fresh()->bookValue(), '90000', 4));

        $after = $this->assets->dispose($van->fresh(), '0', null, '2026-09-10', FixedAsset::LOST, 'Stolen');
        $this->assertTrue($after->isInService(), '⛔ হারানো সই ছাড়াই খাতা থেকে বেরোল।');
        app(ApprovalEngine::class)->approve(app(ApprovalEngine::class)->latestFor($van, AccountsSignature::FIXED_ASSET_DISPOSE), $this->owner);
        $this->assertSame(FixedAsset::LOST, $van->fresh()->status, '⛔ শেষ সইয়ে "হারানো" হিসেবে বেরোয়নি।');
    }

    public function test_the_screens_open_and_save(): void
    {
        $van = $this->depreciatedVan($this->category());

        $this->get(route('accounts.asset.show', $van))->assertOk()
            ->assertSee(__('accounts::asset.event_addition'))->assertSee(__('accounts::asset.leaving_lost'))
            ->assertDontSee(__('accounts::asset.event_revaluation_hint'));

        $this->post(route('accounts.asset.event', [$van, 'addition']), [
            'amount' => '5000', 'happened_on' => '2026-09-01', 'funded_by' => 'money', 'funding_account_id' => $this->cash(),
        ])->assertSessionHasNoErrors();
        $this->post(route('accounts.asset.event', [$van, 'impairment']), ['amount' => '1', 'happened_on' => '2026-09-01'])
            ->assertSessionHasErrors('reason');
        $this->post(route('accounts.asset.transfer', $van), ['moved_on' => '2026-09-02', 'location' => 'Back office'])->assertSessionHasNoErrors();

        $this->get(route('accounts.asset.show', $van))->assertOk()->assertSee('Back office')
            ->assertSee(AssetEvent::query()->where('fixed_asset_id', $van->id)->value('document_no'));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** ⓘ ১,২০,০০০ টাকার ভ্যান, ৬০ মাস, জুলাই আর আগস্টের অবচয় বসা — খাতার দাম ১,১৬,০০০ */
    private function depreciatedVan(?AssetCategory $category = null, string $name = 'Delivery Van'): FixedAsset
    {
        $accounts = $category !== null ? ['category_id' => $category->id] : [
            'asset_account_id' => Account::query()->postable()->where('code', '1202')->value('id'),
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)->id,
            'expense_account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)->id,
        ];

        $van = $this->assets->register([
            ...$accounts, 'name' => $name, 'acquired_on' => '2026-07-01', 'cost' => '120000', 'salvage' => '0',
            'method' => FixedAsset::STRAIGHT_LINE, 'life_months' => 60, 'funded_by' => FixedAssetService::FUNDED_ALREADY,
        ]);
        $this->assets->depreciate($van, '2026-07-01');
        $this->assets->depreciate($van->fresh(), '2026-08-01');

        return $van->fresh();
    }

    private function category(): AssetCategory
    {
        return AssetCategory::query()->where('code', 'VEH')->first() ?? app(AssetCategoryService::class)->create([
            'code' => 'VEH', 'name_en' => 'Vehicles', 'name_bn' => 'যানবাহন',
            'asset_account_id' => Account::query()->postable()->where('code', '1202')->value('id'),
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)->id,
            'expense_account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)->id,
            'gain_account_id' => $this->account('4391', 'Gain on vehicles', Account::INCOME, Account::CREDIT),
            'loss_account_id' => $this->account('5391', 'Loss on vehicles', Account::EXPENSE, Account::DEBIT),
            'impairment_account_id' => $this->account('5392', 'Impairment of vehicles', Account::EXPENSE, Account::DEBIT),
            'method' => FixedAsset::STRAIGHT_LINE, 'life_months' => 60, 'residual_percent' => '0',
        ]);
    }

    private function account(string $code, string $name, string $type, string $nature): int
    {
        return (int) (Account::query()->where('code', $code)->value('id') ?? Account::query()->create([
            'company_id' => CompanyContext::id(), 'code' => $code, 'name_en' => $name, 'name_bn' => $name,
            'parent_id' => null, 'type' => $type, 'nature' => $nature, 'is_group' => false,
            'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ])->id);
    }

    private function cash(): int
    {
        return (int) Account::query()->money()->postable()->orderBy('code')->value('id');
    }

    private function balanceOf(int $accountId): string
    {
        $row = LedgerEntry::query()->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c')->first();

        return bcsub((string) $row->d, (string) $row->c, 4);
    }
}
