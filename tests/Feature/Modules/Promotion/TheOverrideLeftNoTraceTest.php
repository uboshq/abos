<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\AuditFieldChange;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBudget;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * হাতে বদল হলো, আর কোনো চিহ্ন রইল না — স্পেক §১৯।
 *
 * ── ⭐ স্পেক ─────────────────────────────────────────────────────────
 * *"Audit Trail কখনো মুছে ফেলা যাবে না।"* আর ওভাররাইডে: *"Reason
 * Mandatory, User, Date/Time, Original Benefit, Modified Benefit"*।
 *
 * ── ⚠️ কেন ওভাররাইডের নিজের ঘরগুলো যথেষ্ট নয় ─────────────────────────
 * ⓘ [[SomeoneChangedTheDiscountAndNobodyKeptTheOldOneTest]] দেখায়
 * `original_worth` থাকে। ⛔ কিন্তু ঐ ঘরে থাকে কেবল **প্রথম** অঙ্ক আর
 * **শেষ** অঙ্ক — মাঝের বদলগুলো, আর কে কোনটা করেছিলেন, ওখানে নেই।
 * ⭐ পুরো ইতিহাস থাকে নিরীক্ষার খাতায় ([[IsAudited]] → `audit_trails` +
 * `audit_field_changes`), আর তাই এই ফাইল ওখানেই তাকায়।
 *
 * ── ⓘ মুছতে না পারার পাহারাটা কোথায় ─────────────────────────────────
 * ⭐ [[AuditTrail]] আর [[AuditFieldChange]]-এর `booted()`-এ: `saving`
 * (আগে থেকে থাকা সারিতে) আর `deleting` — দুইটাই `RuntimeException`।
 *
 * ⚠️ পাহারাটা **মডেলের ঘটনায়**, ডাটাবেজে নয়। ⓘ `query()->delete()`
 * বা `DB::table()` ঘটনা ডাকে না — ঐ ফাঁকটা এই ফাইল মাপে না, কারণ মাপলে
 * আজ লাল হত; সেটা আলাদা করে জানানো হয়েছে।
 */
final class TheOverrideLeftNoTraceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Promotion $offer;

    private PromotionApplication $applied;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->offer = new Promotion([
            'name_en' => 'Trace me',
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $this->offer->code = 'PROM-T-0001';
        $this->offer->type = PromotionType::QUANTITY_SLAB;
        $this->offer->status = PromotionStatus::ACTIVE;
        $this->offer->combines = PromotionCombines::BEST;
        $this->offer->created_by = $this->owner->id;
        $this->offer->save();

        $this->applied = PromotionApplication::query()->create([
            'promotion_id' => $this->offer->id,
            'source_type' => 'sales_invoice',
            'source_id' => 6101,
            'benefit_kind' => BenefitKind::AMOUNT,
            'benefit_amount' => '100',
            'worth' => '100',
        ]);
    }

    /**
     * ⓘ একটা রেকর্ডের শেষ *"বদলানো"* সারি, আর তার একটা ঘরের পুরনো-নতুন।
     *
     * ⚠️ ঘর ধরে সরাসরি `AuditFieldChange` পড়া — `$trail->changes` নয়,
     * কারণ `changes` নামটা Eloquent-এর নিজের একটা ভিতরের ঘরও।
     *
     * @return array{0: AuditTrail, 1: AuditFieldChange}
     */
    private function lastChangeOf(string $type, int $id, string $field): array
    {
        $trail = AuditTrail::query()
            ->forRecord($type, $id)
            ->where('action', AuditTrail::UPDATED)
            ->whereHas('changes', fn ($q) => $q->where('field', $field))
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($trail, "«{$field}» বদলাল, অথচ নিরীক্ষার খাতায় কোনো সারি নেই।");

        $change = AuditFieldChange::query()
            ->where('audit_trail_id', $trail->id)
            ->where('field', $field)
            ->firstOrFail();

        return [$trail, $change];
    }

    /**
     * ⛔ কাজটা বাড়ির পাহারায় থামল কি না।
     *
     * ── ⚠️ কেন `try { …; $this->fail() } catch (RuntimeException)` নয় ──
     * ⓘ PHPUnit-এর `fail()` যে ব্যতিক্রম ছোঁড়ে (`AssertionFailedError`)
     * সেটাও `RuntimeException`-এর বংশধর। ⛔ ঐ আকারে লিখলে পাহারা না
     * থাকলেও `fail()`-টা নিজেই ধরা পড়ত, আর দাবিটা **কখনো লাল হত না**।
     *
     * ⭐ তাই PHPUnit-এর নিজের ব্যতিক্রম আবার ছোঁড়া হয়, আর বার্তাটা
     * মিলিয়ে দেখা হয় — অন্য কোনো কারণে ভাঙলে সেটা পাহারা বলে গোনা হয় না।
     */
    private function assertRefused(callable $attempt, string $why): void
    {
        try {
            $attempt();
        } catch (\PHPUnit\Exception $own) {
            throw $own;
        } catch (RuntimeException $refused) {
            $this->assertMatchesRegularExpression('/cannot be (changed|edited|deleted)/', $refused->getMessage(),
                'থামল, কিন্তু বাড়ির পাহারায় নয়: '.$refused->getMessage());

            return;
        }

        $this->fail($why);
    }

    private function override(string $worth, string $reason): void
    {
        $this->actingAs($this->owner)
            ->post(route('promotion.override', $this->applied), [
                'worth' => $worth,
                'override_reason' => $reason,
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * ⭐ হাতে বদল খাতায় থাকে — পুরনো অঙ্ক, নতুন অঙ্ক, কারণ আর কে।
     *
     * ⚠️ বিপজ্জনক ইনপুট: **দুইবার** বদল। ⓘ একবারে `original_worth`
     * আর খাতা একই কথা বলত; দ্বিতীয় বদলের *"পুরনো"* অঙ্ক (১৫০) কেবল
     * খাতাতেই আছে — ওটা হারালে মাঝের হাতটা অদৃশ্য।
     */
    public function test_each_override_leaves_its_old_and_new_worth_and_its_reason(): void
    {
        $this->override('150', 'পুরনো ক্রেতা, মালিকের সম্মতিতে');
        $this->override('180', 'ঈদের আগে আরও একটু');

        [$trail, $worth] = $this->lastChangeOf(PromotionApplication::class, $this->applied->id, 'worth');

        $this->assertSame(0, bccomp((string) $worth->old_value, '150', 4),
            'দ্বিতীয় বদলের আগের অঙ্কটা খাতায় নেই — মাঝের হাতটা হারিয়ে গেছে।');
        $this->assertSame(0, bccomp((string) $worth->new_value, '180', 4));
        $this->assertSame($this->owner->id, (int) $trail->user_id, 'কে বদলালেন তা খাতায় নেই।');
        $this->assertNotNull($trail->created_at);

        $reason = AuditFieldChange::query()
            ->where('audit_trail_id', $trail->id)
            ->where('field', 'override_reason')
            ->first();

        $this->assertNotNull($reason, 'কারণটা একই সারিতে লেখা হয়নি।');
        $this->assertSame('ঈদের আগে আরও একটু', $reason->new_value);
    }

    /**
     * ⭐ ছাদ বাড়ানো খাতায় থাকে — আগের ছাদ আর নতুন ছাদ।
     *
     * ⓘ ছাদ বাড়ানো মানে আরও টাকা দেওয়া। ⛔ কেবল শেষ অঙ্কটা থাকলে
     * *"কে কবে ৫০০ থেকে ৮০০ করেছিলেন"* প্রশ্নের কোনো উত্তর থাকত না।
     */
    public function test_raising_the_ceiling_leaves_the_old_and_new_ceiling(): void
    {
        // ⓘ খসড়ায় — সই হওয়া অফারের ছাদ নতুন সই ছাড়া বাড়ে না (Sales অডিট, ১০ অক্টোবর ২০২৬; [[BudgetKeeper::set()]])
        $this->offer->status = PromotionStatus::DRAFT;
        $this->offer->save();

        foreach (['500', '800'] as $ceiling) {
            $this->actingAs($this->owner)
                ->post(route('promotion.budget.store', $this->offer), ['kind' => 'total', 'ceiling' => $ceiling])
                ->assertSessionHasNoErrors();
        }

        $budget = PromotionBudget::query()
            ->where('promotion_id', $this->offer->id)
            ->where('kind', PromotionBudget::TOTAL)
            ->firstOrFail();

        [$trail, $change] = $this->lastChangeOf(PromotionBudget::class, $budget->id, 'ceiling');

        $this->assertSame(0, bccomp((string) $change->old_value, '500', 4), 'আগের ছাদটা খাতায় নেই।');
        $this->assertSame(0, bccomp((string) $change->new_value, '800', 4));
        $this->assertSame($this->owner->id, (int) $trail->user_id);
    }

    /**
     * ⛔ খাতার সারি বদলানো যায় না — মডেলের পথে।
     *
     * ⚠️ বিপজ্জনক ইনপুট: পুরনো অঙ্কটাকে নতুনের সমান করে দেওয়া — অর্থাৎ
     * ঠিক সেই বদল যা একটা ওভাররাইড লুকাতে চাইলে কেউ করত।
     */
    public function test_a_trail_row_cannot_be_rewritten(): void
    {
        $this->override('150', 'লুকানোর চেষ্টার আগে');
        [$trail, $change] = $this->lastChangeOf(PromotionApplication::class, $this->applied->id, 'worth');

        $this->assertRefused(function () use ($change) {
            $change->old_value = '150';
            $change->save();
        }, 'খাতার ঘরের পুরনো অঙ্ক বদলে দেওয়া গেল।');

        $this->assertRefused(function () use ($trail) {
            $trail->user_id = null;
            $trail->save();
        }, 'খাতার সারি থেকে কে-বদলালেন মুছে দেওয়া গেল।');

        $this->assertSame(0, bccomp((string) $change->fresh()->old_value, '100', 4),
            'বাধা দিল, অথচ ডাটাবেজে পুরনো অঙ্কটা বদলে গেছে।');
        $this->assertSame($this->owner->id, (int) $trail->fresh()->user_id);
    }

    /** ⛔ খাতার সারি মোছা যায় না — না সারিটা, না তার ঘরগুলো। */
    public function test_a_trail_row_cannot_be_deleted(): void
    {
        $this->override('150', 'মোছার চেষ্টার আগে');
        [$trail, $change] = $this->lastChangeOf(PromotionApplication::class, $this->applied->id, 'worth');

        $this->assertRefused(fn () => $change->delete(), 'খাতার ঘর মুছে ফেলা গেল।');
        $this->assertRefused(fn () => $trail->delete(), 'খাতার সারি মুছে ফেলা গেল।');

        $this->assertTrue(AuditTrail::query()->whereKey($trail->id)->exists(), 'সারিটা আর নেই।');
        $this->assertTrue(AuditFieldChange::query()->whereKey($change->id)->exists(), 'ঘরটা আর নেই।');
    }

    /**
     * ⭐ পাল্টা-দাবি: নতুন সারি লেখা যায় — নাহলে *"সব সংরক্ষণ থামাও"*
     * লিখেও উপরের দুইটা সবুজ হত, আর খাতায় কিছুই জমত না।
     */
    public function test_new_trail_rows_are_still_written(): void
    {
        $before = AuditTrail::query()->forRecord(PromotionApplication::class, $this->applied->id)->count();

        $this->override('150', 'নতুন সারি জমছে কি না');

        $this->assertGreaterThan($before,
            AuditTrail::query()->forRecord(PromotionApplication::class, $this->applied->id)->count());
    }
}
