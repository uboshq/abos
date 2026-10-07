<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * সংখ্যার কলামে শিরোনামও ডানে — নাহলে ছকটা আঁকাবাঁকা দেখায়।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * টেবিলের সংখ্যাগুলো ডানে সারিবদ্ধ (দশমিক বিন্দু এক লাইনে না থাকলে চোখে
 * যোগফল মেলানো যায় না), অথচ **শিরোনামগুলো বাঁয়ে** বসত। ফলে প্রতিটা
 * সংখ্যার কলামে দুইটা আলাদা খাড়া রেখা তৈরি হত: শিরোনামের ধার বাঁয়ে,
 * সংখ্যার ধার ডানে।
 *
 * মজুদের পর্দায় চারটা সংখ্যার কলাম — তাকে, ধরা, আটকানো, বিক্রয়যোগ্য।
 * চারটা কলাম মানে **আটটা রেখা**, আর গোটা ছকটা আঁকাবাঁকা দেখাত। ব্যবহারকারী
 * ঠিক কী গোলমাল তা বলতে পারতেন না, শুধু বলতেন "লাইন আঁকাবাঁকা কেন"।
 *
 * ── কেন CSS-এর নিয়মটা কাজ করছিল না ──────────────────────────────────
 * `app.css`-এ `th.num { text-align: right }` আগে থেকেই লেখা ছিল। কিন্তু
 * ওটা **`@layer base`-এ**, আর ব্লেডে বসানো `text-start` Tailwind-এর
 * **utility স্তরে**। CSS-এ utility সবসময় base-কে হারায়।
 *
 * অর্থাৎ নিয়মটা লেখা ছিল, পড়াও যেত, অথচ কোনোদিন খাটেনি — কারণ ঠিক
 * তার উল্টো কথাটা এক স্তর উঁচুতে বসানো ছিল। এই ধরনের ভুল চোখে পড়ে না,
 * কারণ কোড দেখে মনে হয় কাজটা হয়ে আছে।
 *
 * ── কেন পরীক্ষাটা দরকার ─────────────────────────────────────────────
 * `x-ui.table`-এ সিদ্ধান্তটা এখন ঠিক, কিন্তু আটটা পর্দা নিজের হাতে
 * টেবিল লিখেছিল আর প্রতিটাতেই একই দুইটা শ্রেণী পাশাপাশি বসেছিল। পরের
 * হাতে-লেখা টেবিলটাও একই ভুল করবে, যদি না কেউ ধরে।
 */
class NumberColumnsLineUpTest extends TestCase
{
    /** একটা `<th ...>` ট্যাগ — ভেতরের শ্রেণীগুলোসহ। */
    private const TH = '/<th\b[^>]*>/s';

    /**
     * ব্লেডের মন্তব্য বাদ — ব্যাখ্যায় ভুল রূপটা লেখা থাকতেই পারে, আর
     * সেটাই ব্যাখ্যা।
     */
    private function markupOf(string $path): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/su', '', File::get($path));
    }

    /** @return list<string> */
    private function blades(): array
    {
        $out = [];

        foreach ([base_path('app'), resource_path('views')] as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php')) {
                    $out[] = $file->getPathname();
                }
            }
        }

        return $out;
    }

    public function test_a_number_column_never_has_its_heading_on_the_left(): void
    {
        $offenders = [];

        foreach ($this->blades() as $file) {
            preg_match_all(self::TH, $this->markupOf($file), $tags);

            foreach ($tags[0] as $tag) {
                $numeric = preg_match('/\bnum\b/', $tag) === 1;

                if ($numeric && str_contains($tag, 'text-start')) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
                    break;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'এই পর্দাগুলোয় সংখ্যার কলামের শিরোনাম বাঁয়ে বসানো।',
            'সংখ্যাগুলো ডানে, শিরোনাম বাঁয়ে — প্রতিটা কলামে দুইটা আলাদা খাড়া রেখা,',
            'আর গোটা ছকটা আঁকাবাঁকা দেখায়।',
            'num-এর সাথে text-end লিখুন, text-start নয়।',
            implode("\n", $offenders),
        ]));
    }

    /**
     * ভাগ করা টেবিল কম্পোনেন্টটাই সিদ্ধান্ত নেয়।
     *
     * নিয়মটা কেবল CSS-এ রাখলে আবার একই ফাঁদ: ব্লেডে উল্টো utility বসালে
     * সেটাই জেতে। তাই শ্রেণীটা কোন দিকে যাবে, সেটা এখানেই ঠিক হয়।
     */
    public function test_the_shared_table_decides_the_alignment_itself(): void
    {
        $table = File::get(resource_path('views/components/ui/table.blade.php'));

        $this->assertStringContainsString("'text-end num' => \$column['numeric']", $table,
            'সংখ্যার শিরোনাম ডানে বসানোর সিদ্ধান্তটা টেবিল কম্পোনেন্টে নেই।');
        $this->assertStringContainsString("'text-start' => ! \$column['numeric']", $table,
            'বাকি শিরোনামগুলো বাঁয়েই থাকার কথা।');
    }
}
