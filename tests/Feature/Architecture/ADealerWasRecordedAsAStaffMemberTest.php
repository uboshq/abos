<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * ⛔ কোরের ইঞ্জিন আর সার্ভিসে `auth()->id()` নেই — ২ অক্টোবর ২০২৬।
 *
 * ── কেন ───────────────────────────────────────────────────────────────
 * ডিলার পোর্টালে (`auth:portal`) ঢোকা মানুষটা গ্রাহক, আর `auth()->id()` তখন গ্রাহকের id দেয়। নিরীক্ষার
 * `user_id`-তে সেটা বসায় দোকানির জমার দাবি একই নম্বরের কর্মীর নামে চড়ত (গ্রাহক ১ → ব্যবহারকারী ১ = মালিক)।
 * ধরা পড়ে [[TheDepositRequestCameWithItsSlipTest]]-এ। কোর জানে না কোন দরজা দিয়ে ডাকা হচ্ছে — তাই কোরে
 * কেবল [[Actor::userId()]]: কর্মী হলে id, নাহলে null।
 *
 * ⓘ মন্তব্য পড়া হয় না (মন্তব্যে নামটা লেখা থাকতেই পারে); আর পাহারা নিজে বিপজ্জনক লেখা খেয়ে দেখে।
 */
final class ADealerWasRecordedAsAStaffMemberTest extends TestCase
{
    private const DIRS = ['app/Core/Engines', 'app/Core/Services'];

    /**
     * ⭐ পোর্টালের পথে পড়া মডিউলের ফাইল — দোকানির স্ক্যান অবস্থা-সারি বানাতে পারে (`ensure()` → `write()`),
     * রওনা হলে গেট পাসও; দাবি তোলেন দোকানি। ⓘ বাকি মডিউলগুলো কেবল কর্মীর পথে, তাই তালিকায় নেই।
     */
    private const PORTAL_PATH = [
        'app/Modules/Sales/Services/DeliveryStageService.php',
        'app/Modules/Sales/Services/GatePassService.php',
        'app/Modules/Sales/Services/DepositClaimService.php',
        'app/Modules/Sales/Services/DepositSlip.php',
        'app/Modules/Sales/Services/DispatchBill.php',
        'app/Modules/Sales/Http/Controllers/PortalController.php',
        'app/Modules/Sales/Http/Controllers/DeliveryScanController.php',
    ];

    public function test_no_core_engine_or_service_asks_auth_for_a_bare_id(): void
    {
        $found = [];
        $looked = 0;

        foreach (self::DIRS as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $looked++;

                foreach ($this->hits((string) file_get_contents($file->getPathname())) as $line) {
                    $found[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.$line;
                }
            }
        }

        foreach (self::PORTAL_PATH as $path) {
            $this->assertFileExists(base_path($path), "পোর্টালের পথের ফাইলটা সরেছে — তালিকা হালনাগাদ করুন: {$path}");
            $looked++;
            foreach ($this->hits((string) file_get_contents(base_path($path))) as $line) {
                $found[] = $path.':'.$line;
            }
        }

        $this->assertGreaterThan(50, $looked, 'প্রস্তুতিটাই ভুল — পাহারা কোনো ফাইল দেখেনি।');
        $this->assertSame([], $found, "⛔ কোরে খালি auth id — পোর্টালের গ্রাহক কর্মীর নামে বসবে; Actor::userId() লিখুন:\n".implode("\n", $found));
    }

    /** ⭐ পাহারা নিজে বিপজ্জনক লেখা চেনে — আর মন্তব্যে চেনে না */
    public function test_the_guard_sees_the_dangerous_shapes_and_not_comments(): void
    {
        foreach ([
            '<?php $a = auth()->id();',
            '<?php $a = auth() -> id();',
            '<?php $a = Auth::id();',
            '<?php $a = \Illuminate\Support\Facades\Auth::id();',
            '<?php $a = auth()->user()?->id;',
            '<?php $a = auth()->user()->id;',
            '<?php $a = auth()->user()?->getKey();',
        ] as $danger) {
            $this->assertNotSame([], $this->hits($danger), "⛔ পাহারা এটা দেখেনি: {$danger}");
        }

        foreach ([
            "<?php // auth()->id() নয়\n\$a = 1;",
            "<?php /** `auth()->id()`-র উপর ছেড়ে দেওয়া যায় না */\n\$a = 1;",
            '<?php $a = \App\Core\Support\Actor::userId();',
            '<?php $a = auth()->guard("web")->check();',
            '<?php $a = $request->user()?->can("x");',
        ] as $safe) {
            $this->assertSame([], $this->hits($safe), "পাহারা নির্দোষ লেখায় লাল: {$safe}");
        }
    }

    /** @return list<int> বিপজ্জনক লেখার লাইন নম্বর — কেবল কোডে */
    private function hits(string $src): array
    {
        $lines = [];
        $code = '';

        foreach (token_get_all($src) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $text = str_repeat("\n", substr_count($text, "\n"));
            }
            $code .= $text;
        }

        foreach (explode("\n", $code) as $i => $row) {
            $flat = preg_replace('/\s+/', '', $row);
            if (preg_match('/auth\(\)->id\(\)|Auth::id\(\)|auth\(\)->user\(\)\??->(id\b|getKey\(\))/', (string) $flat)) {
                $lines[] = $i + 1;
            }
        }

        return $lines;
    }
}
