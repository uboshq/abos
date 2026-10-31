<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ⭐ "ভাউচারটা সইয়ের অপেক্ষায় কি" — উত্তর এক জায়গায়, [[VoucherApproval::decide()]] (fe, ২৪ অক্টোবর ২০২৬)।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────────
 * তালিকা, ফোন, ড্যাশবোর্ড, পোস্টিং মনিটর, খরচের পাতা, বিমার দাবি আর মাস-শেষ — প্রত্যেকে নিজে `pending()` গুনত। অনুরোধের পরে
 * ভাউচার বদলালে সেই অনুরোধ বাসি, পরের পোস্টে তুলে নতুন চাওয়া হয় ([[VoucherApproval::stopping()]]); অথচ এরা সবাই তখনো
 * "সইয়ের অপেক্ষায়" বলত, আর তালিকার মোট সেটাকে খসড়া গুনত — একই কাগজ নিয়ে কোম্পানির পাতাগুলো দুই কথা বলত।
 *
 * ── পাহারা ──────────────────────────────────────────────────────────────
 * ⓘ একই বিবৃতিতে (আগের `;` `{` `}` থেকে পরের `;` পর্যন্ত) `approvable_type` = `Voucher::class` আর PENDING থাকলে সেটা নিজে গোনা —
 * কেবল [[KNOWN]]-এর জায়গায় চলে, মালিক আর কারণসহ; তালিকা কেবল ছোট হয়। [[VoucherApproval]] নিজে পাহারার বাইরে — উত্তরটা ওখানেই।
 */
final class EveryVoucherWaitIsDecidedOnceTest extends TestCase
{
    /**
     * আজও নিজে PENDING পড়া জায়গা — ফাইল => [কতগুলো, মালিক আর কারণ]। ⓘ কেবল ছোট হবে।
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const KNOWN = [
        'app/Modules/Accounts/Services/VoucherWriter.php' => [2, 'cb — পোস্টের নিজের পথ: stopping()-এর ঠিক পরে যে অনুরোধ এইমাত্র লেখা হলো, সেটাই পড়ে (একটা ভাউচার, বাসি হওয়ার সময় নেই)'],
        'app/Modules/Sales/Http/Controllers/DirectSaleController.php' => [1, 'a5 — গোটা বিক্রি আটকে থাকে যতক্ষণ যেকোনো কাগজে সই চাওয়া — বাসি অনুরোধে কাউন্টার কী দেখাবে, a5 ঠিক করবে'],
        'app/Modules/Sales/Services/DirectSaleService.php' => [2, 'a5 — গোটা বিক্রি আটকে থাকে যতক্ষণ যেকোনো কাগজে সই চাওয়া — বাসি অনুরোধে কাউন্টার কী দেখাবে, a5 ঠিক করবে'],
        'app/Modules/Sales/Services/HeldCounterSaleFinisher.php' => [1, 'a5 — গোটা বিক্রি আটকে থাকে যতক্ষণ যেকোনো কাগজে সই চাওয়া — বাসি অনুরোধে কাউন্টার কী দেখাবে, a5 ঠিক করবে'],
    ];

    /** উত্তরের নিজের ঘর */
    private const THE_ANSWER = 'app/Modules/Accounts/Services/VoucherApproval.php';

    public function test_no_page_counts_a_voucher_as_waiting_on_its_own(): void
    {
        $found = $this->found();

        foreach ($found as $path => $hits) {
            $known = self::KNOWN[$path][0] ?? 0;
            $this->assertLessThanOrEqual($known, $hits,
                "⛔ {$path}: ভাউচারের অপেক্ষা নিজে গুনছে ({$hits} জায়গা, তালিকায় {$known}) — বাসি অনুরোধও \"অপেক্ষা\" হবে; VoucherApproval::waitingIds() বা signingOf() ডাকুন");
        }

        foreach (self::KNOWN as $path => [$known, $why]) {
            $this->assertSame($known, $found[$path] ?? 0,
                "⛔ {$path}: KNOWN বলছে {$known} জায়গা, আছে ".($found[$path] ?? 0)." — সারানো হলে তালিকা থেকেও সরাও ({$why})");
        }
    }

    public function test_the_guard_sees_the_shape_it_forbids(): void
    {
        $src = "<?php\n\$x = Approval::query()->where('approvable_type', Voucher::class)\n    ->pending()->pluck('approvable_id');\n";
        $this->assertSame(1, $this->hits($src), '⛔ পাহারা নিষিদ্ধ ধাঁচটাই চিনল না — কাউকে না দেখে সবুজ হত');

        $other = "<?php\n\$x = Approval::query()->where('approvable_type', Voucher::class)->get();\n\$y = Approval::query()->pending()->count();\n";
        $this->assertSame(0, $this->hits($other), '⛔ আলাদা বিবৃতির দুই কথা জুড়ে পাহারা ভুল ধরল');
    }

    /** @return array<string, int> */
    private function found(): array
    {
        $out = [];

        foreach ((new Finder)->files()->in(base_path('app'))->name('*.php') as $file) {
            $path = str_replace('\\', '/', 'app/'.$file->getRelativePathname());

            if ($path === self::THE_ANSWER) {
                continue;
            }

            $hits = $this->hits((string) file_get_contents($file->getPathname()));

            if ($hits > 0) {
                $out[$path] = $hits;
            }
        }

        ksort($out);

        return $out;
    }

    private function hits(string $src): int
    {
        $hits = 0;

        if (! preg_match_all('/approvable_type[\'"]?\s*,\s*(?:\\\\?[\w\\\\]*\\\\)?Voucher::class/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return 0;
        }

        foreach ($m[0] as [, $at]) {
            $before = substr($src, 0, $at);
            $start = max((int) strrpos($before, ';'), (int) strrpos($before, '{'), (int) strrpos($before, '}'));
            $end = strpos($src, ';', $at);
            $statement = substr($src, $start, ($end === false ? strlen($src) : $end) - $start);

            if (preg_match('/->pending\(\)|Approval::PENDING|[\'"]pending[\'"]/', $statement)) {
                $hits++;
            }
        }

        return $hits;
    }
}
