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
        // ⭐ অফিসের SMS ফোন (মালিকের "ঠিক আছে", নমুনা U1X687u5MkwVfEL7ci7MGv) — মানুষ নেই, ফোনের নিজের গোপন চাবি (smsgw_, sha256-এ রাখা)
        'app/Core/Services/SmsOutboxService.php' => 'অফিসের SMS ফোন — মানুষ নেই, কেবল ফোনের নিজের কোম্পানিতে ঢোকে (চাবি sha256-এ মিলিয়ে পাওয়া ফোনের company_id); '
            .'নেয় কেবল সেই ফোনের বার্তা, জানায় কেবল নিজের নেওয়া সারির ফল (AnSmsHadNoWayOutOfTheOfficeTest)',
        // ⭐ পার্টিকে বার্তা — Meta-র ফেরত-খবর (মালিকের "ok", ২৩ নভেম্বর ২০২৬; 1.3.10-এর লাল)
        'app/Core/Services/PartyMessageDispatcher.php' => 'Meta-র webhook (onCloudStatus) — মানুষ নেই; ঠিকানার কোম্পানির app secret-এ সই মিললে (WhatsAppWebhookController) কেবল '
            .'সেই কোম্পানিতে ঢোকে, আর কেবল সেই কোম্পানির নিজের পাঠানো বার্তার (provider_ref) অবস্থা বদলায়; কারও চাবিতে কিছু খোলে না',
        'app/Modules/Notification/Http/Controllers/WhatsAppWebhookController.php' => 'Meta-র webhook-এর দরজা — মানুষ নেই, লগইন নেই; ঠিকানার কোম্পানিতে ঢুকে কেবল সেই কোম্পানির verify token (GET) বা app secret-এর HMAC সই (POST) মেলায়, না মিললে ৪০৩ — কিছু পড়ে না, কিছু ফেরায় না',
        // ⭐ বিক্রির দায়িত্ব (মালিক, ২৩ নভেম্বর ২০২৬)
        'app/Modules/Sales/Services/SalesResponsibility.php' => 'কাগজের নিজের কোম্পানির একটা সেটিং পড়া (sales.default_sales_person) — চলতি কোম্পানি আলাদা হলে (কনসোলের sales:assign-responsible, যা প্রতিটা কোম্পানি ধরে চলে) কেবল পড়তে সেখানে বসে; '
            .'কিছু লেখে না, আর মানুষটা সেই কোম্পানির সদস্য না হলে বসায় না (isMember)',
        'app/Core/Services/WhatsAppCloud.php' => 'Meta-র webhook-এর দেহ (receive) — মানুষ নেই; সই আগেই ঠিকানার কোম্পানির app secret-এ মেলানো (WhatsAppWebhookController), '
            .'তারপর কেবল সেই কোম্পানিতে ঢুকে তার নিজের নম্বর/অ্যাকাউন্টের ঘটনা নেয় (phone_number_id, waba_id মিলিয়ে); বার্তার অবস্থা আর লেখার অনুমোদন ছাড়া কিছু বদলায় না',
        'app/Modules/Accounts/Http/Controllers/InterCompanyController.php' => null,
        'app/Modules/Accounts/Services/InterCompanyService.php' => null,
        // ⓘ দলগত রিপোর্ট — প্রতিটা কোম্পানিতে শাখার নাগাল পড়তে ঢোকে; কোম্পানির তালিকাই আগে সেখানের চাবি দেখে (পুনঃঅডিট, ৯ অক্টোবর ২০২৬)
        'app/Modules/Accounts/Services/GroupLedgerService.php' => null,
        // ⭐ মালিকের কেন্দ্র — প্রতিটা কোম্পানিতে `executive.view` ঐ কোম্পানিতে বসে জিজ্ঞেস করে (৯ অক্টোবর ২০২৬)
        'app/Modules/Executive/Services/CompanyLens.php' => null,
        'app/Modules/Executive/Services/Snapshots.php' => 'রাতের ক্রন — মানুষ নেই, প্রতিটা কোম্পানির নিজের super_admin-এর চোখে লেখে; '
            .'পড়ার সময় কেবল CompanyLens-এর কোম্পানি (যেখানে executive.view আছে), আর শাখার সীমা পড়তে প্রসঙ্গে বসে',
        'app/Modules/SystemAdmin/Http/Controllers/CompanyController.php' => null,
        'app/Modules/SystemAdmin/Http/Controllers/BranchController.php' => null,
        'app/Modules/SystemAdmin/Http/Controllers/BranchModuleController.php' => null,
        'app/Modules/SystemAdmin/Http/Controllers/UserController.php' => 'অন্য কোম্পানি কেবল যেখানে কর্তা নিজে সুপার অ্যাডমিন (companiesWithinReach); বাকিগুলো কেবল গুদামের নাম পড়া',
        'app/Modules/SystemAdmin/Services/BranchDesk.php' => 'কেবল BranchController/CompanyController ডাকে, আর ওরা আগে canInCompany জিজ্ঞেস করে',
        'app/Modules/SystemAdmin/Services/ScheduledReportRunner.php' => 'ক্রন — কোনো মানুষ নেই, নির্ধারিত রিপোর্ট যার নামে তার কোম্পানিতেই চলে',
        // ⭐ চাকরি ছাড়ায় লগইন বন্ধ — অন্য কোম্পানির সদস্যপদ কেবল আছে কি না দেখা (লগইন খোলা রাখতে), কিছু পড়া বা লেখা নয় (a6, ২১ অক্টোবর ২০২৬)
        'app/Modules/Hr/Services/LeaversLoseTheirLogin.php' => 'চাকরি ছাড়া কর্মীর অন্য কোম্পানিতে চালু সদস্যপদ আছে কি না — কেবল exists(); থাকলে লগইন খোলা থাকে, এই কোম্পানির প্রবেশই বন্ধ হয়; রাতের ক্রনও চালায়, মানুষ নেই',
        'app/Modules/Approval/Services/MoneyFlowDefaults.php' => 'নতুন কোম্পানির ডিফল্ট ছক বসানো — প্রভিশনিং, কারও চাবিতে নয়',
        'app/Modules/Approval/Services/OwnerSignsDiscounts.php' => 'ছাড়ে মালিকের সইয়ের ছক বসানো — নতুন কোম্পানির প্রভিশনিং আর একবারের মাইগ্রেশন, কারও চাবিতে নয়',
        'app/Modules/Inventory/Services/PackBackfill.php' => 'কনসোলের ব্যাকফিল — মানুষ নেই',
        'app/Core/Services/CompanyProvisioner.php' => 'নতুন কোম্পানি খোলা — মালিক/প্রভিশনিং',
        'app/Http/Controllers/Api/AuthController.php' => 'টোকেন নবায়নে দুই ধাপ বাধ্যতামূলক কি না পড়া — কেবল মানুষটার নিজের চলতি কোম্পানিতে, শুধু পড়া, কিছু লেখা নয় (পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬)',
        'app/Modules/Sales/Services/TrackingNotices.php' => 'ঘটনার শ্রোতা — কোনো মানুষ নেই; চালানটা যে কোম্পানির, কেবল সেই কোম্পানির ভেতরে চলে (CompanyContext::forCompany) আর সেই কোম্পানিরই লেখক, অনুমোদনকারী ও মালিককে জানায়',
        // ⭐ নিট সম্পদ বিবরণী (ড্যা-৮, ২৫ অক্টোবর; মূল লাইনে ৮ নভেম্বর ২০২৬)
        'app/Modules/Accounts/Services/NetWorthStatement.php' => 'কেবল মালিক — প্রতিটা কোম্পানিতে ঢোকার আগে সেখানে Ownership::isOwnerIn() জিজ্ঞেস করে (mayView); যেখানে মালিক নন, সেটা যোগে আসে না, কেবল নাম ধরে "দেখা যাচ্ছে না"',
        'app/Core/Services/Ownership.php' => 'মালিকের সুপার ক্ষমতা সব কোম্পানিতে (ABOS_OWNER_EMAILS)',
        'app/Core/Services/PermissionSyncer.php' => 'চাবির তালিকা সব কোম্পানিতে মেলানো — ডিপ্লয়/কনসোল',
        // ⭐ সময়-বাঁধা রোল (রোলের পাতার নমুনা, মালিক "ok", ২০ নভেম্বর ২০২৬)
        'app/Core/Services/RoleTerms.php' => 'লগইনের মুহূর্তে (expireFor) কেবল লগইন করা মানুষটার নিজের মেয়াদ পেরোনো রোল তোলে, প্রতিটা সারির নিজের কোম্পানিতে — '
            .'কারও চাবিতে কিছু খোলে না, কেবল কমে; কোনো মানুষ অন্য কোম্পানির কিছু বদলাতে পারেন না (রাতের abos:role-terms-এর একই কাজ)',
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
