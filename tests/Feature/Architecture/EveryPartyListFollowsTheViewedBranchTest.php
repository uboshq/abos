<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * গ্রাহক আর সরবরাহকারীর প্রতিটা তালিকা আর পিকার হেডারে বাছা শাখা মানে — ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কেন ─────────────────────────────────────────────────────────────
 * মালিকের প্রশ্ন: *"সব শাখার পার্টি এক জায়গায় কেন দেখায়?"* দেয়ালটা মডেলে নয়
 * ([[Customer::scopeInViewedBranch()]]-এর কারণ দেখুন), তাই প্রতিটা তালিকাকে নিজে ডাকতে
 * হয় — আর নতুন একটা ফর্ম কাল ভুলে যাবে। এই দাবি সেটা ধরে।
 *
 * ⓘ মাপ: কন্ট্রোলার আর ড্যাশবোর্ডে (মন্তব্য বাদে) `Customer::query()`/`Supplier::query()`
 * দিয়ে শুরু প্রতিটা বাক্য `inViewedBranch()` ডাকে, নয়তো নিচে কারণসহ ছাড় পায়।
 * ⚠️ একটা নাম ধরে খোঁজা (`find`, `exists`) তালিকা নয় — কাগজ অন্য শাখার পক্ষ খুঁজে পেতেই হবে।
 */
final class EveryPartyListFollowsTheViewedBranchTest extends TestCase
{
    /** @var array<string, string> ফাইল => কারণ (ফাইলের প্রতিটা ছাড়-পাওয়া বাক্যের জন্য একবার) */
    private const EXCUSED = [
        'app/Modules/Sales/Http/Controllers/PortalController.php' => 'গ্রাহক-পোর্টালের লগইন — গ্রাহক নিজে ঢুকছেন, কর্মীর হেডার নেই',
        'app/Modules/Sales/Http/Controllers/SalesOrderController.php' => 'একজনকে নাম ধরে খোঁজা (find) — তালিকা নয়',
        'app/Modules/Supplier/Http/Controllers/SupplierController.php' => 'একজন সরবরাহকারীর ধরন আছে কি না (exists) — তালিকা নয়',
        // ⓘ ৬ অক্টোবর ২০২৬ (ec, পাহারার মিথ্যা লাল) — প্রতিটা লাইন পড়ে মেলানো: একজনকে চাবি ধরে খোঁজা, বা আগেই শাখায়
        //   ছাঁকা আইডির নাম আনা; কোনোটাই বাছাইয়ের তালিকা নয়
        'app/Modules/Sales/Http/Controllers/DepositRequestController.php' => 'একজন গ্রাহককে ঠিকানার চাবি (uuid/id) ধরে খোঁজা — তালিকা নয়',
        'app/Modules/Sales/Http/Controllers/OrderStandingController.php' => 'একজন গ্রাহককে ঠিকানার চাবি (uuid/id) ধরে খোঁজা — তালিকা নয়',
        'app/Modules/Sales/Http/Controllers/DirectSaleApiController.php' => 'ফোনের পাঠানো একজন গ্রাহক public_id ধরে, আর বাছা সরবরাহকারীদের id→public_id মানচিত্র — তালিকা নয়',
        'app/Modules/Sales/Http/Controllers/DirectSaleController.php' => 'জমা দেওয়া একজন গ্রাহককে id ধরে খোঁজা (find) — তালিকা নয়',
        'app/Modules/Sales/Http/Controllers/SalesReturnApiController.php' => 'ফোনের পাঠানো একজন গ্রাহক public_id ধরে — তালিকা নয়',
        'app/Modules/Customer/Dashboard/CustomerTradeCharts.php' => 'চার্টের নাম — আইডিগুলো আগেই দেখা শাখার বিক্রি আর বকেয়া থেকে বাছা; গোনার তালিকা নিজে inViewedBranch()',
        'app/Modules/Supplier/Dashboard/SupplierCharts.php' => 'চার্টের নাম — আইডিগুলো আগেই দেখা শাখায় ছাঁকা ক্রয় থেকে ([[DataScope::inView()]])',
        'app/Modules/Accounts/Dashboard/AccountsDashboard.php' => 'কেবল মন্তব্যে নাম আছে, কোডে নয়',
    ];

    public function test_every_party_list_asks_for_the_viewed_branch(): void
    {
        $blind = [];
        $seen = 0;

        foreach ($this->files() as $path) {
            $code = $this->codeOf(base_path($path));

            foreach (preg_split('/;/', $code) as $statement) {
                if (preg_match('/\b(Customer|Supplier)::query\(\)/', $statement) !== 1) {
                    continue;
                }

                $seen++;

                if (str_contains($statement, 'inViewedBranch()') || array_key_exists($path, self::EXCUSED)) {
                    continue;
                }

                $blind[] = $path.' — '.trim(preg_replace('/\s+/', ' ', substr($statement, 0, 160)));
            }
        }

        $this->assertGreaterThan(20, $seen, 'খোঁজাটাই ভেঙেছে — পক্ষের তালিকা পাওয়া গেছে মাত্র '.$seen.'টা।');
        $this->assertSame([], $blind, implode("\n", [
            'এই তালিকা/পিকারগুলো হেডারে বাছা শাখা মানে না:', '', ...$blind, '',
            '`->inViewedBranch()` বসান — বা নাম-ধরে-খোঁজা হলে কারণসহ EXCUSED-এ তুলুন।',
        ]));
    }

    public function test_no_excuse_is_stale(): void
    {
        foreach (array_keys(self::EXCUSED) as $path) {
            $this->assertFileExists(base_path($path), "ছাড়ের ফাইল আর নেই: {$path}");
        }
    }

    /** @return list<string> */
    private function files(): array
    {
        $root = base_path();
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app/Modules', \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (str_ends_with($path, '.php') && (str_contains($path, '/Http/Controllers/') || str_contains($path, '/Dashboard/'))) {
                $out[] = $path;
            }
        }

        sort($out);

        return $out;
    }

    private function codeOf(string $file): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
