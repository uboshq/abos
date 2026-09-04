<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\MasterData\Models\TransferMode;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * টাকা নেওয়ার উপায় জানে সে কোন ধরনের, আর "কোন মাধ্যমে সরল" তালিকাটা
 * নতুন করে দাঁড়িয়েছে।
 *
 * সবচেয়ে জরুরি টেস্টটা হলো backfill — নতুন `kind` কলামটা চলমান
 * কোম্পানিগুলোর পুরনো সারিতে ঠিকভাবে বসে কি না। মাইগ্রেশন-কালে টেবিল
 * খালি, তাই সেখানে যাচাই করা যায় না; তাই এখানে legacy-আকারের সারি
 * বানিয়ে মাইগ্রেশনের `backfill()` সরাসরি ডেকে তিনটা শাখাই মেলানো হয়।
 */
class PaymentMethodKindAndTransferModeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
    }

    /** seed-এর চার উপায় নিজের ধরন নিয়ে আসে, আর MFS-এর নাম দুই ভাষাতেই "MFS"। */
    public function test_seeded_methods_carry_their_kind_and_mfs_is_renamed(): void
    {
        app(MasterListService::class)->installDefaults();

        $byCode = PaymentMethod::query()->get()->keyBy('code');

        $this->assertSame('cash', $byCode['CASH']->kind);
        $this->assertSame('cheque', $byCode['CHQ']->kind);
        $this->assertSame('bank', $byCode['BANK']->kind);
        $this->assertSame('mfs', $byCode['MFS']->kind);

        // "Mobile banking" নয় — দুই ভাষাতেই MFS
        $this->assertSame('MFS', $byCode['MFS']->name_en);
        $this->assertSame('MFS', $byCode['MFS']->name_bn);
    }

    /** মাধ্যমের প্রমিত তালিকা বসে, আর ছয়টাই ব্যাংকের (`applies_to='bank'`)। */
    public function test_transfer_modes_install_as_bank_channels(): void
    {
        app(MasterListService::class)->installDefaults();

        $modes = TransferMode::query()->get();

        $this->assertEqualsCanonicalizing(
            ['ONLINE', 'NPSB', 'BEFTN', 'RTGS', 'DEPOSIT', 'PO_DD'],
            $modes->pluck('code')->all(),
        );

        // আজকের মাধ্যমগুলো কেবল ব্যাংকের — MFS-এরগুলো কোম্পানি পরে যোগ করে
        foreach ($modes as $mode) {
            $this->assertSame('bank', $mode->applies_to, "{$mode->code} ব্যাংকের হওয়ার কথা।");
        }
    }

    /**
     * ★ backfill — তিন শাখাই, সবচেয়ে নিশ্চিত সংকেত আগে।
     *
     * code=CHQ ব্যাংক-খাত পেলেও cheque; খাত থাকলে তার পূর্বপুরুষ-মা;
     * খাত না থাকলে প্রমিত seed-কোড; কিছুই না মিললে null।
     */
    public function test_the_backfill_derives_kind_across_all_branches(): void
    {
        app(MasterListService::class)->installDefaults();

        $cash = Account::query()->where('code', '1101')->firstOrFail();
        $bank = Account::query()->where('code', '1102')->firstOrFail();
        $mfs = Account::query()->where('code', '1105')->firstOrFail();

        // ব্যাংক-মায়ের নিচে একটা সত্যিকারের হিসাব — বহু-স্তর হাঁটা প্রমাণে
        $bankChild = Account::query()->create([
            'parent_id' => $bank->id,
            'code' => '110299',
            'name_en' => 'Test Bank A/C',
            'type' => $bank->type,
            'nature' => $bank->nature,
            'is_group' => false,
            'is_bank' => true,
        ]);

        // ── legacy-আকারের সারি: kind খালি রেখে (backfill যা ঠিক করবে) ──
        $expectations = [];

        $expectations[$this->legacyMethod('MYCASH', $cash->id)] = 'cash';
        $expectations[$this->legacyMethod('MYBANK', $bank->id)] = 'bank';
        $expectations[$this->legacyMethod('MYMFS', $mfs->id)] = 'mfs';
        $expectations[$this->legacyMethod('MYCHILD', $bankChild->id)] = 'bank';   // বহু-স্তর
        $expectations[$this->legacyMethod('ZZZ', null)] = null;                    // অচেনা, খাতহীন

        // seed করা CHQ-কে ব্যাংক-খাতে বেঁধে দিলাম — তবু cheque থাকার কথা
        $chqId = PaymentMethod::query()->where('code', 'CHQ')->value('id');
        DB::table('mdm_payment_methods')->where('id', $chqId)
            ->update(['account_id' => $bank->id, 'kind' => null]);
        $expectations[$chqId] = 'cheque';

        // seed করা CASH/BANK/MFS-এর খাত খালি — কোড-fallback ধরার কথা
        foreach (['CASH' => 'cash', 'BANK' => 'bank', 'MFS' => 'mfs'] as $code => $kind) {
            $id = PaymentMethod::query()->where('code', $code)->value('id');
            DB::table('mdm_payment_methods')->where('id', $id)->update(['kind' => null]);
            $expectations[$id] = $kind;
        }

        // ── মাইগ্রেশনের backfill() সরাসরি ─────────────────────────────
        $migration = include base_path(
            'app/Modules/MasterData/Database/Migrations/'
            .'2026_10_20_100000_a_payment_method_did_not_know_its_own_kind.php'
        );
        $migration->backfill();

        foreach ($expectations as $id => $expected) {
            $actual = DB::table('mdm_payment_methods')->where('id', $id)->value('kind');
            $this->assertSame($expected, $actual, "method #{$id} ভুল ধরন পেয়েছে।");
        }
    }

    /**
     * ধরন ঐচ্ছিক — খালি রাখলেও উপায় বসে (তখন পর্দা সব টাকার খাত দেখায়);
     * দিলে ধরনটা সংরক্ষিত হয়।
     *
     * ⓘ আগে required ছিল, কিন্তু তাতে কোম্পানি নিজের নতুন উপায় যোগ করতে
     * পারত না (মালিকের নিয়ম: তালিকা ক্রেতা বাড়াবেন) — সুইট ধরিয়ে দিল।
     */
    public function test_a_payment_method_may_be_created_with_or_without_a_kind(): void
    {
        $account = $this->aMoneyAccount();

        // ধরন ছাড়াই বসে — থামে না
        $this->post(route('master_data.payment_method.store'), [
            'name_en' => 'Card Machine',
            'account_id' => $account->id,
        ])->assertRedirect(route('master_data.payment_method.index'));

        $this->assertNull(PaymentMethod::query()->where('name_en', 'Card Machine')->value('kind'));

        // ধরন দিলে সেটাই বসে
        $this->post(route('master_data.payment_method.store'), [
            'name_en' => 'Nagad',
            'kind' => 'mfs',
            'account_id' => $account->id,
        ])->assertRedirect(route('master_data.payment_method.index'));

        $this->assertSame('mfs', PaymentMethod::query()->where('name_en', 'Nagad')->value('kind'));
    }

    /** ধরন খালি রাখলে মাধ্যম "সব ধরনে চলে" — অর্থাৎ `applies_to` null। */
    public function test_a_transfer_mode_may_apply_to_all_kinds(): void
    {
        $this->post(route('master_data.transfer_mode.store'), [
            'name_en' => 'Wallet sweep',
        ])->assertRedirect(route('master_data.transfer_mode.index'));

        $this->assertNull(TransferMode::query()->where('name_en', 'Wallet sweep')->value('applies_to'));
    }

    /** kind খালি রেখে একটা legacy সারি বসায়, id ফেরত দেয়। */
    private function legacyMethod(string $code, ?int $accountId): int
    {
        $method = PaymentMethod::query()->create([
            'code' => $code,
            'name_en' => $code,
            'account_id' => $accountId,
            'kind' => null,
        ]);

        return $method->id;
    }

    /** ফর্মে account_id বাধ্যতামূলক — তাই একটা টাকার খাত দরকার। */
    private function aMoneyAccount(): Account
    {
        $bank = Account::query()->where('code', '1102')->firstOrFail();

        return Account::query()->create([
            'parent_id' => $bank->id,
            'code' => '110288',
            'name_en' => 'Counter Bank A/C',
            'type' => $bank->type,
            'nature' => $bank->nature,
            'is_group' => false,
            'is_bank' => true,
        ]);
    }
}
