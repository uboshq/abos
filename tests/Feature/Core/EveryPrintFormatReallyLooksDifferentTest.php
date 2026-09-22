<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintFormat;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * তেরোটা রূপ সত্যিই তেরো রকম দেখায়।
 *
 * ── ⭐ মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"১০-১৫ ফরমেট রাখবা … কোম্পানী গুলো বেচে নিতে পারে কোডিং না করেই।"*
 *
 * ── ⛔ এই ফাইলের একমাত্র কাজ ──────────────────────────────────────────
 * পনেরোটা নাম বসিয়ে দেওয়া সবচেয়ে সহজ কাজ, আর তার দশটা দেখতে **হুবহু
 * এক** হতে পারে। ⚠️ তখন তালিকাটা মিথ্যা বলে: মালিক পড়েন পনেরোটা রূপ
 * পেয়েছেন, বাছেন একটা, আর কাগজে কিছুই বদলায় না — ⓘ আর তিনি ভাবেন
 * বাছাইটাই কাজ করে না।
 *
 * ⭐ তাই দাবিটা "আলাদা লেখা আছে" নয়, **"আলাদা দেখায়"**।
 */
final class EveryPrintFormatReallyLooksDifferentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }

    /** ⭐ মালিক যতগুলো চেয়েছেন, ততগুলোই আছে। */
    public function test_there_are_at_least_ten_formats(): void
    {
        $this->assertGreaterThanOrEqual(10, count(PrintFormat::all()), implode("\n", [
            '⛔ মালিক ১০-১৫টা রূপ চেয়েছিলেন।',
            '',
            'ⓘ কম থাকলে কোম্পানিগুলো আবার কোড লেখাতে ফিরে আসবে,',
            'আর ঠিক সেটাই এড়ানোর জন্য এই তালিকাটা।',
        ]));
    }

    /**
     * ⛔ কোনো দুইটা রূপ একরকম কাগজ আঁকে না।
     *
     * ── ⓘ কেন সুইচের সমষ্টি মেলানো যথেষ্ট নয় ─────────────────────────
     * দুইটা রূপের সুইচ আলাদা হতে পারে অথচ কাগজ এক — যেমন এমন একটা
     * অংশ চালু/বন্ধ করা যার এই কাগজে কোনো অস্তিত্বই নেই। ⚠️ তাই
     * সত্যিকারের HTML আঁকা হয় আর তার ছাপ মেলানো হয়।
     */
    public function test_no_two_formats_draw_the_same_paper(): void
    {
        $seen = [];
        $same = [];

        foreach (PrintFormat::all() as $name) {
            $print = $this->paperFor($name);
            $fingerprint = md5($print);

            if (isset($seen[$fingerprint])) {
                $same[] = "{$seen[$fingerprint]} = {$name}";
            }

            $seen[$fingerprint] = $name;
        }

        $this->assertSame([], $same, implode("\n", array_merge(
            ['⛔ এই রূপগুলো দেখতে হুবহু এক:', ''],
            $same,
            [
                '',
                '⚠️ তালিকাটা তখন মিথ্যা বলে — মালিক ভাবেন সবগুলো আলাদা রূপ পেয়েছেন,',
                'বাছেন একটা, আর কাগজে কিছুই বদলায় না।',
                '',
                'ⓘ হয় রূপটাকে সত্যিই আলাদা করুন, নয় তালিকা থেকে সরান।',
            ],
        )));
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ── ⚠️ কেন এই দাবিটা ছাড়া উপরেরটা কিছুই মাপে না ──────────────────
     * ⓘ [[paperFor()]] যদি কোনো কারণে খালি লেখা ফেরত দিত — একটা ভুল
     * ভিউয়ের নাম, একটা চাপা পড়া ব্যতিক্রম — তবে সবগুলো ছাপ **এক
     * এক** হত, আর দাবিটা লাল হত। ⛔ কিন্তু উল্টো ভুলটা নীরব: আঁকাটা
     * ঠিকই হলো অথচ সুইচগুলো কিছুই বদলাল না, আর তখনও ছাপ আলাদা হতে
     * পারে অন্য কোনো কারণে।
     *
     * ⭐ তাই সরাসরি মাপা: সুইচ বদলালে কাগজে ঐ জিনিসটাই বদলায়।
     */
    public function test_switching_a_part_off_really_removes_it_from_the_paper(): void
    {
        $withWords = $this->paperFor('standard');

        /* ⓘ "টাকা কথায়" বন্ধ করা — একটা অংশ, যার উপস্থিতি চোখে দেখা যায় */
        $withoutWords = $this->paperFor('standard', without: ['words']);

        $this->assertNotSame($withWords, $withoutWords, implode("\n", [
            '⛔ একটা অংশ বন্ধ করেও কাগজ অবিকল একই।',
            '',
            '⚠️ অর্থাৎ সুইচগুলো কিছুই নিয়ন্ত্রণ করছে না, আর উপরের',
            'দাবিটা অন্য কোনো কারণে সবুজ ছিল।',
        ]));

        $this->assertLessThan(strlen($withWords), strlen($withoutWords),
            'ⓘ অংশ বাদ দিলে কাগজ ছোট হওয়ার কথা — বড় হলে কিছু একটা উল্টো ঘটছে।');
    }

    /**
     * ⭐ আর কলামের ক্রম সত্যিই বদলায়।
     *
     * ⓘ মালিকের কথাটা ছিল *"কোনটার পর কোনটা"* — ⚠️ কলামগুলো থাকা আর
     * **ঠিক ক্রমে থাকা** এক জিনিস নয়, আর ক্রমের ভুল নীরব: সব সংখ্যা
     * কাগজে আছে, কেবল ভুল শিরোনামের নিচে।
     */
    public function test_the_column_order_is_the_order_on_the_paper(): void
    {
        $ours = $this->headings($this->paperFor('standard'));
        $sample = $this->headings($this->paperFor('distributor'));

        $this->assertNotSame($ours, $sample, implode("\n", [
            '⛔ দুইটা রূপের কলামের ক্রম এক।',
            '',
            'ⓘ `distributor` মালিকের নমুনার ক্রম ধরে — ওখানে দর আসে',
            'পরিমাণের **আগে**, আর একক আসে পরিমাণের **পরে**।',
        ]));

        $this->assertContains(__('core.print.column.code'), $sample,
            '⛔ নমুনার রূপে কোডের কলামটাই নেই — অথচ ঐ কাগজে ওটা দ্বিতীয় কলাম।');
    }

    /**
     * শিরোনামগুলো কাগজের ক্রমে।
     *
     * ⓘ `<th>`-এর ভিতরের লেখা টেনে নেওয়া হয়, কারণ ক্রমটাই মাপার জিনিস।
     *
     * @return list<string>
     */
    private function headings(string $html): array
    {
        preg_match('/<thead>(.*?)<\/thead>/s', $html, $head);
        preg_match_all('/<th[^>]*>(.*?)<\/th>/s', $head[1] ?? '', $cells);

        return array_map(fn (string $cell) => trim(strip_tags($cell)), $cells[1] ?? []);
    }

    /**
     * এই রূপে একটা কাগজ আঁকা।
     *
     * ── ⚠️ কেন সেটিংসে বসিয়ে, সরাসরি ভিউকে দিয়ে নয় ──────────────────
     * ⓘ সরাসরি একটা [[PrintProfile]] বানিয়ে ভিউকে দিলে **আসল পথটা
     * কখনো হাঁটা হত না** — সেটিং পড়া, রূপ মেলানো, ওভাররাইড বসানো।
     * ⛔ আর ঐ পথেই ভুল থাকে, ভিউতে নয়।
     *
     * @param  list<string>  $without
     */
    private function paperFor(string $format, array $without = []): string
    {
        $settings = app(SettingsService::class);
        $settings->set('print.invoice.format', $format);

        if ($without === []) {
            $settings->reset('print.invoice.parts');
        } else {
            $settings->set('print.invoice.parts', array_values(array_diff(
                PrintFormat::of($format)->parts,
                $without,
            )));
        }

        $settings->flush();

        return view('print.document', [
            'doc' => $this->document(),
            'title' => 'INV-0001',
            'company' => Company::query()->where('code', 'TDEPOT')->firstOrFail(),
            'paper' => PaperSize::of(PaperSize::A4),
            'locale' => 'bn',
            'settings' => $settings,
            'profile' => PrintProfile::for('invoice', $settings),
        ])->render();
    }

    private function document(): PrintableDocument
    {
        return new PrintableDocument(
            title: 'বিক্রয় বিল',
            meta: ['core.print.customer' => 'পরীক্ষার গ্রাহক'],
            lines: [
                [
                    'code' => 'SL001', 'name' => 'আনারস', 'qty' => '১০', 'unit' => 'কার্টন',
                    'rate' => '112.15', 'amount' => '1121.50', 'note' => '', 'free' => '2',
                    'group' => 'Slfpl', 'band_total' => '1121.50',
                ],
                [
                    'code' => 'SL002', 'name' => 'এনার্জি', 'qty' => '২০', 'unit' => 'কার্টন',
                    'rate' => '252.34', 'amount' => '5046.80', 'note' => '', 'free' => '5',
                    'group' => 'Jabed Food', 'band_total' => '5046.80',
                ],
            ],
            totals: ['core.print.total' => '6168.30'],
            signatures: ['core.print.signature_receiver'],
            amountInWords: 'ছয় হাজার একশত আটষট্টি টাকা ত্রিশ পয়সা',
        );
    }
}
