<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Support\Str;

/**
 * ⭐ "নতুন কী এল" — রেপোর resources/release-notes/{bn,en}.md, সংস্করণ ধরে (মালিক, ২৪ অক্টোবর ২০২৬)।
 *
 * ⓘ প্রতিটা অংশ শুরু হয় `## 1.0.0 — 24-10-2026`-এর মতো শিরোনামে। ⭐ সর্বশেষ সংস্করণ সবসময় সবার উপরে — ফাইলে কে কোন ক্রমে লিখলেন
 * তার ওপর নির্ভর নয় (version_compare)। নোট লেখেন fe; লেখা রেপোর ফাইল, তাই বিশ্বস্ত — তবু কাঁচা HTML ফেলে দেওয়া হয়।
 * ⓘ চলতি ভাষার ফাইল না থাকলে বাংলা, বাংলাও না থাকলে খালি তালিকা — পাতা ভাঙে না।
 */
final class ReleaseNotes
{
    /** @return list<array{version: string, title: string, html: string}> */
    public static function sections(?string $locale = null): array
    {
        $dir = (string) config('release.notes_path', resource_path('release-notes'));
        $locale = in_array($locale ?? app()->getLocale(), ['bn', 'en'], true) ? ($locale ?? app()->getLocale()) : 'bn';
        $file = is_file($dir.'/'.$locale.'.md') ? $dir.'/'.$locale.'.md' : $dir.'/bn.md';

        if (! is_file($file)) {
            return [];
        }

        $sections = [];
        $current = null;

        foreach (preg_split('/\R/u', (string) file_get_contents($file)) ?: [] as $line) {
            if (preg_match('/^##\s+v?(\d+\.\d+\.\d+)\b(.*)$/u', $line, $m) === 1) {
                if ($current !== null) {
                    $sections[] = $current;
                }

                $current = ['version' => $m[1], 'title' => trim('v'.$m[1].' '.trim($m[2])), 'body' => ''];

                continue;
            }

            if ($current !== null) {
                $current['body'] .= $line."\n";
            }
        }

        if ($current !== null) {
            $sections[] = $current;
        }

        usort($sections, fn (array $a, array $b) => version_compare($b['version'], $a['version']));

        return array_map(fn (array $s) => [
            'version' => $s['version'],
            'title' => $s['title'],
            'html' => Str::markdown(trim($s['body']), ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ], $sections);
    }
}
