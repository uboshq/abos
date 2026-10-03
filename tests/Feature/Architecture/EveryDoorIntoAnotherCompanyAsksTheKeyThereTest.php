<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * অন্য কোম্পানিতে কাজ করার প্রতিটা দরজা ওই কোম্পানির চাবি জিজ্ঞেস করে — ⛔২১-এর শেষ যাচাই, ১ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ঘটেছিল ───────────────────────────────────────────────────────
 * ৩০ সেপ্টেম্বর: A-র চাবিতে B-র শাখা বদলানো যেত (c143678f)। ১ অক্টোবর: A-র হিসাবরক্ষক B-র
 * কেবল সাধারণ সদস্য হয়েও আন্তঃকোম্পানি লেনদেনে B-র খাতায় দাখিলা বসাতে পারতেন। দুবারই একই
 * ছাঁচ: [[CompanyContext::forCompany()]] দিয়ে অন্য কোম্পানিতে ঢোকা, আর চাবি জিজ্ঞেস কেবল চলতি
 * কোম্পানিতে।
 *
 * ── ⭐ এই দাবি যা ধরে ──────────────────────────────────────────────────
 * ওয়েবের পথে (Http আর মডিউলের সেবা) যেখানেই অন্য কোম্পানিতে ঢোকা হয়, সেই ফাইল এই তালিকায়
 * থাকতে হবে, আর হয় [[User::canInCompany()]] ডাকতে হবে, নয় কারণটা লিখে রাখতে হবে। নতুন দরজা
 * যোগ হলে দাবিটা লাল হয় — কেউ একবার পড়ে দেখার আগে সেটা সবুজ হয় না।
 */
final class EveryDoorIntoAnotherCompanyAsksTheKeyThereTest extends TestCase
{
    /**
     * ফাইল → কারণ। `null` মানে ফাইলটা নিজেই `canInCompany()` ডাকে।
     *
     * @var array<string, string|null>
     */
    private const DOORS = [
        'app/Modules/Accounts/Http/Controllers/InterCompanyController.php' => null,
        'app/Modules/Accounts/Services/InterCompanyService.php' => null,
        'app/Modules/SystemAdmin/Http/Controllers/CompanyController.php' => null,
        'app/Modules/SystemAdmin/Http/Controllers/BranchController.php' => null,
        'app/Modules/SystemAdmin/Http/Controllers/UserController.php' => 'অন্য কোম্পানি কেবল যেখানে কর্তা নিজে সুপার অ্যাডমিন (companiesWithinReach); বাকিগুলো কেবল গুদামের নাম পড়া',
        'app/Modules/SystemAdmin/Services/BranchDesk.php' => 'কেবল BranchController/CompanyController ডাকে, আর ওরা আগে canInCompany জিজ্ঞেস করে',
        'app/Modules/SystemAdmin/Services/ScheduledReportRunner.php' => 'ক্রন — কোনো মানুষ নেই, নির্ধারিত রিপোর্ট যার নামে তার কোম্পানিতেই চলে',
        'app/Modules/Approval/Services/MoneyFlowDefaults.php' => 'নতুন কোম্পানির ডিফল্ট ছক বসানো — প্রভিশনিং, কারও চাবিতে নয়',
        'app/Modules/Approval/Services/OwnerSignsDiscounts.php' => 'ছাড়ে মালিকের সইয়ের ছক বসানো — নতুন কোম্পানির প্রভিশনিং আর একবারের মাইগ্রেশন, কারও চাবিতে নয়',
        'app/Modules/Inventory/Services/PackBackfill.php' => 'কনসোলের ব্যাকফিল — মানুষ নেই',
        'app/Core/Services/CompanyProvisioner.php' => 'নতুন কোম্পানি খোলা — মালিক/প্রভিশনিং',
        'app/Core/Services/Ownership.php' => 'মালিকের সুপার ক্ষমতা সব কোম্পানিতে (ABOS_OWNER_EMAILS)',
        'app/Core/Services/PermissionSyncer.php' => 'চাবির তালিকা সব কোম্পানিতে মেলানো — ডিপ্লয়/কনসোল',
    ];

    public function test_every_door_into_another_company_is_known_and_asks_the_key_there(): void
    {
        $found = [];

        foreach (['app/Http', 'app/Core/Services', ...glob(base_path('app/Modules/*/Http'), GLOB_ONLYDIR) ?: [], ...glob(base_path('app/Modules/*/Services'), GLOB_ONLYDIR) ?: []] as $dir) {
            $dir = str_starts_with($dir, base_path()) ? $dir : base_path($dir);

            foreach (File::allFiles($dir) as $file) {
                if (str_contains($file->getContents(), 'CompanyContext::forCompany(')) {
                    $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
                }
            }
        }

        // ⚠️ দাবিটা সত্যিই তাকিয়েছে — জানা দরজাগুলোর অন্তত একটা পাওয়া চাই
        $this->assertContains('app/Modules/Accounts/Services/InterCompanyService.php', $found, 'প্রস্তুতিটাই ভুল — খোঁজা কিছু পায়নি।');

        foreach (array_unique($found) as $path) {
            $this->assertArrayHasKey($path, self::DOORS, "⛔ {$path} অন্য কোম্পানিতে ঢোকে, অথচ তালিকায় নেই — ওই কোম্পানির চাবি জিজ্ঞেস করে কি না দেখে এখানে যোগ করুন।");
        }

        foreach (self::DOORS as $path => $reason) {
            $this->assertFileExists(base_path($path), "তালিকার {$path} আর নেই — সারিটা সরান।");

            if ($reason === null) {
                $this->assertStringContainsString('->canInCompany(', File::get(base_path($path)), "⛔ {$path} অন্য কোম্পানিতে কাজ করে, অথচ ওই কোম্পানির চাবি জিজ্ঞেস করে না।");
            }
        }
    }
}
