<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\Company;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ছাপার কাগজে লোগো বসত না, আর কোথাও কোনো ভুল ছিল না।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * মালিকের রিপোর্ট, ২২ আগস্ট: *"A4 ছাপায় লোগো ভাঙা"*।
 *
 * কোড পড়ে কিছুই পাওয়া যায় না — `Company::logoData()` ছবিটা base64 করে
 * সরাসরি HTML-এ বসায়, পথ নয়, তাই প্রিন্টারে ভাঙার কথাই নয়। লোকাল
 * মেশিনে সব ঠিক দেখাত।
 *
 * চালু সার্ভারে জিজ্ঞেস করে বেরোল: **`logoData()` সেখানে `null`**।
 * `storage/app/public/` পুরো খালি — কোনো `logos/` ফোল্ডারই নেই।
 *
 * কারণটা সিডারে: সে `logo_path` বসাত (`logos/Trade Depot.png`), কিন্তু
 * **ফাইলটা বসাত না**। ফাইলটা ছিল কেবল সেই মেশিনে যেখানে একদিন হাতে
 * আপলোড হয়েছিল, আর `storage/app/public/*` gitignored — তাই সার্ভারে
 * কোনোদিন পৌঁছাত না।
 *
 * অর্থাৎ ডাটাবেজের সারিটা একটা পথ দেখাত যেখানে কিছু নেই, আর ফলটা ছিল
 * নীরব: `logoData()` `null` ফেরাত, ব্লেড `@if ($logo)` দেখে চুপ করে
 * যেত, আর কাগজটা লোগো ছাড়াই ছাপা হত।
 *
 * ── এই পরীক্ষাটা কী দাবি করে ────────────────────────────────────────
 * সিডার যদি একটা পথ ঘোষণা করে, সেই পথে সত্যিই কিছু থাকতে হবে।
 * সহজ দাবি, কিন্তু এটা না থাকায় ভুলটা ধরা পড়েছে ছাপার কাগজে —
 * ছাপা হয়ে যাওয়ার পরে।
 */
class ThePrintedPageHadNoLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    /**
     * প্রতিটা ঘোষিত পথে সত্যিই একটা ফাইল আছে।
     *
     * এটাই আসল দাবি। সারিটা মিথ্যা বললে বাকি সবকিছু ঠিক থেকেও কাগজে
     * লোগো বসে না।
     */
    public function test_every_company_that_claims_a_logo_really_has_the_file(): void
    {
        $claimed = Company::query()->whereNotNull('logo_path')->get();

        $this->assertNotEmpty($claimed, 'কোনো কোম্পানিই লোগোর দাবি করছে না — পরীক্ষাটা কিছুই প্রমাণ করছে না।');

        foreach ($claimed as $company) {
            $this->assertTrue(
                Storage::disk('public')->exists((string) $company->logo_path),
                "{$company->code}-এর সারি বলছে ফাইলটা '{$company->logo_path}'-এ আছে, ".
                'অথচ ওখানে কিছু নেই — ছাপার কাগজ লোগো ছাড়াই বেরোবে, নীরবে।',
            );
        }
    }

    /**
     * আর ছবিটা সত্যিই ছাপার মতো — খালি ফাইল নয়।
     *
     * ফাইলটা থাকা আর ছবি হওয়া এক নয়। শূন্য বাইটের একটা ফাইল থাকলেও
     * `exists()` সত্যি বলত, আর `logoData()` তবু `null` ফেরাত।
     */
    public function test_the_logo_turns_into_something_a_page_can_carry(): void
    {
        foreach (Company::query()->whereNotNull('logo_path')->get() as $company) {
            $data = $company->logoData();

            $this->assertNotNull($data, "{$company->code}-এর লোগো ছাপার মতো কিছুতে পরিণত হচ্ছে না।");
            $this->assertStringStartsWith('data:image/', (string) $data);
            $this->assertGreaterThan(1000, strlen((string) $data),
                "{$company->code}-এর ছবিটা এত ছোট যে ওটা ছবি নয়।");
        }
    }

    /**
     * লোগো না থাকা কোম্পানিতে ছাপা তবু চলে।
     *
     * নতুন কোম্পানি প্রথম দিন লোগো ছাড়াই থাকে। ওখানে ব্যতিক্রম ছুঁড়লে
     * প্রথম বিলটাই ছাপা যেত না।
     */
    public function test_a_company_without_a_logo_still_prints(): void
    {
        $company = Company::query()->whereNull('logo_path')->first()
            ?? Company::query()->firstOrFail();

        $company->forceFill(['logo_path' => null])->saveQuietly();

        $this->assertNull($company->fresh()->logoData());
    }
}
