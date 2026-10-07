<?php

declare(strict_types=1);

namespace App\Core\Engines\Print;

use App\Models\Company;

/**
 * কাগজের নকশার সাজ — ছকের ঘর, টাকার বাক্স, কোম্পানির মাথা; সব কাগজের এক জায়গা।
 *
 * মালিক, ৩০ সেপ্টেম্বর ২০২৬: ভাউচার · চালান · আদেশ · রসিদ — প্রতিটায় ২০টা নকশা, প্রথম ৫টা বিশ্বের নতুন,
 * পরের ৫টা বিশ্বের জনপ্রিয়, পরের ৫টা আধুনিক, বাকিগুলো আমাদের — *"sobgulor khetrei ekoi stayle"*।
 *
 * ── ⚠️ কেন ক্লাস, Blade-এর ভেতরে নয় ────────────────────────────────────
 * `@include`-এর ভেতরের চলক বাইরে আসে না। ছকের ঘরের সাজ তাই প্রতিটা কাগজের partial-এ আলাদা
 * লেখা হত — আর একদিন ভাউচারের "ঘরকাটা" চালানের "ঘরকাটা" থাকত না। ⓘ এখানে একবার, সবাই ডাকে।
 *
 * ⓘ mPDF বংশধর-বাছাই সব জায়গায় মানে না, তাই এগুলো inline style ফেরত দেয়।
 */
final class PaperLook
{
    /** @param array<string, mixed> $look */
    public function __construct(public readonly array $look) {}

    public function accent(): string
    {
        return (string) $this->look['accent'];
    }

    public function tint(): string
    {
        return (string) ($this->look['tint'] ?? '#f4f6f8');
    }

    public function ink(): string
    {
        return (string) ($this->look['ink'] ?? '#1b1f24');
    }

    public function lang(): string
    {
        return (string) ($this->look['lang'] ?? 'en');
    }

    /**
     * QR মাথায় নয়, সইয়ের সারিতে — শেষ সইয়ের ("Received by") ডানে।
     *
     * ⓘ মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"1, 3, 5, 7, 12 number er qr Received by er dane daw"* — পাঁচটাই পুরো চওড়া
     * পট্টি বা ব্লকের মাথা (band · dark · brutal), যেখানে QR মাথার নিচে একা ঝুলত। ⚠️ মাথাটা তখন QR আঁকে না —
     * তাই QR-ওয়ালা প্রতিটা কাগজের partial এটা জিজ্ঞেস করে নিজে বসায়, নাহলে QR হারাত।
     */
    public function qrAtSignatures(): bool
    {
        return in_array($this->look['head'] ?? '', ['band', 'dark', 'brutal'], true);
    }

    /**
     * কার্ডের ছোট শিরোনামের রং — সাধারণত নকশার রং।
     *
     * ⚠️ নিও-ব্রুটালের রং উজ্জ্বল হলুদ: সাদা জমিনে ছোট লেখা পড়াই যেত না, তাই সেখানে কালো।
     */
    public function capColor(): string
    {
        return ($this->look['head'] ?? '') === 'brutal' ? '#000' : $this->accent();
    }

    /** তথ্যের কার্ড — কাকে, কোথায়, কীভাবে; রঙিন জমিন, উপরের দাগ, ঘেরা বাক্স বা খালি */
    public function card(): string
    {
        $ac = $this->accent();

        return $this->sized(match ($this->look['cards'] ?? 'tint') {
            'line' => "border-top: 0.8mm solid {$ac}; padding: 2.5mm 0;",
            'box' => "border: 0.3mm solid {$this->ink()}; padding: 2.5mm 3mm;",
            'brutal' => 'border: 0.7mm solid #000; padding: 2.5mm 3mm;',
            'plain' => 'padding: 1mm 0;',
            default => "background: {$this->tint()}; padding: 3mm 3.5mm;",
        });
    }

    /** ছকের মাথার ঘর */
    public function th(): string
    {
        $ac = $this->accent();
        $ink = $this->ink();

        return $this->sized(match ($this->look['table'] ?? 'rows') {
            'dark' => "background: {$ac}; color: #fff;",
            'grid' => "border: 0.3mm solid {$ink}; background: {$this->tint()};",
            'zebra' => "background: {$this->tint()}; color: {$ac}; border-bottom: 0.5mm solid {$ac};",
            'clean' => 'color: #667085; border-bottom: 0.3mm solid #d0d5dd;',
            'underline' => "border-bottom: 1mm solid {$ac}; color: {$ink};",
            'brutal' => "background: #000; color: {$ac}; border: 0.6mm solid #000;",
            default => "border-bottom: 0.5mm solid {$ink};",
        });
    }

    /** ছকের সাধারণ ঘর — $row শূন্য থেকে গোনা */
    public function td(int $row): string
    {
        $ink = $this->ink();

        return $this->sized(match ($this->look['table'] ?? 'rows') {
            'grid' => "border: 0.3mm solid {$ink};",
            'zebra' => $row % 2 === 1 ? "background: {$this->tint()};" : '',
            'clean' => '',
            'brutal' => 'border: 0.6mm solid #000;',
            'dark', 'underline' => 'border-bottom: 0.2mm solid #e4e7ec;',
            default => 'border-bottom: 0.2mm solid #d0d5dd;',
        });
    }

    /** যোগফলের সারি */
    public function totalRow(): string
    {
        $ac = $this->accent();

        return $this->sized(match ($this->look['table'] ?? 'rows') {
            'grid' => "border: 0.3mm solid {$this->ink()}; background: {$this->tint()};",
            'brutal' => "border: 0.6mm solid #000; background: {$ac};",
            'dark' => "background: {$this->tint()}; color: {$ac}; border-top: 0.5mm solid {$ac};",
            default => "border-top: 0.5mm solid {$this->ink()};",
        });
    }

    /**
     * বড় টাকার বাক্স — "কত টাকা"। প্রশ্নটা কাগজের প্রথম প্রশ্ন, তাই সবচেয়ে বড় অঙ্কে।
     */
    public function amountBox(string $label, string $value, string $words = ''): string
    {
        $ac = $this->accent();
        $tint = $this->tint();
        $r = ($this->look['radius'] ?? 0).'mm';
        $w = $words !== '' ? '<div style="font-size: 8pt; margin-top: 1mm">'.e($words).'</div>' : '';
        $l = '<div style="font-size: 7.5pt; font-weight: bold; letter-spacing: 0.4mm">'.e($label).'</div>';
        $v = fn (string $css) => '<div style="font-family: dejavusans; font-weight: bold; '.$css.'">'.e($value).'</div>';

        /*
         * ⚠️ বাক্সটা এক ঘরের ছক, div নয় — mPDF ছকের ঘরের ভেতরের div-এ রং আর দাগ আঁকে না, কেবল
         * লেখার পেছনে রং বসায় (হাইলাইটের মতো দেখায়)। ঘরের নিজের রং-দাগ ঠিক আঁকে।
         */
        $cell = fn (string $css, string $inner) => '<table style="width: 100%"><tr><td style="'.$css.'">'.$inner.'</td></tr></table>';
        $white = fn (string $html) => str_replace('<div style="', '<div style="color: #fff; ', $html);

        return $this->sized(match ($this->look['amount'] ?? 'box') {
            'fill' => $cell('background: '.$ac.'; color: #fff; padding: 4mm 5mm;', $white($l.$v('font-size: 20pt').$w)),
            'line' => $cell('border-top: 0.4mm solid '.$this->ink().'; border-bottom: 1.2mm double '.$this->ink().'; padding: 3mm 0;', $l.$v('font-size: 18pt').$w),
            'big' => $cell('padding: 1mm 0;', $l.$v('font-size: 28pt; color: '.$ac).$w),
            'card' => $cell('background: '.$tint.'; padding: 4mm 5mm;', $l.$v('font-size: 20pt; color: '.$ac).$w),
            'brutal' => $cell('background: '.$ac.'; border: 0.9mm solid #000; padding: 4mm 5mm;', $l.$v('font-size: 20pt; color: #000').$w),
            default => $cell('border: 0.6mm solid '.$ac.'; padding: 4mm 5mm;', $l.$v('font-size: 18pt; color: '.$ac).$w),
        });
    }

    /**
     * কোম্পানির মাথা — নাম, ঠিকানা, যোগাযোগ, BIN/TIN, লোগো।
     *
     * ⓘ Core কোনো মডিউলের উপর দাঁড়ায় না — তাই কোনো মডিউলের ছাপার সেটিং নয়, কোম্পানির নিজের তথ্য।
     *
     * @return array{name: string, address: string, contact: string, tax: string, logo: ?string}
     */
    public static function head(Company $company, bool $withLogo = true): array
    {
        return [
            'name' => (string) $company->name('en'),
            'address' => (string) ($company->address('en') ?? ''),
            'contact' => implode(' · ', array_filter([(string) ($company->phone ?? ''), (string) ($company->email ?? '')])),
            'tax' => trim(implode('   ', array_filter([
                filled($company->bin) ? 'BIN '.$company->bin : null,
                filled($company->tin) ? 'TIN '.$company->tin : null,
            ]))),
            'logo' => $withLogo ? $company->logoData() : null,
        ];
    }

    // ── থার্মাল রোল (৮০মিমি) — রং নেই, কেবল কালো; একই নকশার সাদা-কালো রূপ ─────────────

    /** থার্মালের দাগ — ঘরকাটা/মোটা নকশায় পুরো, ব্রুটালে দুই দাগ, বাকিগুলোয় ড্যাশ */
    public function thermalRule(): string
    {
        return match ($this->look['table'] ?? 'rows') {
            'grid', 'underline', 'dark' => '0.3mm solid #000',
            'brutal' => '0.9mm double #000',
            default => '0.3mm dashed #000',
        };
    }

    /** থার্মালের মোট টাকা — পট্টি-মাথায় কালো ব্লক, বাক্স, দুই দাগ বা বড় অঙ্ক */
    public function thermalAmount(string $label, string $value, string $words = ''): string
    {
        $w = $words !== '' ? '<div style="font-size: 7pt; margin-top: 0.6mm">'.e($words).'</div>' : '';
        $row = fn (string $css, string $size) => '<table style="width: 100%"><tr><td style="'.$css.'">'
            .'<table style="width: 100%"><tr><td style="font-weight: bold; font-size: 8pt; vertical-align: middle;'.(str_contains($css, '#000; color: #fff') ? ' color: #fff;' : '').'">'.e($label).'</td>'
            .'<td style="text-align: right; font-family: dejavusans; font-weight: bold; font-size: '.$size.'; white-space: nowrap;'.(str_contains($css, '#000; color: #fff') ? ' color: #fff;' : '').'">'.e($value).'</td></tr></table>'
            .(str_contains($css, '#000; color: #fff') ? str_replace('<div style="', '<div style="color: #fff; ', $w) : $w).'</td></tr></table>';

        return match ($this->look['amount'] ?? 'box') {
            'fill', 'brutal' => $row('background: #000; color: #fff; padding: 1.8mm 2mm;', '13pt'),
            'line' => $row('border-top: 0.9mm double #000; border-bottom: 0.9mm double #000; padding: 1.5mm 0;', '12pt'),
            'big' => $row('padding: 1mm 0;', '17pt'),
            default => $row('border: 0.5mm solid #000; padding: 1.8mm 2mm;', '12pt'),
        };
    }

    /** থার্মালের অক্ষর — নকশার মোনো/সেরিফ, নাহলে বাংলা-সহ সাধারণ */
    public function thermalFont(): string
    {
        return match ($this->look['font'] ?? 'sans') {
            'mono' => 'dejavusansmono',
            'serif' => 'dejavuserif',
            default => 'hindsiliguri',
        };
    }

    /**
     * কাগজের মাপে ছোট করা — A5-এ mm ×০.৭২, অক্ষর ×০.৮৫ (নিচে সীমা ৬.৫pt); A4-এ যেমন আছে।
     *
     * ⓘ A4-এর নকশা থেকে A5-এর নকশা বানানোর সেই একই নিয়ম (বিলের A5 নকশাগুলোয় যা মাপা)। ⚠️ এক জায়গায়,
     * যাতে টাকার বাক্স, কার্ড আর ছকের ঘর একসাথে ছোট হয় — একটা বাদ পড়লে A5-এ সেটাই বেঢপ বড় দেখাত।
     */
    private function sized(string $css): string
    {
        if (($this->look['size'] ?? 'a4') !== 'a5') {
            return $css;
        }

        $css = (string) preg_replace_callback('/(\d+(?:\.\d+)?)mm/', fn ($m) => round((float) $m[1] * 0.72, 2).'mm', $css);

        return (string) preg_replace_callback('/(\d+(?:\.\d+)?)pt/', function ($m) {
            $v = (float) $m[1];

            return ($v >= 6.5 ? max(6.5, round($v * 0.85, 1)) : $v).'pt';
        }, $css);
    }
}
