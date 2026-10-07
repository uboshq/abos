<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Concerns\ScopedToUserDealers;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DealerScope;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerConduct;
use App\Modules\Sales\Models\DeliveryChallanLine;
use Database\Seeders\DemoSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Throwable;

/**
 * ⭐ ডিলারের দেয়াল প্রতিটা ডিলারের কাগজে, আর প্রতিটা বিক্রয়/গ্রাহকের রিপোর্টে — ⛔১৬, ২ অক্টোবর ২০২৬।
 *
 * ── ⛔ কেন এই পাহারা ───────────────────────────────────────────────────
 * নতুন ছাঁকনির মাত্রা মানে পুরনো বাগ নতুন দরজা দিয়ে ফেরে ([[a-guard-protects-a-case-not-a-shape]])।
 * কাল কেউ একটা নতুন কাগজ বানাবেন যার গায়ে `customer_id`, আর `use ScopedToUserDealers;` ভোলার
 * **কোনো লক্ষণ নেই**: পর্দা খোলে, কেউ অভিযোগ করেন না — কেবল একজন বিক্রয়কর্মী অন্যের ডিলারের
 * কাগজ দেখেন, আর তিনি বলতে আসেন না।
 *
 * ⓘ তালিকা হাতে লেখা নয় (মেমরি: never-supply-the-name-yourself): মডেলের নিজের `fillable` থেকে
 * খোঁজা — যার গায়ে ডিলার (`customer_id`) বা ডিলারের চালান (`delivery_challan_id`)।
 */
final class EveryDealerPaperStandsBehindTheDealerWallTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ইচ্ছাকৃতভাবে দেয়ালের বাইরে — প্রতিটার কারণ পাশে।
     *
     * @var array<class-string, string>
     */
    private const EXCUSED = [
        'App\Modules\Customer\Models\DealerBinding' => 'বাঁধনটাই দেয়ালের উৎস; এর পর্দা কেবল বাঁধার চাবিওয়ালার, আর দেয়ালের হিসাব কাঁচা কোয়েরিতে',
        'App\Modules\Sales\Models\Lead' => 'নিজের লিড নিজে দেখেন (scopeVisibleTo); `customer_id` রূপান্তরের ফল — সম্ভাব্য ক্রেতার কোনো ডিলার নেই, দেয়াল বসালে বিক্রয়কর্মীর নিজের লিড হারাত',
        'App\Modules\Sales\Models\Opportunity' => 'লিডের একই কারণ — নিজের সুযোগ নিজে দেখেন (scopeVisibleTo), ডিলার না-ও থাকতে পারে',
        'App\Modules\Promotion\Models\PromotionApplication' => 'যাচাইয়ের পথ: বাজেটের সীমা সব ডিলারের প্রয়োগ মিলিয়ে গোনে (BudgetGuard) — অর্ধেক দেখলে সীমা নিচু হত; প্রমোশনের পর্দা বিক্রয়কর্মীর চাবিতে নেই, আর রিপোর্টগুলো ইঞ্জিনেই ফেরত',
        'App\Modules\Promotion\Models\PromotionCoupon' => 'যাচাইয়ের পথ: কুপন কতবার চলেছে তা সবার ব্যবহার মিলিয়ে (CouponDesk); প্রমোশনের পর্দা বিক্রয়কর্মীর চাবিতে নেই',
        'App\Modules\Promotion\Models\PromotionCouponRedemption' => 'কুপনের একই কারণ — ব্যবহারের গোনা সবার',
        'App\Modules\Promotion\Models\LoyaltyEntry' => 'আনুগত্যের খাতা — জের পুরো খাতা ধরে (LoyaltyLedger); প্রমোশনের পর্দা বিক্রয়কর্মীর চাবিতে নেই',
        'App\Modules\Sales\Models\DeliveryChallanLine' => 'লাইন — কেবল দেয়াল-ঘেরা চালানের ভিতর দিয়ে পৌঁছায়',
        'App\Modules\Sales\Models\DeliveryChallanGiftLine' => 'লাইন — কেবল দেয়াল-ঘেরা চালানের ভিতর দিয়ে পৌঁছায়',
        'App\Modules\Sales\Models\ShipmentLine' => 'লাইন — দেয়াল-ঘেরা ট্রিপ আর চালানের ভিতর দিয়ে',
        'App\Modules\Sales\Models\DeliveryEvent' => 'চালানের ঘটনা — দেয়াল-ঘেরা চালানের পাতার ভিতরে দেখায়, নিজের তালিকা নেই',
    ];

    /**
     * ⛔ বিক্রয়/গ্রাহকের যে রিপোর্ট দেয়ালের মানুষকে ইচ্ছা করে ফেরানো হয় — কারণসহ।
     *
     * @var array<string, string>
     */
    private const REFUSED_TO_THE_WALLED = [
        'customer.ledger_check' => 'হিসাবের মিলানো — পাওনার খাতের সাথে সব পক্ষের যোগফল, আর "কারো নামে নয়" সারিটা গোটা কোম্পানির; বিক্রয়কর্মীর দেখার জিনিস নয়',
    ];

    public function test_every_model_that_names_a_dealer_or_his_challan_is_walled(): void
    {
        $models = $this->dealerModels();

        $this->assertGreaterThanOrEqual(20, count($models), 'ডিলারের কাগজ পাওয়া গেল মাত্র '.count($models).'টা — খোঁজাটাই ভেঙেছে।');

        $open = [];

        foreach ($models as $model) {
            if (! array_key_exists($model, self::EXCUSED) && ! $this->walled($model)) {
                $open[] = $model;
            }
        }

        $this->assertSame([], $open, implode("\n", [
            'এই মডেলগুলো ডিলার (বা তাঁর চালান) রাখে, কিন্তু ডিলারের দেয়ালের বাইরে:', '', ...$open, '',
            '⛔ অর্থাৎ বিক্রয়কর্মী আইডি জানলেই অন্যের ডিলারের কাগজ খুলতে পারেন।',
            'মডেলে `use \App\Core\Concerns\ScopedToUserDealers;` বসান, নয়তো EXCUSED-এ কারণসহ লিখুন।',
        ]));
    }

    public function test_the_excuses_stay_true(): void
    {
        $stale = [];

        foreach (array_keys(self::EXCUSED) as $model) {
            if (! class_exists($model)) {
                // ⓘ পিয়ারের অকমিটেড মডেল (লিড, সুযোগ) HEAD-এ নেই — বাসি নয়, এখনো আসেনি
                continue;
            }

            if ($this->walled($model)) {
                $stale[] = $model.' — এখন দেয়ালের ভিতরে, ছাড়টা অর্থহীন';
            } elseif (! in_array($model, $this->dealerModels(), true)) {
                $stale[] = $model.' — আর ডিলার রাখে না';
            }
        }

        $this->assertSame([], $stale, "বাসি ছাড়:\n".implode("\n", $stale));
    }

    /** ⭐ গ্রাহকের নিজের তালিকা দেয়ালে — `id` ধরে, কারণ ডিলারটা সারিটাই। */
    public function test_the_dealer_list_itself_is_walled_by_its_own_id(): void
    {
        $this->assertTrue($this->walled(Customer::class));
        $this->assertSame('id', (new Customer)->dealerScopeColumn());
    }

    /** ⓘ যন্ত্রটা দুই দিকেই চেনে — আসল নমুনায়: একটা দেয়ালের ভিতরে, একটা ছাড়ের তালিকায়। */
    public function test_the_detector_can_tell_the_two_apart(): void
    {
        $this->assertTrue($this->walled(CustomerConduct::class));
        $this->assertFalse($this->walled(DeliveryChallanLine::class));
        $this->assertContains(DeliveryChallanLine::class, $this->dealerModels(), 'লাইনের মডেল খোঁজায় নেই — খোঁজাটাই অন্ধ।');
    }

    /**
     * ⭐ বিক্রয় আর গ্রাহকের প্রতিটা রিপোর্ট দেয়ালের ভিতরের মানুষের জন্য **চলে** — অর্থাৎ ডিলারের
     * দেয়াল বসায় (না বসালে ইঞ্জিন ফেরায়)। ⓘ বাকি মডিউলের রিপোর্ট হয় চলে (দেয়াল বসিয়ে), নয়
     * ফেরত — অন্য কোনো ভাঙন নয়। আসল ডাটাবেজে, তাই সাবকোয়েরিটা কড়া SQL-এও বৈধ কি না মাপা।
     */
    public function test_every_sales_and_customer_report_lays_the_dealer_wall(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $user = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
        $user->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $user->givePermissionTo(Permission::findOrCreate(DealerScope::OWN, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DealerScope::class)->forget();
        $this->actingAs($user->fresh());

        $this->assertTrue(app(DealerScope::class)->walled(), 'মানুষটা দেয়ালেই নেই — দাবিটা কিছু মাপছে না।');

        $engine = app(ReportEngine::class);
        $range = ['from' => now()->subMonths(3)->toDateString(), 'to' => now()->toDateString()];
        $lost = [];
        $broken = [];
        $ran = 0;

        foreach ($engine->keys() as $key) {
            $ours = str_starts_with($key, 'sales.') || str_starts_with($key, 'customer.');

            try {
                $engine->run($key, $range);
                $ran++;
            } catch (AuthorizationException $e) {
                if ($ours && ! array_key_exists($key, self::REFUSED_TO_THE_WALLED)) {
                    $lost[] = $key;
                }
            } catch (Throwable $e) {
                $broken[] = $key.': '.$e::class.' — '.$e->getMessage();
            }
        }

        $this->assertGreaterThan(8, $ran, 'দেয়ালের ভিতরে প্রায় কোনো রিপোর্টই চলেনি — খোঁজাটাই ভেঙেছে।');
        $this->assertSame([], $broken, "দেয়ালের ভিতরে এই রিপোর্টগুলো ভেঙেছে:\n".implode("\n", $broken));
        $this->assertSame([], $lost, implode("\n", [
            'বিক্রয়/গ্রাহকের এই রিপোর্টগুলো ডিলারের দেয়াল বসায় না — বিক্রয়কর্মী ৪০৩ পান:', '', ...$lost, '',
            '⭐ কোয়েরিতে `->tap(ReportEngine::dealerWall($f, \'<table>.customer_id\'))`।',
        ]));
    }

    /** @return list<class-string> */
    private function dealerModels(): array
    {
        $models = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Modules'), \FilesystemIterator::SKIP_DOTS));

        foreach ($walk as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());

            if (! str_contains($path, '/Models/') || str_contains($path, '/Models/Concerns/') || ! str_ends_with($path, '.php')) {
                continue;
            }

            $class = 'App\\'.str_replace('/', '\\', substr($path, strpos($path, '/app/') + 5, -4));

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
                continue;
            }

            // ⓘ গঠন-পদ্ধতি ছাড়া — কিছু মডেল নিজের `new` বন্ধ রাখে (PricingRule), fillable তবু পড়া যায়
            $fillable = $reflection->newInstanceWithoutConstructor()->getFillable();

            if (in_array('customer_id', $fillable, true) || in_array('delivery_challan_id', $fillable, true) || $class === Customer::class) {
                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }

    private function walled(string $class): bool
    {
        $reflection = new ReflectionClass($class);
        $traits = [];

        while ($reflection !== false) {
            $traits = [...$traits, ...$reflection->getTraitNames()];
            $reflection = $reflection->getParentClass();
        }

        return in_array(ScopedToUserDealers::class, $traits, true);
    }
}
