<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AssetTransfer;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ফ্রিজটা অন্য শাখায় চলে গেল, আর খাতা কিছুই জানল না — মানচিত্র §১৫।
 *
 * ── ⚠️ কেন কেবল একটা কলাম বদলানো যথেষ্ট নয় ──────────────────────────
 * জিনিসটা ঢাকা থেকে খুলনায় গেলে **দুইটা শাখার স্থিতিপত্রই** বদলায়।
 * ⓘ কেবল `branch_id` বদলালে খতিয়ানের পুরনো সারিগুলো ঢাকার নামেই পড়ে
 * থাকত, আর শাখা ধরে হিসাব চাইলে দুইটাই ভুল আসত।
 *
 * ⛔ আর এই ফাইলের শেষ পরীক্ষাটা একটা **পুরনো ফাঁদের** পাহারা: সম্পদের
 * বিদায় একবার ঠিক এই কারণে ভেঙেছিল — নিবন্ধন আর বিদায় এক চাবিতে বসত,
 * আর পোস্টিং ইঞ্জিন দ্বিতীয়টা আটকে দিত।
 */
final class TheFridgeMovedBranchAndTheBooksNeverKnewTest extends TestCase
{
    use RefreshDatabase;

    private FixedAsset $fridge;

    private Branch $dhaka;

    private Branch $khulna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $this->dhaka = $company->defaultBranch() ?? Branch::query()->firstOrFail();

        CompanyContext::set($company->id, $this->dhaka->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->khulna = Branch::query()->create([
            'company_id' => $company->id,
            'code' => 'KHL',
            'name_en' => 'Khulna',
            'name_bn' => 'খুলনা',
            'is_active' => true,
        ]);

        $this->fridge = app(FixedAssetService::class)->register([
            'name' => 'ডিপ ফ্রিজ',
            'asset_account_id' => $this->accountId(StandardChart::FIXED_ASSETS, group: true),
            'accumulated_account_id' => $this->accountId(StandardChart::ACCUMULATED_DEPRECIATION),
            'expense_account_id' => $this->accountId(StandardChart::DEPRECIATION_EXPENSE),
            'cost' => '60000',
            'salvage' => 0,
            'acquired_on' => now()->subYear()->toDateString(),
            'method' => FixedAsset::STRAIGHT_LINE,
            'life_months' => 60,
            'funded_by' => FixedAssetService::FUNDED_MONEY,
            'funding_account_id' => Account::query()->money()->postable()->active()->firstOrFail()->id,
        ]);
    }

    /**
     * ⭐ সরানোর পর দুই শাখার খাতাতেই সেটা বসে।
     */
    public function test_moving_it_moves_the_money_between_the_branches(): void
    {
        app(FixedAssetService::class)->transfer($this->fridge, (int) $this->khulna->id, now()->toDateString());

        $rows = LedgerEntry::query()
            ->where('source_type', AssetTransfer::drillSourceType())
            ->get();

        $this->assertCount(2, $rows, 'দুইটা সারি বসার কথা — এক শাখা থেকে যায়, অন্যটায় আসে।');

        $out = $rows->firstWhere('branch_id', $this->dhaka->id);
        $in = $rows->firstWhere('branch_id', $this->khulna->id);

        $this->assertNotNull($out, 'পুরনো শাখার সারিটাই নেই।');
        $this->assertNotNull($in, 'নতুন শাখার সারিটাই নেই।');

        $this->assertSame('60000.0000', $out->credit, 'পুরনো শাখা থেকে পুরো দামটা বেরোয়নি।');
        $this->assertSame('60000.0000', $in->debit, 'নতুন শাখায় পুরো দামটা ঢোকেনি।');

        // ⭐ আর সম্পদটা নিজেও এখন খুলনার
        $this->assertSame((int) $this->khulna->id, (int) $this->fridge->fresh()->branch_id);
    }

    /**
     * ⛔ একই সম্পদ দুইবার সরানো যায় — আর এটাই আসল ফাঁদটা।
     *
     * ── ⚠️ কেন এই পরীক্ষাটা জরুরি ───────────────────────────────────
     * পোস্টিং ইঞ্জিন এক `(উৎস, আইডি)` জোড়ায় **একবারই** পোস্ট করতে দেয়।
     * ⓘ চাবিটা সম্পদের আইডিতে বসালে দ্বিতীয় স্থানান্তরটা নীরবে আটকে
     * যেত — ঠিক যেভাবে সম্পদের **বিদায়** একবার ভেঙেছিল, আর ফলটা ছিল
     * নীরব: টাকা খাতায় উঠত না।
     *
     * ⭐ প্রতিটা স্থানান্তরের নিজের সারি, তাই নিজের আইডি, তাই নিজের চাবি।
     */
    public function test_the_same_asset_can_move_twice(): void
    {
        $assets = app(FixedAssetService::class);

        $assets->transfer($this->fridge, (int) $this->khulna->id, now()->subDays(10)->toDateString());
        $assets->transfer($this->fridge->fresh(), (int) $this->dhaka->id, now()->toDateString());

        $this->assertSame(2, AssetTransfer::query()->count(), 'দ্বিতীয় স্থানান্তরটা বসেনি।');

        $this->assertSame(4, LedgerEntry::query()
            ->where('source_type', AssetTransfer::drillSourceType())->count(),
            'দ্বিতীয় স্থানান্তরের দাখিলা খাতায় ওঠেনি — নীরবে আটকে গেছে।');

        $this->assertSame((int) $this->dhaka->id, (int) $this->fridge->fresh()->branch_id);
    }

    /**
     * ⛔ যেখানে আছে সেখানেই পাঠানো যায় না।
     */
    public function test_it_cannot_be_sent_where_it_already_is(): void
    {
        $this->expectException(ValidationException::class);

        app(FixedAssetService::class)->transfer($this->fridge, (int) $this->dhaka->id);
    }

    /**
     * ⭐ পর্দা থেকেও কাজটা হয়, আর ইতিহাসটা সেখানেই থাকে।
     */
    public function test_the_screen_moves_it_and_remembers(): void
    {
        $this->post(route('accounts.asset.transfer', $this->fridge), [
            'to_branch_id' => $this->khulna->id,
            'moved_on' => now()->toDateString(),
            'note' => 'নতুন দোকানে',
        ])->assertRedirect();

        $this->get(route('accounts.asset.show', $this->fridge))
            ->assertOk()
            ->assertSee('নতুন দোকানে');
    }

    /**
     * ⛔ ভবিষ্যতের তারিখে সরানো যায় না — খাতা আগামীকাল চেনে না।
     */
    public function test_the_future_is_refused(): void
    {
        $this->post(route('accounts.asset.transfer', $this->fridge), [
            'to_branch_id' => $this->khulna->id,
            'moved_on' => now()->addWeek()->toDateString(),
        ])->assertSessionHasErrors('moved_on');

        $this->assertSame(0, AssetTransfer::query()->count());
    }

    private function accountId(string $code, bool $group = false): int
    {
        $query = Account::query()->where('code', $code);

        /* ⓘ সম্পদের খাতটা দল — তার নিচের প্রথম পোস্টযোগ্য খাতটাই লাগে */
        if ($group) {
            $parent = $query->firstOrFail();

            return (int) Account::query()
                ->where('parent_id', $parent->id)
                ->where('is_group', false)
                ->value('id') ?: (int) $parent->id;
        }

        return (int) $query->value('id');
    }
}
