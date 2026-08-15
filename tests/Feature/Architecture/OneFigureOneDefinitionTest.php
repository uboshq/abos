<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Metrics\Metric;
use App\Core\Support\DocumentStatus;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * একটা সংখ্যার একটাই সংজ্ঞা, একটাই জায়গা।
 *
 * ── ABOS-এর নিজের প্রমাণ ────────────────────────────────────────────
 * "আজকের বিক্রয়" এই রিপোতেই চার জায়গায় হিসাব হত: ড্যাশবোর্ড, POS,
 * রিপোর্ট, শিফট। একবার তারা দুইটা আলাদা উত্তর দিয়েছিল — একজন খসড়াও
 * গুনত, অন্যজন নয়। ধরে-রাখা একটা বিলের টাকা ক্যাশিয়ারের শিফটে দেখা
 * যেত, শিফট মেলানোর সময় হাতের নগদ কম পড়ত, আর কেউ বুঝত না কেন।
 *
 * ওই নির্দিষ্ট ভুলটা সারানো। এই পরীক্ষাটা পরেরটা আটকায়।
 */
class OneFigureOneDefinitionTest extends TestCase
{
    /**
     * "কোন কাগজ গোনা হয়" কেবল এক জায়গায় লেখা।
     *
     * ── কেন গ্রেপ, কেন আচরণ নয় ──────────────────────────────────────
     * আচরণ দিয়ে ধরা যায় না: দুইটা জায়গায় একই তালিকা হাতে লিখলে আজ
     * দুইটাই ঠিক উত্তর দেয়। ভুলটা ঘটে ছয় মাস পরে, যখন কেউ একটা
     * বদলায় আর অন্যটা খুঁজে পায় না। তাই প্রশ্নটা "উত্তর কি এক" নয়,
     * "নিয়মটা কি এক জায়গায়" — আর সেটা কেবল কোড পড়েই দেখা যায়।
     */
    public function test_the_counted_statuses_are_written_in_exactly_one_place(): void
    {
        /*
         * ফাঁকা জায়গা মুছে তারপর খোঁজা।
         *
         * ── কেন, আর কীভাবে ধরা পড়ল ──────────────────────────────────
         * প্রথম রূপে নিডলগুলো হুবহু লেখা খুঁজত, তাই এক লাইনের নকল ধরা
         * পড়ত আর কয়েক লাইনে ভাঙা নকল পড়ত না। ঠিক তেমন একটা নকল
         * `SalesReturnService`-এ বসে ছিল — পাহারাটা সবুজ দেখাচ্ছিল
         * অথচ নিয়মটা দুই জায়গায় লেখা ছিল।
         *
         * একটা পাহারা যা অর্ধেক ধরে, তার বিপদ ধরতে না পারার চেয়ে বেশি:
         * সবুজ দেখে সবাই ধরে নেয় জিনিসটা এক জায়গায় আছে।
         */
        $pattern = '/CONFIRMED,(?:self|DocumentStatus)::CLOSED,?\]/';
        $home = 'app/Core/Support/DocumentStatus.php';

        $offenders = [];

        foreach ($this->phpFiles() as $path) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen(base_path()) + 1));

            if ($relative === $home) {
                continue;
            }

            $code = preg_replace('/\s+/', '', $this->withoutComments(file_get_contents($path)));

            if (preg_match($pattern, $code) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders,
            '"কোন কাগজ গোনা হয়" নিয়মটা একাধিক জায়গায় লেখা হয়েছে। কোয়েরিতে `->posted()`, '
            ."সংজ্ঞা বলতে `DocumentStatus::POSTED` — তালিকাটা {$home}-এ:\n".implode("\n", $offenders));
    }

    /**
     * সংজ্ঞাটা তার নিজের চারটা প্রশ্নের উত্তর দেয়।
     *
     * এই চারটাতেই দুইজন মানুষ দুই রকম ধরে নেয়, আর কোনোটাই সংখ্যাটা
     * দেখে বোঝা যায় না।
     */
    public function test_a_metric_can_say_how_it_is_counted(): void
    {
        $metric = new Metric(
            key: 'sales.today',
            label: 'আজকের বিক্রয়',
            statuses: [DocumentStatus::CONFIRMED, DocumentStatus::CLOSED],
            dateField: Metric::BY_TRANSACTION_DATE,
            scale: 2,
            rounding: Metric::ROUND_AT_TOTAL,
            permission: 'sales.view',
            value: fn () => '1000.00',
        );

        $definition = $metric->definition();

        // কোন status — খসড়া নয়, সেটাই সবচেয়ে বড় প্রশ্ন
        $this->assertStringContainsString(__('core.status.confirmed'), $definition);
        $this->assertStringNotContainsString(__('core.status.draft'), $definition);

        // কোন তারিখ — লেনদেনের, এন্ট্রির নয়
        $this->assertStringContainsString(__('core.metric.by_transaction_date'), $definition);

        // রাউন্ডিং কোন ধাপে
        $this->assertStringContainsString(__('core.metric.round_at_total'), $definition);

        $this->assertSame('1000.00', $metric->value());
    }

    /**
     * একটাও status না গুনলে সংখ্যাটা চিরকাল শূন্য — নির্মাণেই আটকানো।
     */
    public function test_a_metric_that_counts_nothing_cannot_be_built(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Metric('sales.today', 'x', [], Metric::BY_TRANSACTION_DATE, 2,
            Metric::ROUND_AT_TOTAL, 'sales.view', fn () => '0');
    }

    /**
     * অজানা status টাইপো — সংখ্যাটা নিঃশব্দে শূন্য হত।
     */
    public function test_an_unknown_status_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Metric('sales.today', 'x', ['confirmd'], Metric::BY_TRANSACTION_DATE, 2,
            Metric::ROUND_AT_TOTAL, 'sales.view', fn () => '0');
    }

    /**
     * নামের আগে মডিউল — নাহলে দুই মডিউলের "today" ঠুকে যেত।
     */
    public function test_a_metric_key_names_its_module(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Metric('today', 'x', [DocumentStatus::CONFIRMED], Metric::BY_TRANSACTION_DATE, 2,
            Metric::ROUND_AT_TOTAL, 'sales.view', fn () => '0');
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $out = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    /** মন্তব্যে নিয়মটার কথা লেখা থাকতেই পারে — সেটা ব্যাখ্যা, অপরাধ নয়। */
    private function withoutComments(string $code): string
    {
        $code = preg_replace('!/\*.*?\*/!su', '', $code);

        return preg_replace('!//.*!', '', $code);
    }
}
