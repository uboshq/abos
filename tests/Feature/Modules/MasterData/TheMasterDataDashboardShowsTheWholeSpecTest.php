<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Engines\Dashboard\Listing;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Backup\Models\BackupDestination;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Dashboard\MasterDataDashboard;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মাস্টার ডেটার ড্যাশবোর্ড — নকশার বাকিটা (৬ অক্টোবর ২০২৬): সদ্য বদলানো মাস্টার রেকর্ড, নিরীক্ষার খাতা থেকে।
 *
 * ⓘ এই মডিউলের নিজের তালিকা (একক) আর অন্য মডিউলের মাস্টার তালিকা (গ্রাহক) — দুইটাই আসে, নতুনটা আগে।
 * ⛔ মাস্টার নয় এমন রেকর্ডের দাগ (ব্যাকআপের গন্তব্য) আসে না, যদিও সেটাই সবচেয়ে নতুন।
 * ⛔ অন্য কোম্পানির দাগ আসে না। ⛔ নিরীক্ষার চাবি ছাড়া তালিকা নেই। ⛔ সুইচ বন্ধে তালিকা নেই।
 * ⓘ "আজ আর এ মাসে কত নতুন" আলাদা দাবিতে — [[TheMasterDataDashboardCountsWhatOpenedTodayTest]]।
 */
final class TheMasterDataDashboardShowsTheWholeSpecTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_latest_master_changes_come_from_the_trail_and_nothing_else(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        config(['abos.dashboards_v2' => false]);
        $this->assertSame([], MasterDataDashboard::dashboard()->listings, '⛔ সুইচ বন্ধ, তবু তালিকা।');

        config(['abos.dashboards_v2' => true]);
        $panelsBefore = array_map(fn ($p) => $p->label, MasterDataDashboard::dashboard()->panels);

        $unit = Unit::query()->create(['company_id' => $company->id, 'code' => 'DSU', 'name_en' => 'Dash unit', 'name_bn' => 'ড্যাশ একক', 'is_active' => true]);
        $customer = Customer::query()->where('is_active', true)->firstOrFail();
        $customer->update(['name_en' => $customer->name_en.' (renamed)']);

        // ⛔ মাস্টার নয় — আর সবচেয়ে নতুন
        BackupDestination::query()->create(['company_id' => $company->id, 'name' => 'Dash pen drive', 'driver' => 'local', 'kind' => 'secondary', 'is_active' => true]);

        $list = $this->listing();
        $this->assertInstanceOf(Listing::class, $list, 'সদ্য বদলানো মাস্টার রেকর্ডের তালিকা নেই।');
        $this->assertLessThanOrEqual(10, $list->rows->count());

        $types = $list->rows->pluck('auditable_type')->all();
        $this->assertNotContains(BackupDestination::class, $types, '⛔ মাস্টার নয় এমন রেকর্ড তালিকায়।');
        $this->assertSame(Customer::class, $types[0], 'সবশেষ মাস্টার বদল (গ্রাহক) প্রথমে নয়।');
        $this->assertSame((int) $customer->id, (int) $list->rows[0]->auditable_id);
        $this->assertTrue($list->rows->contains(fn (AuditTrail $t) => $t->auditable_type === Unit::class && (int) $t->auditable_id === (int) $unit->id),
            'এই মডিউলের নিজের তালিকার বদল (একক) আসেনি।');
        $this->assertTrue($list->rows->every(fn (AuditTrail $t) => (int) $t->company_id === (int) $company->id), '⛔ অন্য কোম্পানির দাগ।');

        // ⓘ ঘরগুলো পড়ার মতো — কাজের নাম শব্দে, চাবি নয়
        $what = collect($list->columns)->firstWhere('key', 'what');
        $this->assertSame(AuditTrail::actionInWords(AuditTrail::UPDATED), ($what['render'])($list->rows[0]));

        // ⭐ প্রথম চার্ট আগের জায়গাতেই, আর চার্টের তালিকা অক্ষত
        $this->assertSame(__('master_data::dashboard.how_full'), MasterDataDashboard::dashboard()->panels[0]->label);
        $this->assertSame($panelsBefore, array_map(fn ($p) => $p->label, MasterDataDashboard::dashboard()->panels));

        // ⛔ নিরীক্ষার চাবি ছাড়া মানুষ — তালিকা নেই
        $nobody = User::factory()->create(['current_company_id' => $company->id]);
        $nobody->companies()->attach($company->id);
        $this->actingAs($nobody);
        $this->assertNull($this->listing(), '⛔ নিরীক্ষার চাবি ছাড়াই কে কী বদলাল দেখা গেছে।');
    }

    private function listing(): ?Listing
    {
        return collect(MasterDataDashboard::dashboard()->listings)->firstWhere('label', __('master_data::dashboard.recently_changed'));
    }
}
