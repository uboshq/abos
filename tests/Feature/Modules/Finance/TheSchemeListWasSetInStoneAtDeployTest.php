<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * স্কিমের তালিকাটা ডিপ্লয়ে পাথরে খোদাই হয়ে যেত।
 *
 * ── ⓘ অর্থের মানচিত্র §১৪ক, ২০ সেপ্টেম্বর ২০২৬ ────────────────────────
 * ⛔ ধরনগুলো বসত [[DepositKindInstaller]] দিয়ে, আর তারপর কেউ ছুঁতে পারত
 * না। ⚠️ ব্যাংক প্রতি বছর নতুন স্কিম আনে, নাম আর হার বদলায় — তখন
 * ব্যবহারকারীর হাতে কোনো পথ থাকত না, কেবল ডেভেলপারের।
 *
 * ── ⚠️ আর যেটা এই পর্দার আসল পাহারা ──────────────────────────────────
 * ⛔ ব্যবহৃত ধরন **মোছা যায় না**: মুছলে পুরনো FD-গুলো অনাথ হত, আর "এটা
 * কোন স্কিমের" প্রশ্নের উত্তর চিরতরে হারাত। ⓘ নিষ্ক্রিয় করা যায়, তাতে
 * নতুন কাগজে আর আসে না আর পুরনোগুলো অটুট থাকে।
 */
final class TheSchemeListWasSetInStoneAtDeployTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        // ⓘ ধরনগুলো সিডারে নেই, ডিপ্লয়ের ইনস্টলারে — তাই এখানেই বসাতে হয়
        app(DepositKindInstaller::class)->install();
    }

    /**
     * ⭐ নতুন স্কিম যোগ করা যায়, আর সাথে সাথে জমার ফর্মে আসে।
     */
    public function test_a_new_scheme_can_be_added_from_the_screen(): void
    {
        $this->get(route('finance.deposit_kind.index'))->assertOk()
            ->assertSee(route('finance.deposit_kind.create'), escape: false);

        $this->post(route('finance.deposit_kind.store'), [
            'code' => 'HAJJ',
            'name_en' => 'Hajj Savings',
            'name_bn' => 'হজ সঞ্চয়',
            'issuer' => DepositKind::BANK,
            'shape' => DepositKind::INSTALMENT,
            'is_active' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect(route('finance.deposit_kind.index'));

        $kind = DepositKind::query()->where('code', 'HAJJ')->firstOrFail();

        $this->assertTrue((bool) $kind->is_active);
        $this->assertSame(DepositKind::INSTALMENT, $kind->shape);

        // ⓘ জমার ফর্মে ধরনটা এখন বাছা যায়
        $this->get(route('finance.deposit.create', ['issuer' => 'bank']))
            ->assertOk()
            ->assertSee('হজ সঞ্চয়');
    }

    /**
     * ⛔ একই কোডে দুইটা ধরন নয় — পুরনো কাগজের জোড়া ঘোলাটে হত।
     */
    public function test_the_same_code_is_refused(): void
    {
        $this->post(route('finance.deposit_kind.store'), [
            'code' => 'FDR',
            'name_en' => 'Another FDR',
            'issuer' => DepositKind::BANK,
            'shape' => DepositKind::AT_MATURITY,
        ])->assertSessionHasErrors('code');
    }

    /**
     * ⭐ নাম ও ছাঁদ বদলানো যায় — ব্যাংক নাম বদলালে খাতাও বদলায়।
     */
    public function test_a_scheme_can_be_edited(): void
    {
        $kind = DepositKind::query()->where('code', 'DPS')->firstOrFail();

        $this->put(route('finance.deposit_kind.update', $kind), [
            'code' => 'DPS',
            'name_en' => 'Monthly Savings Plus',
            'name_bn' => 'মাসিক সঞ্চয়ী প্লাস',
            'issuer' => $kind->issuer,
            'shape' => $kind->shape,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Monthly Savings Plus', $kind->fresh()->name_en);
    }

    /**
     * ⛔ যে ধরনে জমা খোলা হয়েছে সেটা মোছা যায় না — কেবল নিষ্ক্রিয়।
     */
    public function test_a_scheme_in_use_cannot_be_removed_only_switched_off(): void
    {
        $kind = DepositKind::query()->where('code', 'FDR')->firstOrFail();

        app(DepositService::class)->open([
            'kind_id' => $kind->id,
            'institution' => 'সোনালী ব্যাংক',
            'held_by' => Deposit::BUSINESS,
            'principal' => '100000',
            'return_word' => 'interest',
            'opened_on' => now()->toDateString(),
            'matures_on' => now()->addYear()->toDateString(),
            'funded_from_account_id' => app(CashTillService::class)->ensurePrimaryTill()->account_id,
        ]);

        $this->delete(route('finance.deposit_kind.destroy', $kind))
            ->assertSessionHasErrors('kind');

        $this->assertNotNull($kind->fresh(), 'ব্যবহৃত ধরনটা মুছে গেছে — পুরনো জমাগুলো অনাথ হত।');

        // ⭐ নিষ্ক্রিয় করা যায়, আর তখন নতুন কাগজে আর আসে না
        $this->post(route('finance.deposit_kind.toggle', $kind))->assertSessionHasNoErrors();

        $this->assertFalse((bool) $kind->fresh()->is_active);

        $this->assertSame(1, Deposit::query()->where('kind_id', $kind->id)->count(),
            'নিষ্ক্রিয় করায় পুরনো জমাটাও হারিয়ে গেছে।');
    }

    /**
     * ⭐ যে ধরনে কিছুই খোলা হয়নি, সেটা মোছা যায়।
     */
    public function test_an_unused_scheme_can_be_removed(): void
    {
        $kind = DepositKind::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'TEMP',
            'name_en' => 'Temporary Scheme',
            'shape' => DepositKind::AT_MATURITY,
            'issuer' => DepositKind::BANK,
            'is_active' => true,
        ]);

        $this->delete(route('finance.deposit_kind.destroy', $kind))->assertSessionHasNoErrors();

        $this->assertNull(DepositKind::query()->find($kind->id));
    }
}
