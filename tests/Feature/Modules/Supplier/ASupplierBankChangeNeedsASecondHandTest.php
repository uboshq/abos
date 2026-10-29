<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierBankDetail;
use App\Modules\Supplier\Services\SupplierBank;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ নকশা দফা #৬ — সরবরাহকারীর ব্যাংক তথ্য বদলাতে দ্বিতীয় একজনের সই লাগে (মালিক অনুমোদিত, ২১ অক্টোবর ২০২৬; SOX দ্বৈত নিয়ন্ত্রণ)।
 *
 * ⛔ বিপজ্জনক মানুষ: কেরানি, যিনি সরবরাহকারী বদলাতে পারেন **আর** সইয়ের চাবিও ধরেন — নিজের চাওয়া বদলে নিজে সই দিলে
 * পরের ব্যাংক পরিশোধ তাঁর পছন্দের হিসাবে যেত। মালিকও নিজের চাওয়ায় সই দেন না।
 *
 * দাবিগুলো HTTP দরজা দিয়ে ([[SupplierBankController]] → [[SupplierBank]]):
 *   - চাওয়া = `pending` সারি; চালু তথ্য বদলায় না; কেবল pending থাকলে `signedFor()` null, আর ব্যাংক পরিশোধ আটকায়
 *   - অন্য একজন সই দিলে নতুনটা চালু, পুরনোটা superseded; সই পড়ার আগ পর্যন্ত পুরনোটাই চালু
 *   - যিনি চেয়েছেন তিনি সই দিতে পারেন না — চাবি থাকলেও, মালিক হলেও
 *   - "না" দিলে পুরনোটাই চালু
 *   - অন্য কোম্পানি সারিটা দেখে না, দরজায় ৪০৪
 *   - `supplier.bank.view` ছাড়া নম্বর ••••শেষ চার; চাবি থাকলে পুরো
 *   - `x-supplier::payee-bank` দুই অবস্থায়
 */
final class ASupplierBankChangeNeedsASecondHandTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Supplier $supplier;

    private User $owner;

    private User $clerk;

    private User $signer;

    private User $looker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->supplier = app(SupplierService::class)->create([
            'name_en' => 'Bank Mill', 'branch_id' => $this->company->defaultBranch()?->id, 'credit_limit' => 0, 'credit_days' => 0,
        ]);

        // ⛔ কেরানির হাতে সইয়ের চাবিও — নিয়মটা চাবি নয়, "অন্য একজন"
        $this->clerk = $this->person(['supplier.view', 'supplier.update', 'supplier.bank.approve', 'supplier.bank.view']);
        $this->signer = $this->person(['supplier.view', 'supplier.bank.approve', 'supplier.bank.view']);
        $this->looker = $this->person(['supplier.view']);
    }

    public function test_a_request_waits_and_leaves_the_active_bank_alone_until_another_person_signs(): void
    {
        $this->signedAccount('1111222233334444');

        $this->actingAs($this->clerk)
            ->post(route('supplier.bank.store', $this->supplier), $this->form('9999888877774521'))
            ->assertRedirect(route('supplier.show', $this->supplier))
            ->assertSessionHasNoErrors();

        $pending = SupplierBankDetail::query()->where('supplier_id', $this->supplier->id)->where('status', 'pending')->first();
        $this->assertNotNull($pending, '⛔ চাওয়াটা অপেক্ষার সারি হয়নি।');
        $this->assertSame('4521', $pending->account_last4);

        // ⓘ পুরনোটাই চালু — সই পড়ার আগে কিছু বদলায় না
        $this->assertSame('1111222233334444', app(SupplierBank::class)->signedFor($this->supplier)?->account_no,
            '⛔ সই ছাড়াই চালু ব্যাংক তথ্য বদলে গেল।');

        // ⓘ পাতায় "সইয়ের অপেক্ষায়"
        $this->actingAs($this->signer)->get(route('supplier.show', $this->supplier))
            ->assertOk()->assertSee('data-bank-pending', false)->assertSee('data-bank-sign', false);

        $this->actingAs($this->signer)
            ->post(route('supplier.bank.sign', [$this->supplier, $pending]))
            ->assertSessionHasNoErrors();

        $active = app(SupplierBank::class)->signedFor($this->supplier);
        $this->assertSame($pending->id, $active?->id, '⛔ দ্বিতীয় জনের সইয়ের পরেও নতুন তথ্য চালু হয়নি।');
        $this->assertSame('9999888877774521', $active->account_no);
        $this->assertSame($this->signer->id, (int) $active->signed_by);
        $this->assertSame(1, SupplierBankDetail::query()->where('supplier_id', $this->supplier->id)->where('status', 'signed')->count(),
            '⛔ একই সরবরাহকারীর দুইটা চালু ব্যাংক তথ্য।');
        $this->assertSame(1, SupplierBankDetail::query()->where('supplier_id', $this->supplier->id)->where('status', 'superseded')->count());

        // ⓘ নিরীক্ষায় নামসহ কাজ, আর নম্বর খোলা লেখা নেই
        $this->assertTrue(AuditTrail::query()->where('auditable_type', SupplierBankDetail::class)
            ->where('auditable_id', $pending->id)->where('action', 'bank_signed')->exists(), '⛔ সইটা নিরীক্ষায় নেই।');
        $this->assertFalse(DB::table('sup_bank_details')->where('account_no', '9999888877774521')->exists(),
            '⛔ হিসাব নম্বর টেবিলে খোলা লেখা।');
    }

    public function test_the_maker_cannot_sign_their_own_request_even_with_the_key_nor_can_the_owner(): void
    {
        $this->actingAs($this->clerk)->post(route('supplier.bank.store', $this->supplier), $this->form('5555666677778888'));
        $mine = $this->pending();

        $this->actingAs($this->clerk)
            ->post(route('supplier.bank.sign', [$this->supplier, $mine]));
        // ⓘ থামার কারণটাই দাবি — "চাবি নেই" দিয়ে থামলেও থামা, কিন্তু নিয়মটা (যিনি চাইলেন তিনি নন) তখন কেউ দেখছে না
        $this->assertSame(__('supplier::bank.maker_cannot_sign'), $this->bankError(), '⛔ যিনি চাইলেন তাঁর নিজের সই "নিজের চাওয়ায় নয়" বলে থামল না।');
        $this->assertSame('pending', $mine->fresh()->status, '⛔ যিনি চাইলেন তিনিই সই দিলেন।');
        $this->assertNull(app(SupplierBank::class)->signedFor($this->supplier));

        // ⓘ পাতায় সইয়ের বোতাম নেই, ফিরিয়ে নেওয়ার আছে
        $this->actingAs($this->clerk)->get(route('supplier.show', $this->supplier))
            ->assertOk()->assertDontSee('data-bank-sign', false)->assertSee('data-bank-withdraw', false);

        // ⛔ মালিকও নিজের চাওয়ায় নন
        $this->actingAs($this->owner)->post(route('supplier.bank.store', $this->supplier), $this->form('1212343456567878'));
        $owners = $this->pending();
        $this->assertSame($this->owner->id, (int) $owners->requested_by);

        $this->flushSession();
        $this->actingAs($this->owner)
            ->post(route('supplier.bank.sign', [$this->supplier, $owners]));
        $this->assertSame(__('supplier::bank.maker_cannot_sign'), $this->bankError(), '⛔ মালিকের নিজের চাওয়ায় সই "নিজের চাওয়ায় নয়" বলে থামল না।');
        $this->assertSame('pending', $owners->fresh()->status, '⛔ মালিক নিজের চাওয়া বদলে নিজে সই দিলেন।');

        // ⓘ চাবিহীন মানুষও নন
        $this->actingAs($this->looker)
            ->post(route('supplier.bank.sign', [$this->supplier, $owners]))
            ->assertSessionHasErrors('bank');
        $this->assertSame('pending', $owners->fresh()->status);

        // ⭐ অন্য একজন — চলে (দাবিটা সত্যিই তাকায়)
        $this->actingAs($this->signer)->post(route('supplier.bank.sign', [$this->supplier, $owners]))->assertSessionHasNoErrors();
        $this->assertSame('signed', $owners->fresh()->status);
    }

    public function test_a_refusal_keeps_the_old_details_and_only_pending_is_never_signed(): void
    {
        $this->actingAs($this->clerk)->post(route('supplier.bank.store', $this->supplier), $this->form('3333444455556666'));

        // ⛔ কেবল pending — চালু কিছু নেই, আর ব্যাংক পরিশোধ আটকায়
        $this->assertNull(app(SupplierBank::class)->signedFor($this->supplier), '⛔ সই-না-পাওয়া তথ্য চালু বলে ফিরল।');
        try {
            app(SupplierBank::class)->assertBankPayable($this->supplier);
            $this->fail('⛔ সই-না-পাওয়া তথ্যে ব্যাংক পরিশোধ আটকাল না।');
        } catch (ValidationException $e) {
            $this->assertSame([__('supplier::bank.unsigned_blocks_payment')], $e->errors()['payee_bank'] ?? null);
        }

        // ⓘ চালু একটা তথ্য (আগের অপেক্ষারটা এতে "replaced"), তারপর নতুন একটা চাওয়া
        $old = $this->signedAccount('1111222233334444');
        $this->actingAs($this->clerk)->post(route('supplier.bank.store', $this->supplier), $this->form('3333444455556666'));
        $waiting = $this->pending();

        $this->actingAs($this->signer)
            ->post(route('supplier.bank.reject', [$this->supplier, $waiting]), ['note' => 'Not on the supplier letterhead'])
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', $waiting->fresh()->status);
        $this->assertSame($old->id, app(SupplierBank::class)->signedFor($this->supplier)?->id, '⛔ "না"-র পরে পুরনো তথ্য হারাল।');
        $this->assertSame($old->id, app(SupplierBank::class)->assertBankPayable($this->supplier)->id);
    }

    public function test_another_company_never_sees_it(): void
    {
        $this->actingAs($this->clerk)->post(route('supplier.bank.store', $this->supplier), $this->form('7777000011112222'));
        $row = $this->pending();

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::forCompany($other->id, function () use ($row): void {
            $this->assertSame(0, SupplierBankDetail::query()->count(), '⛔ অন্য কোম্পানি ব্যাংক তথ্যের সারি দেখল।');
            $this->assertNull(SupplierBankDetail::query()->find($row->id));
        });

        $stranger = User::factory()->create(['current_company_id' => $other->id, 'is_active' => true]);
        $stranger->companies()->attach($other->id, ['is_active' => true]);
        CompanyContext::forCompany($other->id, function () use ($stranger): void {
            foreach (['supplier.view', 'supplier.bank.approve'] as $key) {
                $stranger->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($stranger->fresh())->post(route('supplier.bank.sign', [$this->supplier, $row]))->assertNotFound();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->assertSame('pending', SupplierBankDetail::query()->withoutGlobalScopes()->find($row->id)?->status,
            '⛔ অন্য কোম্পানির মানুষ সই দিলেন।');
    }

    public function test_the_account_is_masked_without_the_bank_view_key(): void
    {
        $this->signedAccount('1234567890124521');

        $this->actingAs($this->looker)->get(route('supplier.show', $this->supplier))
            ->assertOk()
            ->assertSee('••••4521')
            ->assertDontSee('1234567890124521')
            ->assertDontSee('data-bank-request-form', false);

        $this->actingAs($this->signer)->get(route('supplier.show', $this->supplier))
            ->assertOk()
            ->assertSee('1234567890124521');
    }

    public function test_the_payee_bank_component_shows_only_signed_details_masked(): void
    {
        $render = fn () => Blade::render('<x-supplier::payee-bank :supplier="$s" />', ['s' => $this->supplier->fresh()]);

        // ⓘ কিছুই নেই
        $none = $render();
        $this->assertStringContainsString('data-payee-bank-signed="0"', $none);
        $this->assertStringContainsString('data-payee-bank-state="none"', $none);
        $this->assertStringContainsString(e(__('supplier::bank.unsigned_blocks_payment')), $none);

        // ⓘ কেবল অপেক্ষায় — তবু সই নেই
        app(SupplierBank::class)->request($this->supplier, $this->form('4444333322221111'), $this->clerk);
        $pending = $render();
        $this->assertStringContainsString('data-payee-bank-signed="0"', $pending);
        $this->assertStringContainsString('data-payee-bank-state="pending"', $pending);
        $this->assertStringNotContainsString('••••1111', $pending, '⛔ সই-না-পাওয়া নম্বর পরিশোধের পর্দায়।');

        // ⭐ সই-পাওয়া — ••••শেষ চার, কখনো পুরো নয়
        app(SupplierBank::class)->sign($this->pending(), $this->signer);
        $signed = $render();
        $this->assertStringContainsString('data-payee-bank-signed="1"', $signed);
        $this->assertStringContainsString('••••1111', $signed);
        $this->assertStringNotContainsString('4444333322221111', $signed);
        $this->assertStringContainsString('Dhaka Main', $signed);
        $this->assertStringContainsString('123456789', $signed);
        $this->assertStringContainsString(e(__('supplier::bank.verified')), $signed);
    }

    /** একটা চালু, সই-পাওয়া তথ্য — কেরানি চান, সইকারী সই দেন (সেবা দিয়ে) */
    private function signedAccount(string $number): SupplierBankDetail
    {
        $bank = app(SupplierBank::class);
        $detail = $bank->request($this->supplier, $this->form($number), $this->clerk);
        // ⛔ নতুন চাওয়া দ্বিতীয় সইয়ের অপেক্ষায় জন্মায় — আগেই "সই-পাওয়া" হয়ে জন্মালে দ্বিতীয় হাতের নিয়মটাই নেই
        $this->assertTrue($detail->fresh()->isPending(), '⛔ ব্যাংক তথ্যের নতুন চাওয়া অপেক্ষায় না থেকে সরাসরি চালু হয়ে জন্মাল।');

        return $bank->sign($detail, $this->signer);
    }

    /** ⓘ সেশনের `bank` ভুলের প্রথম লেখা — থলেটা পরীক্ষায় কখনো ViewErrorBag, কখনো JSON থেকে ফেরা array */
    private function bankError(): string
    {
        $errors = session('errors');

        if ($errors instanceof \Illuminate\Support\ViewErrorBag) {
            return (string) $errors->first('bank');
        }

        return (string) ($errors['default']['messages']['bank'][0] ?? '');
    }

    private function pending(): SupplierBankDetail
    {
        return SupplierBankDetail::query()->where('supplier_id', $this->supplier->id)->where('status', 'pending')->latest('id')->firstOrFail();
    }

    /** @return array<string, string> */
    private function form(string $number): array
    {
        return [
            'bank_name' => 'Sonali Bank', 'branch_name' => 'Dhaka Main', 'account_name' => 'Bank Mill Ltd',
            'account_no' => $number, 'routing_no' => '123456789', 'reason' => 'New account letter',
        ];
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create([
            'current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id, 'is_active' => true,
        ]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
