<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Integrity\IntegrityCheck;
use App\Core\Integrity\IntegrityRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দুইটা যাচাই, একটাই নাম।
 *
 * ── কীভাবে ধরা পড়ল ─────────────────────────────────────────────────
 * চালু সাইটে যাচাইয়ের পর্দাটা খুলে দেখা গেল আটটা সারির দুই জোড়ার
 * নাম হুবহু এক: "বিলের মোট লাইনের সাথে মেলে" দুইবার, "নিশ্চিত বিল
 * খাতায় পৌঁছেছে" দুইবার। একটা বিক্রয়ের, একটা ক্রয়ের — কিন্তু পর্দা
 * পড়ে তা বোঝার কোনো উপায় ছিল না।
 *
 * সব সবুজ থাকতে এটা নিরীহ দেখায়। একটা লাল হলে নয়: "বিলের মোট মিলছে
 * না" পড়ে মানুষ জানতেন না কোন খাতায় খুঁজতে হবে, আর দুই মডিউলের দুই
 * তালিকা ঘেঁটে দেখতে হত।
 *
 * ── কেন ইংরেজিতে সমস্যাটা ছিল না, তবু দুই ভাষাতেই পরখ করা হয় ────────
 * ইংরেজিতে "invoice" আর "bill" আলাদা শব্দ, তাই ওখানে নামগুলো এমনিতেই
 * আলাদা ছিল — কাকতালীয়ভাবে। বাংলায় দুইটাই "বিল"। কেবল যে ভাষায়
 * সমস্যা হয়েছিল সেটা পরখ করলে অন্য ভাষায় একই ভুল কাল আবার ঢুকত।
 */
class TwoChecksWithOneNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }

    /**
     * @return array<string, list<string>> নাম → যে চাবিগুলো ওই নামে
     */
    private function labelsIn(string $locale): array
    {
        $was = app()->getLocale();
        app()->setLocale($locale);

        $byLabel = [];

        foreach (app(IntegrityRegistry::class)->all() as $key => $check) {
            $byLabel[$check->label][] = $key;
        }

        app()->setLocale($was);

        return $byLabel;
    }

    public function test_no_two_checks_read_the_same_in_bangla(): void
    {
        $this->assertNoCollision('bn');
    }

    public function test_no_two_checks_read_the_same_in_english(): void
    {
        $this->assertNoCollision('en');
    }

    /**
     * নামটা অনুবাদই হয়েছে — কাঁচা চাবি নয়।
     *
     * অনুবাদ না থাকলে `sales::integrity.invoice_total` লেখাটাই পর্দায়
     * বসত, আর ওটা প্রতিটার আলাদা বলে উপরের পরীক্ষা দুইটা পাশ করেই
     * যেত — অথচ পর্দাটা পড়া যেত না।
     */
    public function test_every_check_has_a_real_name(): void
    {
        foreach (app(IntegrityRegistry::class)->all() as $key => $check) {
            $this->assertStringNotContainsString('::', $check->label,
                "যাচাই '{$key}'-এর নামের জায়গায় অনুবাদের চাবিটাই বসছে।");

            $this->assertNotSame('', trim($check->question), "যাচাই '{$key}' কী জিজ্ঞেস করছে তা লেখা নেই।");
            $this->assertNotSame('', trim($check->whenBroken), "যাচাই '{$key}' ভাঙলে কী হয় তা লেখা নেই।");
        }
    }

    /** যাচাইগুলো সত্যিই আছে — খালি তালিকায় উপরের সবকিছুই পাশ করত। */
    public function test_there_are_checks_to_compare(): void
    {
        $this->assertGreaterThan(4, count(app(IntegrityRegistry::class)->all()));
    }

    private function assertNoCollision(string $locale): void
    {
        $clashes = array_filter($this->labelsIn($locale), fn (array $keys) => count($keys) > 1);

        $message = implode('; ', array_map(
            fn (string $label, array $keys) => "\"{$label}\" ← ".implode(' + ', $keys),
            array_keys($clashes), $clashes,
        ));

        $this->assertSame([], $clashes,
            "একই নামে একাধিক যাচাই ({$locale}): {$message}। "
            .'একটা লাল হলে কোনটা তা পড়ে বোঝা যেত না।');
    }

    /** নামগুলো সত্যিই IntegrityCheck থেকে আসছে — গঠন বদলালে টেস্টটা যেন ভাঙে। */
    public function test_the_registry_returns_checks(): void
    {
        $this->assertContainsOnlyInstancesOf(
            IntegrityCheck::class, app(IntegrityRegistry::class)->all()
        );
    }
}
