<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\SerialNumberService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ ফেরত আসা সিরিয়াল-পিস সিদ্ধান্তের আগেই আবার বেচা যেত (পুরো-ERP অডিট, মজুদ ছ৩; fe-র সিদ্ধান্ত (ক), ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ `returned` মানে "ফেরত এসেছে, সিদ্ধান্ত বাকি" ([[SerialNumber]]) — খোলা বাক্সের টিভি কেউ না দেখেই নতুনের দামে যেত, কারণ বেচার
 * পাহারা কেবল `sold` আটকাত। এখন কেবল `in_stock` বেরোয়; ফেরত পিসের সিদ্ধান্ত একটা দরজায় ([[SerialNumberService::backToStock()]]),
 * মজুদ-সমন্বয়ের চাবিতে, অডিটে লেখা। ⛔ সিরিয়ালে কেবল কোম্পানির দেয়াল, তাই দরজাটা নিজে শাখা আর গুদাম দেখে।
 */
final class AReturnedPieceWaitsForItsDecisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_returned_piece_waits_for_a_keyed_decision_before_it_sells_again(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $mms = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($company->id, $mms->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $here = Warehouse::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'branch_id' => $mms->id, 'code' => 'SR-MMS',
            'name_en' => 'Serial store', 'is_active' => true]);
        $there = Warehouse::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'branch_id' => $ntk->id, 'code' => 'SR-NTK',
            'name_en' => 'Serial store far', 'is_active' => true]);
        $product = Product::query()->create(['code' => 'SR-'.mb_substr(md5(microtime()), 0, 6), 'name_en' => 'Serial probe',
            'name_bn' => 'সিরিয়ালের নমুনা', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true]);
        $piece = fn (string $no, string $status, Warehouse $w) => SerialNumber::query()->create(['company_id' => $company->id,
            'product_id' => $product->id, 'serial_no' => $no, 'warehouse_id' => $w->id, 'received_on' => now()->toDateString(), 'status' => $status]);
        $sold = $piece('SR-SOLD', SerialNumber::SOLD, $here);
        $back = $piece('SR-BACK', SerialNumber::RETURNED, $here);
        $far = $piece('SR-FAR', SerialNumber::RETURNED, $there);

        // ⛔ বেচা আর ফেরত — দুটোই আবার বেরোয় না
        foreach (['SR-SOLD' => 'serial_already_out', 'SR-BACK' => 'serial_waiting_decision'] as $no => $why) {
            try {
                app(SerialNumberService::class)->issue([$no], ['sold_to' => 'X']);
                $this->fail("⛔ {$no} আবার বেরোল।");
            } catch (ValidationException $e) {
                $this->assertContains(__('inventory::validation.'.$why, ['no' => $no]), $e->validator->errors()->all());
            }
        }

        // ⓘ একই মানুষ — আগে চাবি ছাড়া, পরে চাবি নিয়ে; ময়মনসিংহে সীমিত
        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mms->id]);
        CompanyContext::forCompany($company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('inventory.serial.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();
        $this->actingAs($clerk->fresh());

        $this->post(route('inventory.serial.back_to_stock', $back))->assertForbidden();
        $this->assertSame(SerialNumber::RETURNED, $back->fresh()->status, '⛔ চাবি ছাড়া পিস গুদামে ফিরল।');

        CompanyContext::forCompany($company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('inventory.stock.adjust', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($clerk->fresh());

        $this->post(route('inventory.serial.back_to_stock', $back))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(SerialNumber::IN_STOCK, $back->fresh()->status, '⛔ চাবি নিয়েও পিস গুদামে ফিরল না।');
        $this->assertSame(1, \App\Models\AuditTrail::query()->where('action', 'serial_back_to_stock')->where('auditable_id', $back->id)->count(),
            '⛔ কে গুদামে ফেরত নিলেন, অডিটে নেই।');

        // ⛔ অন্য শাখার পিস — খোলে না
        $this->post(route('inventory.serial.back_to_stock', $far))->assertNotFound();
        $this->assertSame(SerialNumber::RETURNED, $far->fresh()->status);

        // ⓘ সিদ্ধান্তের পরে বেচা যায়
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $out = app(SerialNumberService::class)->issue(['SR-BACK'], ['sold_to' => 'Y']);
        $this->assertSame(SerialNumber::SOLD, $out[0]->status, '⛔ গুদামে ফেরা পিস বেচা গেল না।');
        $this->assertSame(SerialNumber::SOLD, $sold->fresh()->status);
    }
}
