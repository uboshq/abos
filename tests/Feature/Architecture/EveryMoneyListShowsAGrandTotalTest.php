<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * টাকার প্রতিটা তালিকার নিচে সর্বমোট আর সারিতে "দেখুন" — মালিক, ১ অক্টোবর ২০২৬:
 * *"kono list er niche grand total nai keno"*।
 *
 * ── কী দেখা হয় ─────────────────────────────────────────────────────────
 * মডিউলের প্রতিটা `index.blade.php` যা [[x-ui.table]] আঁকে আর টাকা দেখায় (`Money::format` বা `ui.amount-link`),
 * তাকে `:grand` আর `:view-url` দিতে হয় — নয়তো নিচের PENDING তালিকায় থাকতে হয়।
 *
 * ⚠️ PENDING কেবল **ছোট হয়**: নতুন টাকার তালিকা এখানে ঢোকে না (লাল হয়, সর্বমোট দিয়ে আসতে হয়); আর তালিকায় সর্বমোট
 * বসলে নামটা এখান থেকে সরাতে হয় — নইলে লাল, যাতে "বাকি" খাতাটা কখনো মিথ্যা না বলে।
 * ⓘ আচরণের দাবি (সব পাতার যোগ, ছাঁকনি, রপ্তানি, দেখুন): [[EveryDocumentListShowsAGrandTotalAndAViewButtonTest]]।
 */
final class EveryMoneyListShowsAGrandTotalTest extends TestCase
{
    /** ধাপে ধাপে আসছে (সমন্বয়কের তালিকা, ১ অক্টোবর ২০২৬) — প্রতিটা সর্বমোট পেলে এখান থেকে নাম কাটা */
    private const PENDING = [
        'Sales/Resources/views/route/index.blade.php',
        'Sales/Resources/views/crm/opportunity/index.blade.php',
        'Sales/Resources/views/quotation/index.blade.php',
    ];

    /** টাকা দেখায়, কিন্তু যোগটা মিথ্যা বা অর্থহীন হত — কারণসহ; সর্বমোট এখানে ইচ্ছাকৃতভাবে নেই */
    private const NOT_A_SUM = [
        'Approval/Resources/views/inbox/index.blade.php' => 'নানা ধরনের সই একসাথে — ছাড়, ভাউচার, ক্রয়; যোগটা কোনো টাকার হিসাব নয়',
        'Accounts/Resources/views/coa/index.blade.php' => 'খাতের গাছ — মাথার স্থিতিতে নিচের খাতগুলো আগেই ধরা, যোগ করলে দুইবার গোনা',
        'Accounts/Resources/views/year-end/index.blade.php' => 'বছর-শেষের পূর্বরূপ — তালিকা নয়, একটা হিসাব',
        'Approval/Resources/views/limit/index.blade.php' => 'সইয়ের সীমা — প্রতিটা একটা দেয়াল, দেয়ালের যোগ অর্থহীন',
        'Finance/Resources/views/account-analysis/index.blade.php' => 'মাসের প্রারম্ভিক/সমাপনী স্থিতি — স্থিতির যোগ অর্থহীন',
        'Finance/Resources/views/carrier-labour/index.blade.php' => 'চলমান স্থিতির খতিয়ান — শেষ সারিটাই মোট',
        'Finance/Resources/views/hand-loan/index.blade.php' => 'উপরে নিজের স্থিতির ঘর (আমাদের পাওনা · আমাদের দেনা) — দুই দিকের টাকা এক যোগে মেলানো যায় না',
        'Inventory/Resources/views/product/index.blade.php' => 'দামের তালিকা — দামের যোগ অর্থহীন',
        'Restaurant/Resources/views/production/index.blade.php' => 'একক খরচ — দরের যোগ অর্থহীন',
        'Sales/Resources/views/overview/index.blade.php' => 'ড্যাশবোর্ড — প্রতিটা ঘর নিজেই একটা মোট',
        'Sales/Resources/views/price_list/index.blade.php' => 'দামের তালিকা — দামের যোগ অর্থহীন',
    ];

    /** সারির নিজের পাতা নেই — "দেখুন" দেওয়ার জায়গা নেই, সর্বমোট তবু লাগে */
    private const NO_PAGE = [
        // ⓘ চেকের কাজ (জমা, পাস, ফেরত) সারিতেই; আলাদা পাতা কখনো ছিল না
        'Accounts/Resources/views/cheque/index.blade.php',
        // ⓘ কমিশনের দাবি — পাস/বাতিল সারিতেই, আলাদা পাতা নেই
        'Sales/Resources/views/commission/index.blade.php',
        // ⓘ আজকের বন্ধ শিফট — সারির নিজের "দেখুন" আগে থেকেই আছে ([[shift/partials/view]])
        'Sales/Resources/views/shift/index.blade.php',
    ];

    public function test_every_money_list_has_a_grand_total_or_is_named_pending(): void
    {
        $lists = $this->moneyLists();

        $this->assertGreaterThanOrEqual(15, count($lists), '⛔ টাকার তালিকাই প্রায় পাওয়া যায়নি — খোঁজটা অন্ধ।');

        foreach ($lists as $rel => $src) {
            $pending = in_array($rel, self::PENDING, true) || array_key_exists($rel, self::NOT_A_SUM);
            $has = $this->hasGrand($src, in_array($rel, self::NO_PAGE, true));

            $this->assertFalse(! $has && ! $pending,
                "⛔ {$rel} টাকা দেখায়, অথচ নিচে সর্বমোট বা সারিতে \"দেখুন\" নেই — `:grand` আর `:view-url` দিন ([[GrandTotals]])।");
            $this->assertFalse($has && $pending,
                "{$rel}-এ সর্বমোট বসেছে — PENDING থেকে নামটা কাটুন, যাতে বাকির খাতা সত্যি থাকে।");
        }

        foreach ([...self::PENDING, ...array_keys(self::NOT_A_SUM)] as $rel) {
            // ⓘ পাশের লোকের এখনো-না-আসা ফাইল (যেমন নতুন পর্দা) — থাকলে তবেই টাকার তালিকা হতে হবে
            if (is_file(app_path('Modules/'.$rel))) {
                $this->assertArrayHasKey($rel, $lists, "তালিকায় লেখা {$rel} আর টাকার তালিকা নয় — নামটা কাটুন।");
            }
        }
    }

    public function test_the_net_sees_a_planted_money_list(): void
    {
        $bare = "<x-ui.table :rows=\"\$rows\" :columns=\"[['key' => 'total', 'render' => fn (\$r) => \\App\\Core\\Support\\Money::format(\$r->total)]]\" />";
        $done = "<x-ui.table\n    :grand=\"\$grand ?? []\"\n    :view-url=\"fn (\$d) => route('x.show', \$d)\"\n    :rows=\"\$rows\" />";

        $this->assertTrue($this->showsMoney($bare), '⛔ টাকার তালিকা চোখে পড়ল না — জালটা ছেঁড়া।');
        $this->assertFalse($this->hasGrand($bare));
        $this->assertTrue($this->hasGrand($done));
        // ⚠️ মন্তব্যে লেখা নামটা সর্বমোট নয়
        $this->assertFalse($this->hasGrand('{{-- :grand="$grand" :view-url="x" --}}'.$bare));
    }

    /** @return array<string, string> মডিউলের পথ => উৎস (মন্তব্য বাদে) */
    private function moneyLists(): array
    {
        $found = [];
        $root = str_replace('\\', '/', app_path('Modules')).'/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Modules')));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getFilename() !== 'index.blade.php') {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            if (str_contains($src, '<x-ui.table') && $this->showsMoney($src)) {
                $found[substr(str_replace('\\', '/', $file->getPathname()), strlen($root))] = $src;
            }
        }

        return $found;
    }

    private function showsMoney(string $src): bool
    {
        $code = $this->code($src);

        return str_contains($code, 'Money::format') || str_contains($code, 'ui.amount-link');
    }

    private function hasGrand(string $src, bool $noPage = false): bool
    {
        $code = $this->code($src);

        return str_contains($code, ':grand=') && ($noPage || str_contains($code, ':view-url='));
    }

    private function code(string $src): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    }
}
