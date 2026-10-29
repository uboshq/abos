<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Supplier\Services\SupplierBank;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ নকশা দফা #৬ — অপেক্ষার ব্যাংক তথ্যের বদল সইকারী খুঁজে পান: অনুমোদনের বাক্সে আর ঘণ্টায় (ছকের বাইরে, [[SignaturesWaitingElsewhere]])।
 *
 * ⛔ সই দরকার অথচ কেউ জানেন না — তাহলে বদলটা চিরকাল ঝুলত, আর মানুষ শেষে নিয়মটাই বন্ধ করতে বলতেন।
 * দাবি: সইকারী (কেবল `supplier.bank.approve`, `approval.decide` ছাড়া) বাক্স খোলেন আর সারিটা দেখেন, লিংক ব্যাংক কার্ডে;
 * ঘণ্টায় খবর পান। যিনি চাইলেন (চাবি থাকলেও) — বাক্সে নেই, খবরও নেই। অন্য কোম্পানির চাবিধারী — কোথাও নেই।
 */
final class ASupplierBankChangeReachesItsSignersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_signer_finds_it_in_the_inbox_and_the_bell_and_the_requester_and_another_company_do_not(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $supplier = app(SupplierService::class)->create([
            'name_en' => 'Signal Mill', 'branch_id' => $company->defaultBranch()?->id, 'credit_limit' => 0, 'credit_days' => 0,
        ]);

        $clerk = $this->person($company, ['supplier.view', 'supplier.update', 'supplier.bank.approve']);
        $signer = $this->person($company, ['supplier.view', 'supplier.bank.approve']);
        $looker = $this->person($company, ['supplier.view']);
        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        $stranger = $this->person($other, ['supplier.view', 'supplier.bank.approve']);
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs($clerk)->post(route('supplier.bank.store', $supplier), [
            'bank_name' => 'Sonali Bank', 'branch_name' => 'Dhaka Main', 'account_name' => 'Signal Mill Ltd',
            'account_no' => '1234567890124521', 'routing_no' => '123456789',
        ])->assertSessionHasNoErrors();

        // ⭐ সইকারী — বাক্সে, লিংক ব্যাংক কার্ডে, নম্বর ঢাকা
        $card = app(SupplierBank::class)->cardUrl($supplier);
        $this->actingAs($signer)->get(route('approval.inbox.index'))
            ->assertOk()
            ->assertSee('data-signatures-elsewhere', false)
            ->assertSee('Signal Mill')
            ->assertSee('••••4521')
            ->assertDontSee('1234567890124521')
            ->assertSee(e($card), false);
        $this->assertStringContainsString('bank=1', $card);
        $this->assertStringContainsString('#supplier-bank', $card);

        // ⭐ ঘণ্টায়
        $this->assertSame(1, $this->bells($signer), '⛔ সইকারী ঘণ্টায় খবর পাননি।');
        $bell = Notification::query()->withoutGlobalScopes()->where('user_id', $signer->id)->where('type', SupplierBank::WAITING)->first();
        $this->assertSame($card, $bell?->url);
        $this->assertStringNotContainsString('1234567890124521', (string) $bell?->body, '⛔ খবরে পুরো হিসাব নম্বর।');

        // ⛔ যিনি চাইলেন — চাবি থাকলেও বাক্সে নেই, খবরও নেই
        $this->actingAs($clerk)->get(route('approval.inbox.index'))->assertForbidden();
        $this->assertSame(0, $this->bells($clerk), '⛔ যিনি চাইলেন তিনি নিজের বদলে সইয়ের খবর পেলেন।');
        $this->assertSame([], app(\App\Core\Contracts\SignaturesWaitingElsewhere::class)->waitingFor($clerk));

        // ⛔ চাবিহীন সহকর্মী — খবর নেই, বাক্সে নেই
        $this->assertSame(0, $this->bells($looker), '⛔ সইয়ের চাবি ছাড়া মানুষ সইয়ের খবর পেলেন।');
        $this->assertSame([], app(\App\Core\Contracts\SignaturesWaitingElsewhere::class)->waitingFor($looker));

        // ⛔ অন্য কোম্পানি — কোথাও নেই
        $this->assertSame(0, $this->bells($stranger), '⛔ অন্য কোম্পানির মানুষ খবর পেলেন।');
        $this->actingAs($stranger)->get(route('approval.inbox.index'))->assertForbidden();
        CompanyContext::forCompany($other->id, function () use ($stranger): void {
            $this->assertSame([], app(\App\Core\Contracts\SignaturesWaitingElsewhere::class)->waitingFor($stranger),
                '⛔ অন্য কোম্পানির বাক্সে এই কোম্পানির বদল।');
        });

        // ⓘ সই পড়লে বাক্স থেকে চলে যায়
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($signer)->post(route('supplier.bank.sign', [$supplier, app(SupplierBank::class)->pendingFor($supplier)]))
            ->assertSessionHasNoErrors();
        $this->assertSame([], app(\App\Core\Contracts\SignaturesWaitingElsewhere::class)->waitingFor($signer));
    }

    private function bells(User $user): int
    {
        return Notification::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('type', SupplierBank::WAITING)->count();
    }

    /** @param  list<string>  $keys */
    private function person(Company $company, array $keys): User
    {
        $user = User::factory()->create([
            'current_company_id' => $company->id, 'current_branch_id' => $company->defaultBranch()?->id, 'is_active' => true,
        ]);
        $user->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
