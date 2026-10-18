<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Governance;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⚠️ অডিট ট্রেইলের "মডিউল" ছাঁকনি কিছুই মেলাত না — ec, Governance লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * ছাঁকনিটা `LIKE 'App\Modules\Sales\%'` বসাত। MariaDB/MySQL-এ LIKE-এর
 * পালানোর অক্ষর `\`, তাই `\M`→`M`, `\S`→`S`, আর `\%` আক্ষরিক `%` —
 * নকশাটা কেবল `AppModulesSales%` লেখাটাই খুঁজত। মালিক "বিক্রয়" বাছলে
 * হাজার সারি থাকতেও "কিছু পাওয়া যায়নি"।
 *
 * ⓘ পাল্টা দাবিও আছে: অন্য মডিউলের সারি বাদ পড়ে — নাহলে ছাঁকনিটা
 * সবকিছু মেলালেও দাবি সবুজ হত।
 */
final class TheModuleFilterNeverMatchedTest extends TestCase
{
    use RefreshDatabase;

    public function test_picking_a_module_shows_that_modules_rows_only(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        foreach ([SalesInvoice::class => 'MODF-SALES-71', Product::class => 'MODF-STOCK-72'] as $type => $no) {
            AuditTrail::query()->create([
                'company_id' => $company->id,
                'branch_id' => $company->defaultBranch()?->id,
                'user_id' => $owner->id,
                'action' => AuditTrail::UPDATED,
                'auditable_type' => $type,
                'auditable_id' => 999_001,
                'document_no' => $no,
                'label' => 'Probe',
            ]);
        }

        $this->actingAs($owner)
            ->get(route('governance.audit.index', ['module' => 'sales']))
            ->assertOk()
            ->assertSee('MODF-SALES-71')
            ->assertDontSee('MODF-STOCK-72');
    }
}
