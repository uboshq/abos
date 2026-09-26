<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionCondition;
use App\Modules\Promotion\Models\PromotionScope;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Closure;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * অফারের প্রতিটা দরজা যেকোনো চাবিতে খুলত কি না — কেউ মাপত না।
 *
 * ── ⚠️ পর্যালোচনায় ধরা (২৭ সেপ্টেম্বর) ─────────────────────────────
 * ⓘ জমা · অনুমোদন · চালু · থামানো · বাতিল · মেয়াদ বদল · ধাপ · সুযোগ ·
 * তৈরি — এদের কোনোটার ৪০৩-এর দাবি ছিল না। ⛔ কেউ `can:` সারি মুছে দিলেও
 * সব পরীক্ষা সবুজ থাকত। ⓘ আর যে দুই-একটা ছিল, সেগুলো **আলাদা মানুষ** দিয়ে
 * মাপত — তখন ৪০৩-টা সদস্যপদের কারণেও আসতে পারত, চাবির কারণে নয়।
 *
 * ⭐ তাই এখানে **একই মানুষ, একই অফার** — চাবি ছাড়া ৪০৩ আর কিছুই বদলায়
 * না; চাবি পেলে দরজা খোলে আর কাজটা সত্যিই ঘটে। ⓘ দরজাগুলো একটা খসড়ার
 * জীবনচক্র ধরে ক্রমে চলে, কারণ প্রতিটা পরীক্ষায় ডেমো বীজ বসাতে অনেক সময়।
 */
final class EveryOfferDoorWasOpenToAnyKeyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /* ⓘ কেবল দেখার চাবি — বাকি সব চাবি দরজা ধরে ধরে দেওয়া হয় */
        $this->clerk = User::factory()->create(['is_active' => true]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->clerk->forceFill(['current_company_id' => $this->company->id])->save();
        $this->clerk->givePermissionTo(Permission::findOrCreate('promotion.view', 'web'));
    }

    private function anOffer(PromotionStatus $status = PromotionStatus::DRAFT): Promotion
    {
        $offer = new Promotion([
            'name_en' => 'Door '.$status->value,
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = 'PROM-K-'.strtoupper(substr(md5($status->value.microtime()), 0, 6));
        $offer->type = PromotionType::QUANTITY_SLAB;
        $offer->status = $status;
        $offer->combines = PromotionCombines::BEST;

        /* ⚠️ মালিকের বানানো — কেরানি নিজের অফারে সই দিতে পারেন না, তাই অন্য কেউ */
        $offer->created_by = $this->owner->id;
        $offer->save();

        return $offer;
    }

    /**
     * ⭐ একটা দরজা — চাবি ছাড়া বন্ধ আর কিছু বদলায় না, চাবি পেলে খোলে আর কাজ হয়।
     *
     * @param  Closure(): TestResponse  $knock
     * @param  Closure(): mixed  $state  যা বদলানোর কথা
     */
    private function door(string $key, Closure $knock, Closure $state, string $what): void
    {
        $holds = $this->clerk->fresh()->can($key);

        if (! $holds) {
            $before = $state();
            $this->actingAs($this->clerk->fresh());
            $knock()->assertForbidden();
            $this->assertEquals($before, $state(), "$what: চাবি ছাড়া ৪০৩ দিল, অথচ কাজটা ঘটে গেছে।");

            $this->clerk->givePermissionTo(Permission::findOrCreate($key, 'web'));
        }

        $before = $state();
        $this->actingAs($this->clerk->fresh());
        $response = $knock();

        $this->assertNotSame(403, $response->status(), "$what: `$key` পেয়েও দরজা খোলেনি।");
        $response->assertSessionHasNoErrors();
        /* ⓘ উত্তরের কোড আর গন্তব্য বার্তায় — লাল হলে কারণটা এখানেই পড়া যায় */
        $this->assertNotEquals($before, $state(), "$what: দরজা খুলল, অথচ কাজটা ঘটেনি। উত্তর: "
            .$response->status().' → '.($response->headers->get('Location') ?? '—')
            .' · '.json_encode(session('errors')?->getBag('default')->all() ?? [], JSON_UNESCAPED_UNICODE));
    }

    /** ⭐ তৈরি → ধাপ → সুযোগ → মোছা → জমা → অনুমোদন → চালু → থামানো → মেয়াদ → বাতিল */
    public function test_every_door_of_an_offers_life_needs_its_own_key(): void
    {
        $this->actingAs($this->clerk);
        $this->get(route('promotion.create'))->assertForbidden();

        $this->door('promotion.create',
            fn () => $this->post(route('promotion.store'), [
                'name_en' => 'Clerk made', 'type' => PromotionType::QUANTITY_SLAB->value,
                'starts_on' => Carbon::today()->toDateString(), 'ends_on' => Carbon::today()->addWeek()->toDateString(),
            ]),
            fn () => Promotion::query()->count(), 'তৈরি');

        $offer = $this->anOffer();

        $this->door('promotion.update',
            fn () => $this->post(route('promotion.step.store', $offer), [
                'condition_kind' => 'quantity', 'value_from' => '10', 'benefit_kind' => 'percent', 'amount' => '5',
            ]),
            fn () => PromotionCondition::query()->where('promotion_id', $offer->id)->count(), 'ধাপ যোগ');

        $customer = (int) Customer::query()->orderBy('id')->value('id');

        $this->door('promotion.update',
            fn () => $this->post(route('promotion.scope.store', $offer), ['kind' => 'customer', 'target_id' => $customer]),
            fn () => PromotionScope::query()->where('promotion_id', $offer->id)->count(), 'সুযোগ যোগ');

        $scope = PromotionScope::query()->where('promotion_id', $offer->id)->firstOrFail();

        $this->door('promotion.update',
            fn () => $this->delete(route('promotion.scope.destroy', [$offer, $scope])),
            fn () => PromotionScope::query()->where('promotion_id', $offer->id)->count(), 'সুযোগ মোছা');

        $status = fn () => $offer->fresh()->status;

        $this->door('promotion.submit', fn () => $this->post(route('promotion.submit', $offer)), $status, 'জমা');
        $this->door('promotion.approve', fn () => $this->post(route('promotion.approve', $offer)), $status, 'অনুমোদন');
        $this->door('promotion.activate', fn () => $this->post(route('promotion.activate', $offer)), $status, 'চালু');
        $this->door('promotion.pause', fn () => $this->post(route('promotion.pause', $offer)), $status, 'থামানো');

        $this->door('promotion.update',
            fn () => $this->post(route('promotion.reschedule', $offer), ['ends_on' => Carbon::today()->addMonth()->toDateString()]),
            fn () => $offer->fresh()->ends_on->toDateString(), 'মেয়াদ বদল');

        $this->door('promotion.cancel', fn () => $this->post(route('promotion.cancel', $offer)), $status, 'বাতিল');

        $this->assertSame(PromotionStatus::CANCELLED, $offer->fresh()->status);
    }

    /**
     * ⭐ ফেরত পাঠানো `approve`-এর চাবিতে, জমা ফেরত নেওয়া কেবল নির্মাতার।
     *
     * ⚠️ বিপজ্জনক ইনপুট: `submit` চাবি আছে, কিন্তু অফারটা অন্যের — ফেরত
     * নেওয়া যায় না। ⓘ নাহলে যেকোনো বিক্রয়কর্মী অন্যের জমা দেওয়া অফার
     * সইয়ের লাইন থেকে সরিয়ে দিতে পারতেন।
     */
    public function test_sending_back_needs_the_approve_key_and_withdrawing_needs_the_creator(): void
    {
        $offer = $this->anOffer(PromotionStatus::SUBMITTED);

        $this->door('promotion.approve',
            fn () => $this->post(route('promotion.send_back', $offer), ['reason' => 'ধাপগুলো আবার দেখুন']),
            fn () => $offer->fresh()->status, 'ফেরত পাঠানো');

        $this->assertSame(PromotionStatus::DRAFT, $offer->fresh()->status);

        $other = $this->anOffer(PromotionStatus::SUBMITTED);
        $this->clerk->givePermissionTo(Permission::findOrCreate('promotion.submit', 'web'));

        $this->actingAs($this->clerk->fresh())
            ->post(route('promotion.withdraw', $other))
            ->assertSessionHasErrors();

        $this->assertSame(PromotionStatus::SUBMITTED, $other->fresh()->status,
            'অন্যের জমা দেওয়া অফার ফেরত নেওয়া গেছে।');
    }
}
