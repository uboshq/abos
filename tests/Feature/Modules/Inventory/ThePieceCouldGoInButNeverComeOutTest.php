<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Services\SerialNumberService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * পিস ঢুকতে পারত, বেরোতে পারত না।
 *
 * ── ⛔ ফাঁকটা ─────────────────────────────────────────────────────────
 * [[SerialNumberService::issue()]] লেখা হয়েছিল ২৪ সেপ্টেম্বরে, আর সেদিন
 * থেকে **একটাও পথ ওটাতে পৌঁছাত না** — না পর্দা, না রুট, না API।
 * ⚠️ পিস ঢুকত, কোনোদিন বেরোত না, আর প্রতিটা নম্বর চিরকাল `IN_STOCK`
 * হয়ে বসে থাকত।
 *
 * ⓘ ওয়ারেন্টির গোটা প্রশ্নটাই এর উপর দাঁড়ানো: বেরোনোর তারিখ ছাড়া
 * ওয়ারেন্টি শুরুই হয় না, তাই *"এই পিসটার মেয়াদ আছে কি"* প্রশ্নের উত্তর
 * চিরকাল "না" হত — আর সেটা দেখতে ঠিক সঠিক উত্তরের মতোই লাগত।
 *
 * ── ⚠️ এই ফাইল যা পাহারা দেয় ────────────────────────────────────────
 *   ১. দরজাটা সত্যিই আছে, আর চাবিওয়ালা ব্যবহারকারীর কাছে খোলে
 *   ২. চাবি ছাড়া খোলে না
 *   ৩. পিস বেরোলে অবস্থা বদলায়, আর ওয়ারেন্টি **বেরোনোর** দিনে শুরু হয়
 *   ৪. শূন্য মাস মানে ওয়ারেন্টি নেই — "আজই শেষ" নয়
 *   ৫. একই পিস দুইবার বেরোতে পারে না
 *
 * ⓘ (৩) সবচেয়ে সহজে ভুল হয়: ঢোকার তারিখ ধরলে ছয় মাস গুদামে পড়ে থাকা
 * একটা যন্ত্র ক্রেতার হাতে যাওয়ার দিনেই অর্ধেক ওয়ারেন্টি হারাত।
 */
final class ThePieceCouldGoInButNeverComeOutTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $tracked;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->tracked = Product::query()->orderBy('id')->firstOrFail();
        $this->tracked->forceFill(['track_serial' => true])->save();
    }

    // ── ১ ও ২ · দরজাটা আছে, আর চাবি মানে ─────────────────────────────

    public function test_the_issue_screen_opens_for_someone_with_the_key(): void
    {
        /*
         * ⚠️ এটাই গোটা ফাইলটার কারণ: ইঞ্জিনটা ২৪ সেপ্টেম্বর থেকে ছিল,
         * আর এই একটা দাবি লিখলেই সেদিন ধরা পড়ত যে ওতে পৌঁছানোর কোনো
         * পথই নেই।
         */
        $this->putOneIn('SN-DOOR-1');

        $this->get(route('inventory.serial.issue'))
            ->assertOk()
            ->assertSee('SN-DOOR-1');
    }

    public function test_the_issue_screen_is_closed_without_the_key(): void
    {
        $stranger = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($stranger)
            ->get(route('inventory.serial.issue'))
            ->assertForbidden();
    }

    // ── ৩ · বেরোলে অবস্থা বদলায়, ওয়ারেন্টি তখনই শুরু ─────────────────

    public function test_a_piece_that_goes_out_starts_its_warranty_that_day(): void
    {
        /*
         * ⛔ পিসটা গুদামে বসেছিল ছয় মাস আগে থেকে। ⚠️ ওয়ারেন্টি যদি
         * ঢোকার দিনে শুরু হত, ক্রেতা বারো মাসের বদলে ছয় মাস পেতেন —
         * আর কাগজে লেখা থাকত "বারো মাস"।
         */
        $this->putOneIn('SN-WAR-1', receivedOn: now()->subMonths(6)->toDateString());

        $this->post(route('inventory.serial.issue.store'), [
            'serials' => 'SN-WAR-1',
            'issued_on' => now()->toDateString(),
            'sold_to' => 'করিম স্টোর',
            'warranty_months' => 12,
        ])->assertSessionHasNoErrors();

        $piece = SerialNumber::query()->where('serial_no', 'SN-WAR-1')->firstOrFail();

        $this->assertSame(SerialNumber::SOLD, $piece->status,
            'পিসটা বেরিয়ে গেছে, তবু খাতায় এখনো "গুদামে" — তাহলে ওটা '
            .'দ্বিতীয়বার বেচা যেত।');

        $this->assertSame(now()->toDateString(),
            Carbon::parse((string) $piece->warranty_from)->toDateString(),
            'ওয়ারেন্টি বেরোনোর দিনে শুরু হয়নি — গুদামে পড়ে থাকা মাসগুলো '
            .'ক্রেতার ওয়ারেন্টি খেয়ে ফেলছে।');

        $this->assertSame(now()->addMonths(12)->toDateString(),
            Carbon::parse((string) $piece->warranty_to)->toDateString());

        $this->assertSame('করিম স্টোর', $piece->sold_to);
    }

    // ── ৪ · শূন্য মাস মানে ওয়ারেন্টি নেই ─────────────────────────────

    public function test_no_warranty_means_no_dates_at_all(): void
    {
        /*
         * ⛔ শূন্য মাসের একটা তারিখ বসালে ওটা *"আজই শেষ"* বলত, আর
         * ⚠️ "মেয়াদ নেই" আর "মেয়াদ আজ ফুরিয়েছে" দুইটা আলাদা কথা —
         * দ্বিতীয়টা ক্রেতার সাথে তর্কের জন্ম দেয়।
         */
        $this->putOneIn('SN-NOWAR-1');

        $this->post(route('inventory.serial.issue.store'), [
            'serials' => 'SN-NOWAR-1',
            'issued_on' => now()->toDateString(),
            'warranty_months' => 0,
        ])->assertSessionHasNoErrors();

        $piece = SerialNumber::query()->where('serial_no', 'SN-NOWAR-1')->firstOrFail();

        $this->assertNull($piece->warranty_from);
        $this->assertNull($piece->warranty_to);
    }

    // ── ৫ · একই পিস দুইবার নয় ────────────────────────────────────────

    public function test_the_same_piece_cannot_go_out_twice(): void
    {
        /*
         * ⛔ পারলে একই নম্বর দুইজন ক্রেতার কাছে যেত, আর ওয়ারেন্টির
         * দাবিতে দুইজনের কাগজেই ঐ এক নম্বর থাকত — ⚠️ আর তখন কোনটা
         * সত্যি তা বলার কোনো উপায় থাকত না।
         */
        $this->putOneIn('SN-TWICE-1');

        $this->post(route('inventory.serial.issue.store'), [
            'serials' => 'SN-TWICE-1',
            'issued_on' => now()->toDateString(),
            'warranty_months' => 0,
        ])->assertSessionHasNoErrors();

        $this->post(route('inventory.serial.issue.store'), [
            'serials' => 'SN-TWICE-1',
            'issued_on' => now()->toDateString(),
            'warranty_months' => 0,
        ])->assertSessionHasErrors('serials');
    }

    public function test_a_number_nobody_ever_received_is_refused(): void
    {
        $this->post(route('inventory.serial.issue.store'), [
            'serials' => 'SN-GHOST-1',
            'issued_on' => now()->toDateString(),
            'warranty_months' => 0,
        ])->assertSessionHasErrors('serials');

        $this->assertSame(0, SerialNumber::query()->where('serial_no', 'SN-GHOST-1')->count(),
            'অচেনা একটা নম্বর বেরোনোর চেষ্টায় খাতায় বসে গেছে।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function putOneIn(string $number, ?string $receivedOn = null): void
    {
        app(SerialNumberService::class)->receive(
            product: $this->tracked,
            serials: [$number],
            data: ['received_on' => $receivedOn ?? now()->toDateString()],
        );
    }
}
