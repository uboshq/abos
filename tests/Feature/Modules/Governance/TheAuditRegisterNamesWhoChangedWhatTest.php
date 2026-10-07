<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Governance;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * নিরীক্ষার খাতা — রিপোর্ট সেন্টার ধাপ ৬ ([[AuditReports]])।
 *
 *   বদল ও বাতিল     যিনি বদলালেন তাঁর নামই আসে — কাগজের মালিক বা যিনি দেখছেন তিনি নন
 *   পেছনের তারিখ    তিন দিন পিছিয়ে লেখা অর্ডার — "৩ দিন", লেখকের নাম, টাকা; অন্য শাখার কাগজ এক শাখা বাছলে নেই
 *   মাস বন্ধ-খোলা   তালা বসানো আর তোলা — দুই সারি, একই মানুষের নাম
 * ⓘ দেখার চাবি `governance.audit.view` — একই মানুষ, চাবি ছাড়া ৪০৩, চাবি দিলে খোলে।
 */
final class TheAuditRegisterNamesWhoChangedWhatTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->editor = User::factory()->create(['name' => 'ZQ সম্পাদক', 'current_company_id' => $this->company->id]);
        $this->editor->companies()->attach($this->company->id, ['is_active' => true]);
    }

    public function test_the_change_register_names_the_one_who_changed_not_the_one_who_looks(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();

        $this->actingAs($this->editor);
        $customer->forceFill(['name_en' => 'ZQ Renamed Customer'])->save();

        $this->actingAs($this->owner);
        $row = collect($this->rows('governance.changes'))->first(fn (array $r) => (int) $r['doc_id'] === (int) $customer->id
            && $r['source_type_literal'] === Customer::drillSourceType());

        $this->assertNotNull($row, '⛔ গ্রাহকের নাম বদল খাতায় নেই।');
        $this->assertSame('ZQ সম্পাদক', $row['user_name'], '⛔ যিনি বদলালেন তাঁর নাম আসেনি — অন্য কারও নাম বসেছে।');
    }

    public function test_a_back_dated_order_shows_how_far_back_who_wrote_it_and_the_branch_wall_holds(): void
    {
        $id = $this->backDatedOrder($this->company->defaultBranch()->id);

        $row = collect($this->rows('governance.backdated'))->first(fn (array $r) => (int) $r['doc_id'] === $id);

        $this->assertNotNull($row, '⛔ তিন দিন পিছিয়ে লেখা অর্ডার খাতায় নেই।');
        $this->assertSame('3', (string) $row['days_back']);
        $this->assertSame('ZQ সম্পাদক', $row['user_name'], '⛔ লেখকের নাম আসেনি।');
        $this->assertSame(0, bccomp((string) $row['amount'], '700', 4));

        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('id', '<>', $this->company->defaultBranch()->id)->firstOrFail();
        $there = $this->backDatedOrder($other->id, 'ZQB-2');

        $mine = collect($this->rows('governance.backdated', ['branch_id' => $this->company->defaultBranch()->id]));
        $this->assertNull($mine->first(fn (array $r) => (int) $r['doc_id'] === $there), '⛔ অন্য শাখার কাগজ এই শাখার খাতায়।');
        $this->assertNotNull(collect($this->rows('governance.backdated'))->first(fn (array $r) => (int) $r['doc_id'] === $there));
    }

    public function test_closing_and_reopening_a_month_are_two_rows_with_the_same_name(): void
    {
        $this->actingAs($this->editor);
        $lock = PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => 2025, 'month' => 6,
            'reason' => 'ZQ বছর শেষ', 'locked_by' => $this->editor->id, 'locked_at' => now(),
        ]);
        $lock->delete();

        $this->actingAs($this->owner);
        $rows = collect($this->rows('governance.periods'))->filter(fn (array $r) => (int) $r['doc_id'] === (int) $lock->id);

        $this->assertSame(
            [(string) __('governance::audit_report.month_reopened'), (string) __('governance::audit_report.month_closed')],
            $rows->pluck('lock_label')->values()->all(),
            '⛔ মাস বন্ধ আর আবার খোলা — দুই সারি নয় (নতুনটা আগে)।',
        );
        $this->assertSame(['ZQ সম্পাদক'], $rows->pluck('user_name')->unique()->values()->all());
    }

    public function test_only_the_audit_key_opens_the_registers_same_person_off_then_on(): void
    {
        foreach (['changes', 'backdated', 'periods'] as $slug) {
            $this->actingAs($this->editor)->get(route('governance.report.show', $slug))->assertForbidden();
        }

        $this->editor->givePermissionTo('governance.audit.view');

        foreach (['changes', 'backdated', 'periods'] as $slug) {
            $this->actingAs($this->editor->fresh())->get(route('governance.report.show', $slug))->assertOk();
        }
    }

    private function backDatedOrder(int $branchId, string $no = 'ZQB-1'): int
    {
        return (int) DB::table('sal_orders')->insertGetId([
            'company_id' => $this->company->id,
            'branch_id' => $branchId,
            'document_no' => $no,
            'customer_id' => Customer::query()->orderBy('id')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->subDays(3)->toDateString(),
            'total' => '700',
            'status' => 'confirmed',
            'created_by' => $this->editor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $key, array $extra = []): array
    {
        return app(ReportEngine::class)->run($key, [
            'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString(), ...$extra,
        ], perPage: 500)->rows;
    }
}
