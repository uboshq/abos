<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Modules\Accounts\Models\Voucher;
use App\Modules\Approval\Services\ApprovalFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কোড যে অনুমোদন চায়, তার ছক বসানোর পথ আছে কি।
 *
 * ── কেন এই পাহারাটা লাগল ────────────────────────────────────────────
 * অনুমোদনের দুই মাথা দুই জায়গায়। এক মাথায় সেবা বলে *"এই কাজে সই
 * লাগতে পারে"* (`request(module: 'finance', action: 'withdrawal')`), আর
 * অন্য মাথায় `module.php`-র `approvals` ঠিক করে ছকের পর্দায় কোন কাজ
 * **দেখা যাবে**। ⚠️ দুইটা আলাদা হলে কিছুই ভাঙে না — আর ওটাই বিপদ।
 *
 * `finance.withdrawal`-এ ঠিক তা-ই ছিল: সেবাটা প্রথম দিন থেকে ইঞ্জিনকে
 * ডাকত, কিন্তু ঘোষণা না থাকায় `ApprovalFlowService::assertKnownAction()`
 * ওই কাজে ছক বসাতেই দিত না। ছক নেই মানে `flowFor()` → `null`, মানে
 * `request()` → `null`, মানে **টাকা বেরোনোর সময় কেউ কোনোদিন সই চায়নি**।
 * দেখতে হুবহু "এই কোম্পানি অনুমোদন চায় না"-র মতো, তাই কেউ টের পেত না।
 *
 * ── উল্টো দিকটাও পাহারা দেওয়া হয় ───────────────────────────────────
 * ঘোষিত অথচ কেউ চায় না — এমন কাজ ছকের পর্দায় একটা সারি হিসেবে বসে
 * থাকত, মালিক ওটায় স্তর ও সীমা বসাতেন, আর জিনিসটা কোনোদিন চলত না।
 * একটা নিয়ম যা দেখতে চলছে অথচ চলে না, তার চেয়ে নিয়ম না থাকা ভালো।
 */
class EveryApprovalAskedForCanBeConfiguredTest extends TestCase
{
    use RefreshDatabase;

    /**
     * কোড যেসব `module.action` অনুমোদনের জন্য চায়।
     *
     * @return list<string>
     */
    private function askedFor(): array
    {
        $asked = [];

        foreach ($this->sourceFiles() as $file) {
            $code = (string) file_get_contents($file);

            // যে ফাইল ইঞ্জিনের নামই জানে না, সে অনুমোদন চায় না
            if (! str_contains($code, 'ApprovalEngine') && ! str_contains($code, 'DocumentApproval')) {
                continue;
            }

            preg_match_all(
                "/module:\s*'([a-z_]+)'\s*,\s*action:\s*'([a-z_]+)'/",
                $code,
                $found,
                PREG_SET_ORDER
            );

            foreach ($found as $one) {
                $asked[] = $one[1].'.'.$one[2];
            }
        }

        /*
         * ভাউচার — কাজের নামটা কাগজের নিজের ধরন।
         *
         * `VoucherApproval::stopping()` `$voucher->type` পাঠায়, তাই
         * নামটা কোড পড়ে বের করা যায় না। পাঁচটা ধরনই এখানে গোনা হয়,
         * আর গোনাটা `Voucher::TYPES` থেকেই — ষষ্ঠ একটা ধরন যোগ হলে
         * ঘোষণা ছাড়া সেটাও এখানে ধরা পড়বে।
         */
        foreach (Voucher::TYPES as $type) {
            $asked[] = 'accounts.'.$type;
        }

        return array_values(array_unique($asked));
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }

    /** ছকের পর্দা যেসব `module.action` বসাতে দেয়। */
    /** @return list<string> */
    private function configurable(): array
    {
        return array_keys(app(ApprovalFlowService::class)->labels());
    }

    public function test_the_regex_finds_something_at_all(): void
    {
        /*
         * ⚠️ শূন্য সংগ্রহে চালানো assertion সবসময় সবুজ।
         *
         * নিচের দুইটা পরীক্ষা তালিকা মেলায়। কোনো কারণে (ফাইলের গঠন
         * বদলাল, নামযুক্ত আর্গুমেন্টের বদলে অবস্থানগত হলো) খোঁজাটা
         * কিছুই না পেলে দুইটাই পাশ করত, আর পাহারাটা অলংকার হয়ে যেত।
         */
        $this->assertGreaterThanOrEqual(10, count($this->askedFor()));
        $this->assertGreaterThanOrEqual(10, count($this->configurable()));
    }

    public function test_every_approval_the_code_asks_for_can_be_set_up(): void
    {
        $missing = array_values(array_diff($this->askedFor(), $this->configurable()));

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['কোড এই কাজগুলোতে অনুমোদন চায়, কিন্তু ছক বসানোর কোনো পথ নেই —'],
            array_map(static fn (string $key): string => "  {$key}", $missing),
            ["মডিউলের module.php-তে 'approvals' সারিটা যোগ করুন, নইলে সই কোনোদিন চাওয়া হবে না।"],
        )));
    }

    public function test_every_action_offered_is_actually_asked_for(): void
    {
        $dead = array_values(array_diff($this->configurable(), $this->askedFor()));

        $this->assertSame([], $dead, implode("\n", array_merge(
            ['ছকের পর্দা এই কাজগুলো বসাতে দেয়, কিন্তু কোনো কোড ওগুলোতে অনুমোদন চায় না —'],
            array_map(static fn (string $key): string => "  {$key}", $dead),
            ['ছক বসালে সেটা কোনোদিন চলবে না। হয় সেবাটা লিখুন, নয় ঘোষণাটা সরান।'],
        )));
    }
}
