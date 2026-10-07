<?php

declare(strict_types=1);

namespace App\Core\Engines\Overview;

/**
 * ⭐ নিশ্চিতের আগে সারাংশ — কেন্দ্রীয় ইঞ্জিন (মালিক, ৪ অক্টোবর ২০২৬): *"নিশ্চিত করুন botam caple ekta overvew dekhabe
 * … sob kichutei ei over vew dekhabe popupe"*, *"proyojone central engine lagle seta banaw"*।
 *
 * ⓘ প্রতিটা কাগজ (সরাসরি বিক্রয়, ক্রয়, চালান, বিল, আদায়, ভাউচার, নোট, ফেরত, আদেশ) একই আকারে নিজের সারাংশ গড়ে;
 * ওয়েবের পপ-আপ ([[confirm-overview]]) আর অ্যাপের নিচ থেকে ওঠা পাতা — দুটোই এই এক আকার আঁকে। তাই নতুন কাগজে
 * কেবল একটা "সারাংশ-গড়া" লিখতে হয়, পর্দা নয়।
 * ⛔ সারাংশ কেবল দেখায় — কিছু লেখে না, কোনো পাহারা এড়ায় না। আসল পাহারা (সীমা, সই, লট, দর) নিশ্চিতের দরজাতেই।
 * ⓘ এক লাইনে এক জিনিস (মালিকের নিয়ম) — তাই আকারটা সারি-ভিত্তিক, টেবিল নয়।
 */
final class ConfirmOverview
{
    /** @var list<array{label: string, value: string}> */
    private array $head = [];

    /** @var list<array{title: string, details: list<string>, amount: ?string}> */
    private array $lines = [];

    /** @var list<array{label: string, amount: string, strong: bool}> */
    private array $totals = [];

    /** @var list<array{label: string, amount: string, tone: string}> */
    private array $money = [];

    /** @var list<array{text: string, tone: string}> */
    private array $notes = [];

    public function __construct(private readonly string $title) {}

    public static function titled(string $title): self
    {
        return new self($title);
    }

    /** মাথার এক সারি — পক্ষ, তারিখ, শর্ত, গুদাম … */
    public function head(string $label, ?string $value): self
    {
        if ($value !== null && trim($value) !== '') {
            $this->head[] = ['label' => $label, 'value' => $value];
        }

        return $this;
    }

    /**
     * কাগজের এক সারি — শিরোনাম (পণ্য), নিচে এক লাইনে এক তথ্য (লট, পরিমাণ × দর, ফ্রি, ছাড়), আর সারির টাকা।
     *
     * @param  list<string|null>  $details
     */
    public function line(string $title, array $details = [], ?string $amount = null): self
    {
        $this->lines[] = [
            'title' => $title,
            'details' => array_values(array_filter($details, fn ($d) => $d !== null && trim((string) $d) !== '')),
            'amount' => $amount,
        ];

        return $this;
    }

    /** মোটের এক সারি — `strong` হলে মোটা (যেমন নিট বিল) */
    public function total(string $label, string $amount, bool $strong = false): self
    {
        $this->totals[] = ['label' => $label, 'amount' => $amount, 'strong' => $strong];

        return $this;
    }

    /** টাকার অবস্থান — জমা, বকেয়া, অবশিষ্ট সীমা …; `tone` = plain · good · bad */
    public function money(string $label, string $amount, string $tone = 'plain'): self
    {
        $this->money[] = ['label' => $label, 'amount' => $amount, 'tone' => $tone];

        return $this;
    }

    /** সতর্কতা বা খবর — `tone` = info · warn · stop (সীমা পার, কার সই লাগবে, নিশ্চিত হবে না …) */
    public function note(string $text, string $tone = 'info'): self
    {
        $this->notes[] = ['text' => $text, 'tone' => $tone];

        return $this;
    }

    /** নিশ্চিত চাপলে কি সার্ভার থামাবে — `stop` সতর্কতা থাকলে হ্যাঁ (পর্দা তখন "নিশ্চিত" ধূসর করে, খসড়া খোলা থাকে) */
    public function blocks(): bool
    {
        foreach ($this->notes as $note) {
            if ($note['tone'] === 'stop') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{title: string, head: list<array{label: string, value: string}>, lines: list<array{title: string, details: list<string>, amount: ?string}>, totals: list<array{label: string, amount: string, strong: bool}>, money: list<array{label: string, amount: string, tone: string}>, notes: list<array{text: string, tone: string}>, blocks: bool}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'head' => $this->head,
            'lines' => $this->lines,
            'totals' => $this->totals,
            'money' => $this->money,
            'notes' => $this->notes,
            'blocks' => $this->blocks(),
        ];
    }
}
