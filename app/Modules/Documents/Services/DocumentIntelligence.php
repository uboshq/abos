<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Models\Attachment;
use App\Modules\Documents\Models\AbeRule;
use App\Modules\Documents\Models\DocumentOcr;
use App\Modules\Documents\Models\DocumentVersion;

/**
 * Document Intelligence (ABE) — নিয়ম, প্যাটার্ন আর গোনা, আমাদের নিজের সার্ভারে
 * (পরিকল্পনা §৮; ষষ্ঠ ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ── ⛔ মালিকের প্রথম বাঁধন ───────────────────────────────────────────────
 * কোনো বাইরের AI নয়, কোনো ভাষা-মডেল নয়, কোনো কাগজ বাইরে যায় না। প্রতিটা কাজ এমন নিয়মে, যা একজন মানুষ
 * পড়ে বুঝতে পারেন — আর পর্দা প্রতিটা ফলের সাথে **কেন** বলে (কোন শব্দ মিলল, কোন লাইন কেন নেওয়া হলো)।
 *
 * ── ⓘ ছয়টা কাজ ─────────────────────────────────────────────────────────
 * শ্রেণি চেনা — ধরনের শব্দ গুনে, মালিকের মূল তালিকা ([[BUILT_IN]]) আর কোম্পানির নিজের নিয়ম ([[AbeRule]])।
 * তথ্য তোলা — [[DocumentFieldExtractor]]-এর চারটা তথ্য, আর ধরন ধরে কোম্পানির নিজের প্যাটার্ন।
 * সারাংশ — **লেখা থেকে বাছা** লাইন (নতুন বাক্য বানানো নয়), নিয়মে নম্বর দিয়ে; পর্দায় সেটা লেখা থাকে।
 * তুলনা — দুই ভার্সনের লেখার লাইন-ধরে পার্থক্য আর ফাইলের তথ্যের পার্থক্য।
 * প্রশ্ন — কাগজের নিজের লেখায় শব্দ খোঁজা, মিল দাগানো।
 * অনুবাদ — কেবল ঘরের নামের ছোট শব্দকোষ (বাংলা ↔ ইংরেজি); পুরো লেখার অনুবাদ নয়, পর্দায় সেটা বলা।
 *
 * ⓘ লেখা কোথা থেকে ([[textOf()]]): ভার্সনের পড়া লেখা (OCR), নয়তো সাদা লেখার ফাইল (txt, csv) নিজেই।
 */
final class DocumentIntelligence
{
    /**
     * মালিকের ধরনের মূল শব্দ — বাংলা আর ইংরেজি। ⓘ কোম্পানি প্রশাসনে নিজের শব্দ যোগ করে।
     *
     * @var array<string, list<string>>
     */
    public const BUILT_IN = [
        'invoice' => ['invoice', 'bill', 'challan', 'total', 'vat', 'qty', 'unit price', 'বিল', 'চালান', 'মোট', 'পরিমাণ', 'দর'],
        'contract' => ['contract', 'agreement', 'party', 'term', 'clause', 'witness', 'চুক্তি', 'পক্ষ', 'শর্ত', 'সাক্ষী'],
        'agreement' => ['memorandum', 'mou', 'understanding', 'সমঝোতা', 'স্মারক'],
        'license' => ['license', 'licence', 'trade license', 'permit', 'valid until', 'লাইসেন্স', 'অনুমতি', 'মেয়াদ'],
        'certificate' => ['certificate', 'certify', 'certified', 'সনদ', 'প্রত্যয়ন', 'প্রত্যয়নপত্র'],
        'letter' => ['dear', 'subject', 'sincerely', 'regards', 'বিষয়', 'জনাব', 'বরাবর', 'বিনীত'],
        'policy' => ['policy', 'procedure', 'shall', 'must', 'নীতিমালা', 'নিয়ম', 'পদ্ধতি'],
        'report' => ['report', 'summary', 'findings', 'analysis', 'প্রতিবেদন', 'সারসংক্ষেপ'],
        'identity' => ['national id', 'nid', 'passport', 'date of birth', 'জাতীয় পরিচয়পত্র', 'জন্ম তারিখ', 'পিতা', 'মাতা'],
        'form' => ['form', 'application', 'signature of applicant', 'আবেদন', 'ফরম', 'ফর্ম'],
    ];

    /** ধরন → মালিকের ফোল্ডার — শ্রেণির সাথে ফোল্ডারেরও প্রস্তাব */
    public const FOLDER_OF = [
        'invoice' => 'purchase', 'contract' => 'contracts', 'agreement' => 'contracts', 'license' => 'compliance',
        'certificate' => 'compliance', 'letter' => 'company', 'policy' => 'company', 'report' => 'company',
        'identity' => 'hr', 'form' => 'company',
    ];

    /**
     * ঘরের নামের শব্দকোষ — বাংলা ↔ ইংরেজি (§৮ Translate)। ⛔ কেবল এটুকুই; পুরো লেখার অনুবাদ নয়।
     *
     * @var array<string, string>
     */
    public const GLOSSARY = [
        'invoice' => 'বিল', 'challan' => 'চালান', 'date' => 'তারিখ', 'total' => 'মোট', 'grand total' => 'সর্বমোট',
        'amount' => 'অঙ্ক', 'quantity' => 'পরিমাণ', 'rate' => 'দর', 'supplier' => 'সরবরাহকারী', 'customer' => 'গ্রাহক',
        'agreement' => 'চুক্তি', 'party' => 'পক্ষ', 'signature' => 'সই', 'witness' => 'সাক্ষী', 'expiry date' => 'মেয়াদের তারিখ',
        'license' => 'লাইসেন্স', 'certificate' => 'সনদ', 'subject' => 'বিষয়', 'address' => 'ঠিকানা', 'name' => 'নাম',
        'father' => 'পিতা', 'mother' => 'মাতা', 'date of birth' => 'জন্ম তারিখ', 'vat' => 'ভ্যাট', 'discount' => 'ছাড়',
    ];

    /** সারাংশের নম্বরের শব্দ — এগুলো থাকলে লাইনটা জরুরি */
    private const KEY_WORDS = ['total', 'amount', 'date', 'party', 'subject', 'agreement', 'valid', 'expiry', 'invoice',
        'মোট', 'সর্বমোট', 'তারিখ', 'পক্ষ', 'বিষয়', 'চুক্তি', 'মেয়াদ', 'বিল', 'টাকা'];

    public function __construct(
        private readonly AttachmentEngine $attachments,
        private readonly DocumentFieldExtractor $fields,
    ) {}

    /** একটা ভার্সনের লেখা — OCR, নয়তো সাদা লেখার ফাইল; কিছু না থাকলে null */
    public function textOf(?DocumentVersion $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $ocr = DocumentOcr::query()->where('version_id', $version->id)->value('text');

        if (filled($ocr)) {
            return (string) $ocr;
        }

        $file = Attachment::query()->find($version->attachment_id);

        if ($file !== null && in_array($file->mime_type, ['text/plain', 'text/csv'], true) && $this->attachments->exists($file)) {
            return mb_substr($this->attachments->contents($file), 0, 200000);
        }

        return null;
    }

    /**
     * শ্রেণি চেনা — প্রতিটা ধরনের নম্বর (শব্দ মেলার গোনা × ওজন), সবচেয়ে বেশিটা আগে, কোন শব্দ মিলল সহ।
     *
     * @return list<array{type: string, folder: ?string, score: int, words: list<string>}>
     */
    public function classify(string $text): array
    {
        $haystack = mb_strtolower($text);
        $scores = [];

        $add = function (string $type, array $words, int $weight) use ($haystack, &$scores) {
            foreach ($words as $word) {
                $hits = $word === '' ? 0 : mb_substr_count($haystack, $word);

                if ($hits > 0) {
                    $scores[$type]['score'] = ($scores[$type]['score'] ?? 0) + min($hits, 5) * $weight;
                    $scores[$type]['words'][] = $word;
                }
            }
        };

        foreach (self::BUILT_IN as $type => $words) {
            $add($type, $words, 1);
        }

        foreach (AbeRule::query()->active()->where('kind', AbeRule::CLASSIFY)->get() as $rule) {
            $add((string) $rule->doc_type, $rule->keywordList(), max(1, (int) $rule->weight));
        }

        $out = [];

        foreach ($scores as $type => $row) {
            $out[] = [
                'type' => $type,
                'folder' => self::FOLDER_OF[$type] ?? null,
                'score' => (int) $row['score'],
                'words' => array_values(array_unique($row['words'])),
            ];
        }

        usort($out, fn ($a, $b) => [$b['score'], $a['type']] <=> [$a['score'], $b['type']]);

        return array_slice($out, 0, 3);
    }

    /**
     * তথ্য তোলা — চারটা মূল তথ্য, আর এই ধরনের কাগজে কোম্পানির নিজের প্যাটার্ন।
     *
     * @return list<array{label: string, value: ?string, rule: string}>
     */
    public function extract(string $text, ?string $docType): array
    {
        $out = [];

        foreach ($this->fields->extract($text) as $field => $value) {
            $out[] = ['label' => __('documents::field.ocr_'.$field), 'value' => $value, 'rule' => 'built_in'];
        }

        $plain = DocumentFieldExtractor::englishDigits($text);

        foreach (AbeRule::query()->active()->where('kind', AbeRule::EXTRACT)->where('doc_type', (string) $docType)->get() as $rule) {
            $value = null;

            // ⛔ ভাঙা প্যাটার্ন পর্দা ভাঙে না — কেবল ফল খালি
            if (@preg_match(self::delimited((string) $rule->pattern), $plain, $m) === 1) {
                $value = trim((string) ($m[1] ?? $m[0]));
            }

            $out[] = ['label' => (string) $rule->label, 'value' => $value === '' ? null : $value, 'rule' => 'company'];
        }

        return $out;
    }

    /**
     * সারাংশ — লেখা থেকেই বাছা লাইন, মূল ক্রমে।
     *
     * ⓘ নম্বরের নিয়ম: সংখ্যা/তারিখ থাকলে +২, জরুরি শব্দ থাকলে প্রতিটায় +২, প্রথম তিন লাইনে +৩ (শিরোনাম),
     * খুব ছোট লাইন (৩ শব্দের কম) বাদ।
     *
     * @return list<array{line: string, why: list<string>}>
     */
    public function summarize(string $text, int $lines = 5): array
    {
        $rows = array_values(array_filter(array_map('trim', preg_split('/\R|(?<=[.!?।])\s+/u', $text) ?: []),
            fn ($l) => count(preg_split('/\s+/u', $l) ?: []) >= 3));

        $scored = [];

        foreach ($rows as $i => $line) {
            $lower = mb_strtolower($line);
            $why = [];
            $score = 0;

            if (preg_match('/[\d০-৯]/u', $line) === 1) {
                $score += 2;
                $why[] = 'number';
            }

            foreach (self::KEY_WORDS as $word) {
                if (str_contains($lower, $word)) {
                    $score += 2;
                    $why[] = $word;
                }
            }

            if ($i < 3) {
                $score += 3;
                $why[] = 'heading';
            }

            $scored[] = ['i' => $i, 'score' => $score, 'line' => $line, 'why' => $why];
        }

        usort($scored, fn ($a, $b) => [$b['score'], $a['i']] <=> [$a['score'], $b['i']]);
        $picked = array_slice($scored, 0, max(1, $lines));
        usort($picked, fn ($a, $b) => $a['i'] <=> $b['i']);

        return array_map(fn ($r) => ['line' => $r['line'], 'why' => $r['why']], $picked);
    }

    /**
     * তুলনা — দুই ভার্সনের ফাইলের তথ্য আর লেখার লাইন-ধরে পার্থক্য।
     *
     * @return array{meta: list<array{label: string, old: string, new: string, same: bool}>, lines: list<array{op: string, line: string}>, text: bool}
     */
    public function compare(DocumentVersion $old, DocumentVersion $new): array
    {
        $a = Attachment::query()->find($old->attachment_id);
        $b = Attachment::query()->find($new->attachment_id);

        $meta = [];

        foreach ([
            'version' => ['v'.$old->label(), 'v'.$new->label()],
            'file' => [(string) $a?->original_name, (string) $b?->original_name],
            'size' => [(string) $a?->humanSize(), (string) $b?->humanSize()],
            'file_hash' => [(string) $old->file_hash, (string) $new->file_hash],
            'author' => [(string) $old->author?->name, (string) $new->author?->name],
            'date' => [(string) $old->created_at?->toDateTimeString(), (string) $new->created_at?->toDateTimeString()],
            'comment' => [(string) $old->comment, (string) $new->comment],
        ] as $key => [$x, $y]) {
            $meta[] = ['label' => __('documents::field.'.$key), 'old' => $x, 'new' => $y, 'same' => $x === $y];
        }

        $left = $this->textOf($old);
        $right = $this->textOf($new);

        return [
            'meta' => $meta,
            'lines' => ($left === null || $right === null) ? [] : self::diff($left, $right),
            'text' => $left !== null && $right !== null,
        ];
    }

    /**
     * প্রশ্ন — কাগজের নিজের লেখায় শব্দ খোঁজা; প্রতিটা মিলের লাইন, মিলটা `<mark>`-এ (লেখা আগে নিরাপদ করা)।
     *
     * @return list<array{line: int, html: string}>
     */
    public function ask(string $text, string $question): array
    {
        $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($question))) ?: [], fn ($w) => mb_strlen($w) >= 2));

        if ($words === []) {
            return [];
        }

        $hits = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $n => $line) {
            $lower = mb_strtolower($line);
            $found = array_filter($words, fn ($w) => str_contains($lower, $w));

            if ($found === []) {
                continue;
            }

            $html = e($line);

            foreach ($found as $word) {
                $html = (string) preg_replace('/('.preg_quote(e($word), '/').')/iu', '<mark>$1</mark>', $html);
            }

            $hits[] = ['line' => $n + 1, 'html' => $html, 'count' => count($found)];
        }

        usort($hits, fn ($a, $b) => [$b['count'], $a['line']] <=> [$a['count'], $b['line']]);

        return array_map(fn ($h) => ['line' => $h['line'], 'html' => $h['html']], array_slice($hits, 0, 30));
    }

    /**
     * শব্দকোষে লেখার ভিতরে যে শব্দগুলো আছে — দুই ভাষায় (§৮ Translate, কেবল ঘরের নাম)।
     *
     * @return list<array{en: string, bn: string}>
     */
    public function glossaryFor(string $text): array
    {
        $lower = mb_strtolower($text);
        $out = [];

        foreach (self::GLOSSARY as $en => $bn) {
            if (str_contains($lower, $en) || str_contains($text, $bn)) {
                $out[] = ['en' => $en, 'bn' => $bn];
            }
        }

        return $out;
    }

    /** প্রশাসনের প্যাটার্ন — `/…/u` না দিলে নিজে বসানো */
    public static function delimited(string $pattern): string
    {
        return str_starts_with($pattern, '/') ? $pattern : '/'.str_replace('/', '\/', $pattern).'/iu';
    }

    /** প্যাটার্ন ঠিক কি না — প্রশাসনের ফর্মের যাচাই */
    public static function validPattern(string $pattern): bool
    {
        return @preg_match(self::delimited($pattern), '') !== false;
    }

    /**
     * লাইন-ধরে পার্থক্য (LCS)। ⓘ খুব লম্বা লেখায় (৪০০ লাইনের বেশি) কেবল প্রথম ৪০০ — শেয়ার্ড সার্ভারের মেমরি।
     *
     * @return list<array{op: string, line: string}> op: same, add, del
     */
    public static function diff(string $left, string $right): array
    {
        $a = array_slice(preg_split('/\R/u', $left) ?: [], 0, 400);
        $b = array_slice(preg_split('/\R/u', $right) ?: [], 0, 400);
        $n = count($a);
        $m = count($b);
        $l = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $l[$i][$j] = $a[$i] === $b[$j] ? $l[$i + 1][$j + 1] + 1 : max($l[$i + 1][$j], $l[$i][$j + 1]);
            }
        }

        $out = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['op' => 'same', 'line' => $a[$i]];
                $i++;
                $j++;
            } elseif ($l[$i + 1][$j] >= $l[$i][$j + 1]) {
                $out[] = ['op' => 'del', 'line' => $a[$i++]];
            } else {
                $out[] = ['op' => 'add', 'line' => $b[$j++]];
            }
        }

        while ($i < $n) {
            $out[] = ['op' => 'del', 'line' => $a[$i++]];
        }

        while ($j < $m) {
            $out[] = ['op' => 'add', 'line' => $b[$j++]];
        }

        return $out;
    }
}
