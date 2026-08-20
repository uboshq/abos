<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * যে তালিকা কেবল খালি থাকলে কাজ করে।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `x-ui.table` স্লট পড়ে না। সে `:rows` আর `:columns` থেকে নিজে সারি
 * আঁকে, আর প্রতিটা কলামে `key` ও `label` **দুইটাই** চায়।
 *
 * ২০ আগস্ট পাঁচটা নতুন পর্দা লেখা হয়েছিল উল্টোভাবে — কম্পোনেন্টের
 * ভেতরে হাতে `<tr>` বসিয়ে, আর কলামে কেবল `label` দিয়ে।
 *
 * ── কেন এটা কোথাও ধরা পড়েনি ─────────────────────────────────────────
 * প্রতিটা পর্দায় লেখা ছিল `@if ($rows->isEmpty()) … @else <x-ui.table>`।
 * ডেমো ডাটায় ওই তালিকাগুলো খালি, তাই `@else` শাখাটা কখনো চলেনি —
 * পর্দা খুলত, শিরোনাম দেখাত, ছবিও তোলা গিয়েছিল।
 *
 * ভাঙত **প্রথম সারিটা তৈরি হওয়ার মুহূর্তে**, ৫০০ হয়ে। অর্থাৎ ডেমোতে
 * নয়, প্রথম আসল ব্যবহারকারীর হাতে — আর ওটাই সবচেয়ে খারাপ সময়।
 *
 * ── এই পরীক্ষাটা কেন কম্পোনেন্ট ধরে, পর্দা ধরে নয় ────────────────────
 * প্রতিটা পর্দা HTTP দিয়ে খুলে দেখতে গেলে ডেটা বসাতে হত, আর তাতে
 * পরীক্ষাটা ধীর ও ভঙ্গুর হত। কম্পোনেন্টের চুক্তিটা সরাসরি পরখ করলে
 * একই কথা প্রমাণ হয়, আর দ্রুত।
 */
class AListThatOnlyWorksWhileEmptyTest extends TestCase
{
    /**
     * স্লটে সারি লিখলে সেটা নীরবে হারায় না — জোরে ভাঙে।
     *
     * নীরবে হারানোই ছিল আসল বিপদ: পাতা খুলত, টেবিল থাকত না, আর কেউ
     * বলতে পারত না কেন। এখন কম্পোনেন্ট কলামের চুক্তি না মিললে
     * ব্যতিক্রম ছোঁড়ে, তাই ভুলটা লেখার দিনেই ধরা পড়ে।
     */
    public function test_a_column_without_a_key_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/key.*label|label.*key/i');

        Blade::render(
            '<x-ui.table :rows="[1]" :columns="[[\'label\' => \'Name\']]" />',
        );
    }

    /** চুক্তি মেনে ডাকলে সারিটা সত্যিই আঁকা হয়। */
    public function test_a_proper_column_renders_its_row(): void
    {
        $out = Blade::render(
            '<x-ui.table :rows="$rows" :columns="$columns" />',
            [
                'rows' => [(object) ['name' => 'SENTINEL']],
                'columns' => [['key' => 'name', 'label' => 'Name']],
            ],
        );

        $this->assertStringContainsString('SENTINEL', $out,
            'চুক্তি মেনে ডাকার পরেও সারিটা আঁকা হয়নি।');
    }

    /**
     * কোনো পর্দা `x-ui.table`-এর ভেতরে হাতে সারি লেখে না।
     *
     * এটাই আসল পাহারা। উপরের দুইটা কম্পোনেন্টের আচরণ বাঁধে; এটা বাঁধে
     * **ব্যবহারটা** — আর ভুলটা ব্যবহারেই ছিল।
     */
    public function test_no_screen_puts_rows_inside_the_table_component(): void
    {
        $offenders = [];

        foreach ($this->blades() as $path => $source) {
            if (! str_contains($source, '<x-ui.table')) {
                continue;
            }

            // খোলা ট্যাগ থেকে বন্ধ ট্যাগ পর্যন্ত — স্বয়ং-বন্ধ হলে কিছুই নেই
            if (! preg_match('/<x-ui\.table\b.*?<\/x-ui\.table>/s', $source, $m)) {
                continue;
            }

            if (str_contains($m[0], '<tr')) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'এই পর্দাগুলো x-ui.table-এর ভেতরে হাতে <tr> লিখেছে।',
            'কম্পোনেন্ট স্লট পড়ে না — সারি আসে :rows আর :columns থেকে,',
            'আর প্রতিটা কলামে key ও label দুইটাই লাগে।',
            'পর্দাটা খালি অবস্থায় ঠিক চলবে আর প্রথম সারিতেই ৫০০ দেবে।',
            ...$offenders,
        ]));
    }

    /** @return array<string, string> */
    private function blades(): array
    {
        $found = [];

        foreach (['app/Modules', 'resources/views'] as $root) {
            $dir = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($root)),
            );

            foreach ($dir as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());
                $path = ltrim(str_replace(str_replace('\\', '/', base_path()), '', $path), '/');

                $found[$path] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($found);

        return $found;
    }
}
