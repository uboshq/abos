<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * প্রতিটা `exists` যাচাই নিজের কোম্পানির নাম বলে — চূড়ান্ত অডিট ⛔১০, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── কেন এই পাহারাটা লাগল ────────────────────────────────────────────
 * [[EveryRawQueryNamesItsCompanyTest]] কেবল `DB::table()` আর তার ভাইদের পড়ে। কিন্তু
 * `'exists:accounts,id'` আর `Rule::exists('accounts', 'id')`-ও ঠিক সেই কাঁচা কোয়েরিতে চলে —
 * কোম্পানির গ্লোবাল স্কোপ সেখানে নেই। ফলে ঋণ, সম্পদ, স্থানান্তর, মিলকরণের ফর্মে **অন্য
 * কোম্পানির** খাত বা টিলের id যাচাই পার হত ([[TheMoneyFormsTookAnotherCompanysRecordsTest]])।
 *
 * ── কী পড়া হয় ───────────────────────────────────────────────────────
 * `app/`-এর প্রতিটা ফাইলে:
 *   • `'exists:টেবিল,…'` — লেখার এই রূপে কোম্পানি বসানোই যায় না, তাই টেবিলে `company_id`
 *     থাকলে এটা নিজেই ফাঁক।
 *   • `Rule::exists('টেবিল', …)` আর তার পরের `->…()` শিকল — শিকলে `company_id` না থাকলে ফাঁক।
 *   • `users` — এই টেবিলে কোম্পানি নেই (সম্পর্ক `company_user`-এ), তাই `users` সরাসরি কখনো
 *     নয়; `Rule::exists('company_user', 'user_id')->where('company_id', …)`।
 * টেবিলে `company_id` আছে কি না সেটা আসল স্কিমা থেকে — অনুমান থেকে নয়।
 */
class EveryExistsRuleNamesItsCompanyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * এখনো সারানো হয়নি — ফাইল ধরে কয়টা, আর কেন অপেক্ষায়।
     *
     * ⚠️ সংখ্যাটা কেবল কমে। নতুন একটা যোগ হলে, বা সারানোর পরে সংখ্যা না কমালে, দাবি লাল।
     * ⓘ চূড়ান্ত অডিটের পরের ধাপ — Accounts আগে (টাকার ফর্ম), বাকিগুলো একে একে।
     */
    private const NOT_YET = [
        'app/Modules/Approval/Http/Controllers/ApprovalDelegationController.php' => 1,
        'app/Modules/Finance/Http/Controllers/DepositController.php' => 6,
        'app/Modules/Finance/Http/Controllers/HandLoanController.php' => 2,
        'app/Modules/Finance/Http/Controllers/WithdrawalController.php' => 1,
        'app/Modules/Inventory/Http/Controllers/StockCountController.php' => 1,
        'app/Modules/Inventory/Http/Controllers/StorageLocationController.php' => 1,
        'app/Modules/MasterData/Http/Controllers/LocationController.php' => 1,
        'app/Modules/Promotion/Http/Controllers/PromotionSuggestController.php' => 1,
        'app/Modules/Sales/Http/Controllers/DepositClaimController.php' => 1,
        'app/Modules/Sales/Http/Controllers/PortalController.php' => 1,
        'app/Modules/SystemAdmin/Http/Controllers/OwnershipController.php' => 1,
    ];

    public function test_no_exists_rule_takes_an_id_from_another_company(): void
    {
        // ⚠️ পাহারাটা সত্যিই স্কিমা দেখছে — না দেখলে সব টেবিল "কোম্পানিহীন" হত আর সবুজ মিথ্যা
        $this->assertTrue(Schema::hasColumn('accounts', 'company_id'), 'স্কিমাই পড়া যায়নি — পাহারাটা অন্ধ।');
        $this->assertFalse(Schema::hasColumn('users', 'company_id'), '`users`-এ company_id এসেছে — নিয়মটা আবার ভাবুন।');

        $found = $this->holes();

        $grew = [];

        foreach ($found as $file => $holes) {
            $allowed = self::NOT_YET[$file] ?? 0;

            if (count($holes) > $allowed) {
                $grew[] = "{$file} — ".count($holes)." (ছাড় {$allowed}):\n    ".implode("\n    ", $holes);
            }
        }

        $this->assertSame([], $grew,
            "এই ফাইলগুলোর `exists` অন্য কোম্পানির id মেনে নেয়। `Rule::exists('t', 'id')->where('company_id', CompanyContext::id())` লিখুন; ব্যবহারকারী হলে `company_user`।\n".implode("\n", $grew));
    }

    /** ⭐ ছাড়-তালিকা বাসি হয় না — সারানো ফাইলের সংখ্যা নামাতে হয়। */
    public function test_the_not_yet_list_shrinks_as_files_are_fixed(): void
    {
        $found = $this->holes();
        $stale = [];

        foreach (self::NOT_YET as $file => $allowed) {
            $now = count($found[$file] ?? []);

            if ($now < $allowed) {
                $stale[] = "{$file}: ছাড় {$allowed}, আছে {$now}";
            }
        }

        $this->assertSame([], $stale, "সারানো হয়েছে — NOT_YET-এর সংখ্যা নামান:\n".implode("\n", $stale));
    }

    /** ⚠️ পাহারাটা নিজে দেখে কি না — জানা দুই ধরনের ফাঁক ধরতেই হবে। */
    public function test_the_guard_catches_both_spellings(): void
    {
        $holes = $this->holesIn(<<<'PHP'
            'a' => ['exists:accounts,id'],
            'b' => [Rule::exists('accounts', 'id')],
            'c' => [Rule::exists('accounts', 'id')->where('company_id', $id)],
            'd' => [Rule::exists('users', 'id')],
            'e' => [Rule::exists('company_user', 'user_id')->where('company_id', $id)],
            PHP);

        $this->assertSame([
            "'exists:accounts,…'",
            "Rule::exists('accounts') — শিকলে company_id নেই",
            "Rule::exists('users') — company_user দিয়ে",
        ], $holes);
    }

    /** @return array<string, list<string>> */
    private function holes(): array
    {
        $found = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $holes = $this->holesIn($file->getContents());

            if ($holes !== []) {
                $found[str_replace('\\', '/', 'app/'.$file->getRelativePathname())] = $holes;
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function holesIn(string $source): array
    {
        $holes = [];

        preg_match_all("/'exists:([a-z0-9_]+)/", $source, $plain);

        foreach ($plain[1] as $table) {
            if ($table === 'users' || $this->hasCompany($table)) {
                $holes[] = "'exists:{$table},…'";
            }
        }

        preg_match_all("/Rule::exists\(\s*'([a-z0-9_]+)'/", $source, $rich, PREG_OFFSET_CAPTURE);

        foreach ($rich[1] as $i => [$table]) {
            $chain = $this->chainFrom($source, $rich[0][$i][1]);

            if ($table === 'users') {
                $holes[] = "Rule::exists('users') — company_user দিয়ে";
            } elseif ($this->hasCompany($table) && ! str_contains($chain, 'company_id')) {
                $holes[] = "Rule::exists('{$table}') — শিকলে company_id নেই";
            }
        }

        return $holes;
    }

    /** `Rule::exists(...)` থেকে শিকলের শেষ পর্যন্ত — `->where(...)` যত লাইনেই থাকুক। */
    private function chainFrom(string $source, int $at): string
    {
        $i = strpos($source, '(', $at);
        $depth = 0;
        $length = strlen($source);

        while ($i !== false && $i < $length) {
            $c = $source[$i];

            if ($c === '(') {
                $depth++;
            } elseif ($c === ')' && --$depth === 0) {
                $next = $i + 1;

                while ($next < $length && ctype_space($source[$next])) {
                    $next++;
                }

                if (substr($source, $next, 2) !== '->') {
                    return substr($source, $at, $i + 1 - $at);
                }

                $i = strpos($source, '(', $next);
                $depth = 0;

                continue;
            }

            $i++;
        }

        return substr($source, $at);
    }

    /** @var array<string, bool> */
    private array $companyTables = [];

    private function hasCompany(string $table): bool
    {
        return $this->companyTables[$table] ??= Schema::hasTable($table) && Schema::hasColumn($table, 'company_id');
    }
}
