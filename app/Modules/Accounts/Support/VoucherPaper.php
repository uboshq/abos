<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Support;

use App\Core\Engines\Print\PaperLook;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Services\SettingsService;
use App\Core\Support\BanglaDigits;
use App\Models\Company;

/**
 * ভাউচারের ২১ নকশার ভাগের কাজ — মালিক, ১২ অক্টোবর ২০২৬: *"ei gulo control panel e bosaw sobgulo"*।
 *
 * ⓘ প্রতিটা নকশা (`resources/views/print/voucher-*`) কেবল সাজ লেখে; ভাষা, বাংলা অঙ্ক, মাথা, "কততম ছাপা", সুইচ
 * আর সইয়ের ঘর এখানে একবার — একটায় ঠিক করলে ২১টাতেই ঠিক।
 * ⚠️ mPDF: flex/grid/CSS var চলে না, তাই সব টেবিল আর inline রং। ⛔ সুইচ মানা হয় আগের মতোই (meta · totals ·
 * words · narration · signatures · logo) — নতুন সাজ বন্ধ করা অংশ ফেরায় না।
 * ⓘ বাংলায় টাকা, পরিমাণ আর তারিখ বাংলা অঙ্কে; নম্বর-ধরনের পরিচয় (ভাউচার নং, TrxID, চেক নং) ইংরেজি অঙ্কে।
 */
final class VoucherPaper
{
    /** A4-এ রসিদ কয় কপি — মালিক, ১২ অক্টোবর ২০২৬: *"control penel e set kora zay ekta na duto"* */
    public const COPIES = 'accounts.print.voucher_copies';

    public readonly string $lang;

    /** @var array<string, mixed> */
    public readonly array $v;

    /** @var array<string, mixed> */
    private readonly array $d;

    /**
     * @param  array<string, mixed>  $voucher  [[VoucherPrintController::paper()]]-এর রূপ
     * @param  list<string>  $signatures
     */
    public function __construct(
        array $voucher,
        ?string $lang,
        public readonly Company $company,
        public readonly PrintProfile $profile,
        public readonly array $signatures,
        public readonly ?string $notice,
        private readonly ?SettingsService $settings = null,
    ) {
        $this->v = $voucher;
        $this->d = (array) ($voucher['details'] ?? []);
        $this->lang = $lang === 'bn' ? 'bn' : 'en';
    }

    /** @param  array<string, mixed>  $data  নকশার কাছে আসা সব চলক */
    public static function from(array $data): self
    {
        return new self(
            (array) ($data['voucher'] ?? []),
            (string) ($data['locale'] ?? app()->getLocale()),
            $data['company'],
            $data['profile'],
            array_values((array) ($data['signatures'] ?? [])),
            isset($data['notice']) ? (string) $data['notice'] : null,
            $data['settings'] ?? null,
        );
    }

    public function t(string $key, array $replace = []): string
    {
        return (string) __('accounts::vx.'.$key, $replace, $this->lang);
    }

    /** অন্য ভাষায় একই শব্দ — দ্বিভাষিক নকশার জন্য */
    public function tIn(string $lang, string $key): string
    {
        return (string) __('accounts::vx.'.$key, [], $lang);
    }

    /** টাকা, পরিমাণ, তারিখ — বাংলায় বাংলা অঙ্ক */
    public function n(string $text): string
    {
        return $this->lang === 'bn' ? BanglaDigits::of($text) : $text;
    }

    /** অঙ্কের অক্ষর — dejavusans-এ বাংলা অঙ্ক নেই, তাই বাংলায় বাংলার অক্ষরই */
    public function numFont(): string
    {
        return $this->lang === 'bn' ? 'hindsiliguri' : 'dejavusans';
    }

    /** অক্ষরের ফাঁক কেবল ইংরেজিতে — বাংলায় যুক্তাক্ষর ভেঙে "প রি শো ধ" হত */
    public function ls(string $gap): string
    {
        return $this->lang === 'bn' ? '' : 'letter-spacing: '.$gap.';';
    }

    public function money(string $amount): string
    {
        return $this->n($this->t('tk').' '.$amount);
    }

    /** কাগজের বাড়তি ঘর ([[VoucherPrintController::details()]]); না থাকলে খালি */
    public function d(string $key): string
    {
        $value = $this->d[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /** @return list<array<string, string>> */
    public function list(string $key): array
    {
        return array_values((array) ($this->d[$key] ?? []));
    }

    /** @return array<string, int> */
    public function notes(): array
    {
        return array_map('intval', (array) ($this->d['notes'] ?? []));
    }

    public function shows(string $part): bool
    {
        return $this->profile->shows($part);
    }

    public function isReceipt(): bool
    {
        return ($this->v['type'] ?? '') === 'receipt';
    }

    /** কার কাছ থেকে / কাকে — পক্ষ, নাহলে প্রাপকের নাম */
    public function counterparty(): string
    {
        return $this->d('party') !== '' ? $this->d('party') : $this->d('payee');
    }

    public function counterpartyLabel(): string
    {
        return $this->t($this->isReceipt() ? 'received_from' : 'paid_to');
    }

    /** মাধ্যম, সাথে চেক/TrxID নম্বর ইংরেজি অঙ্কে */
    public function method(): string
    {
        return trim(implode(' · ', array_filter([$this->d('method'), $this->d('wallet'), $this->d('instrument_no')])));
    }

    public function billAdjustment(): string
    {
        return $this->d('against') === '' ? '' : $this->t('adjusted_against', ['no' => $this->d('against')]);
    }

    /** @return array{name: string, address: string, contact: string, tax: string, logo: ?string} */
    public function head(): array
    {
        return PaperLook::head($this->company, $this->shows('logo'));
    }

    public function logo(float $heightMm = 14): string
    {
        $logo = $this->head()['logo'];

        return $logo ? '<img src="'.e($logo).'" style="height: '.$heightMm.'mm" alt="">' : '';
    }

    public function companyMeta(string $color = '#555'): string
    {
        $h = $this->head();

        return implode('', array_map(
            fn (string $line) => '<div style="font-size: 7.5pt; color: '.$color.'; line-height: 1.4">'.e($line).'</div>',
            array_values(array_filter([$h['address'], $h['contact'], $h['tax']])),
        ));
    }

    /** "কততম ছাপা" — শিরোনামের নিচে নিজের ঘরে ([[PrintableDocument::isDuplicateNotice()]]) */
    public function duplicateMark(): string
    {
        if ($this->notice === null || ! PrintableDocument::isDuplicateNotice($this->notice)) {
            return '';
        }

        return '<div data-duplicate style="margin-top: 1mm"><span style="background: #b42318; color: #fff; font-weight: bold; font-size: 8pt; padding: 0.6mm 2mm">'.e($this->notice).'</span></div>';
    }

    /** খসড়া-বাতিলের মতো বড় সতর্কবার্তা */
    public function loudNotice(): string
    {
        if ($this->notice === null || $this->notice === '' || PrintableDocument::isDuplicateNotice($this->notice)) {
            return '';
        }

        return '<div style="text-align: center; font-weight: bold; border: 0.5mm solid #b42318; color: #b42318; padding: 2mm; margin: 2mm 0; font-size: 11pt">'.e($this->notice).'</div>';
    }

    /**
     * খাতের সারি — `dc` = ডেবিট/ক্রেডিট দুই কলাম, `amount` = কেবল টাকার এক কলাম (ক্রেডিটের সারি বাদ)।
     *
     * @param  array{th?: string, td?: string, border?: string, zebra?: string, code?: bool}  $s
     */
    public function lines(string $mode = 'dc', array $s = []): string
    {
        $th = $s['th'] ?? 'background: #eef1f5;';
        $border = $s['border'] ?? '0.25mm solid #333';
        $cell = 'border: '.$border.'; padding: 1.6mm 2mm; font-size: 8.8pt; vertical-align: top;';
        $head = 'border: '.$border.'; padding: 1.6mm 2mm; font-size: 7.6pt; font-weight: bold; '.$th;

        $html = '<table style="width: 100%; border-collapse: collapse; margin: 2.5mm 0">'
            .'<tr><th style="'.$head.' text-align: left">'.e($this->t('account_head')).'</th>'
            .'<th style="'.$head.' text-align: left">'.e($this->t('particulars')).'</th>'
            .($mode === 'dc'
                ? '<th style="'.$head.' text-align: right; width: 28mm">'.e($this->t('debit')).'</th><th style="'.$head.' text-align: right; width: 28mm">'.e($this->t('credit')).'</th>'
                : '<th style="'.$head.' text-align: right; width: 32mm">'.e($this->t('amount')).'</th>')
            .'</tr>';

        foreach ((array) ($this->v['lines'] ?? []) as $i => $line) {
            $debit = (string) ($line['debit'] ?? '');
            $credit = (string) ($line['credit'] ?? '');

            if ($mode === 'amount' && $this->isZero($debit) && ! $this->isZero($credit) && ! $this->isReceipt()) {
                continue;
            }

            $bg = isset($s['zebra']) && $i % 2 === 1 ? ' background: '.$s['zebra'].';' : '';
            $html .= '<tr><td style="'.$cell.$bg.' font-weight: bold">'.e((string) ($line['account'] ?? '')).'</td>'
                .'<td style="'.$cell.$bg.' color: #444">'.e((string) ($line['narration'] ?? '')).'</td>';

            if ($mode === 'dc') {
                $html .= '<td style="'.$cell.$bg.' text-align: right; font-family: '.$this->numFont().'">'.e($this->blankZero($debit)).'</td>'
                    .'<td style="'.$cell.$bg.' text-align: right; font-family: '.$this->numFont().'">'.e($this->blankZero($credit)).'</td></tr>';
            } else {
                $one = $this->isZero($debit) ? $credit : $debit;
                $html .= '<td style="'.$cell.$bg.' text-align: right; font-family: '.$this->numFont().'">'.e($this->n($one)).'</td></tr>';
            }
        }

        if ($this->shows('totals')) {
            $tot = $cell.' font-weight: bold; background: #f5f5f5;';
            $html .= '<tr data-totals><td colspan="2" style="'.$tot.' text-align: right">'.e($this->t('total')).'</td>'
                .($mode === 'dc'
                    ? '<td style="'.$tot.' text-align: right; font-family: '.$this->numFont().'">'.e($this->n((string) $this->v['total_debit'])).'</td><td style="'.$tot.' text-align: right; font-family: '.$this->numFont().'">'.e($this->n((string) $this->v['total_credit'])).'</td>'
                    : '<td style="'.$tot.' text-align: right; font-family: '.$this->numFont().'">'.e($this->n((string) $this->v['total_debit'])).'</td>')
                .'</tr>';
        }

        return $html.'</table>';
    }

    /** বিবরণের বাক্স — "কথায়"-এর ওপরে বসে (মালিক, ১২ অক্টোবর ২০২৬) */
    public function narrationBox(string $border = '#ccc'): string
    {
        $text = (string) ($this->v['narration'] ?? '');

        if (! $this->shows('narration') || $text === '') {
            return '';
        }

        return '<div data-narration style="border: 0.25mm solid '.$border.'; padding: 2mm 3mm; margin: 2mm 0; font-size: 8.8pt">'
            .'<b>'.e($this->t('narration')).'</b><br>'.e($text).'</div>';
    }

    public function words(string $prefix = ''): string
    {
        $words = (string) ($this->v['amount_in_words'] ?? '');

        if (! $this->shows('words') || $words === '') {
            return '';
        }

        return '<div style="margin: 1.5mm 0; font-size: 8.8pt">'.e($prefix !== '' ? $prefix : $this->t('in_words')).': <b>'.e($words).'</b></div>';
    }

    /** A4-এ কয় কপি — কন্ট্রোল প্যানেলের `accounts.print.voucher_copies` (১ বা ২; ডিফল্ট ২) */
    public function copies(): int
    {
        return (string) ($this->settings?->get(self::COPIES, '2') ?? '2') === '1' ? 1 : 2;
    }

    public function signs(): bool
    {
        return $this->shows('signatures') && ($this->settings === null || (bool) $this->settings->get('accounts.print_signature_lines', true));
    }

    /**
     * সইয়ের ঘর — `line` = দাগের ওপর নাম, `box` = সিলের ঘর।
     *
     * @param  list<string>|null  $roles  না দিলে কন্ট্রোলারের ([[VoucherPrintController::signatures()]])
     */
    public function signatures(string $style = 'line', ?array $roles = null, float $gapMm = 14, string $ink = '#111'): string
    {
        if (! $this->signs()) {
            return '';
        }

        $roles = $roles ?? $this->signatures;
        $width = (int) round(100 / max(count($roles), 1));
        $html = '<table data-signatures style="width: 100%; margin-top: '.$gapMm.'mm; border-collapse: collapse"><tr>';

        foreach ($roles as $role) {
            $html .= $style === 'box'
                ? '<td style="width: '.$width.'%; border: 0.3mm solid '.$ink.'; height: 22mm; vertical-align: bottom; text-align: center; font-size: 8pt; padding: 1.5mm">'.e($role).'</td>'
                : '<td style="width: '.$width.'%; padding: 0 3mm; text-align: center; font-size: 8.3pt"><div style="border-top: 0.3mm solid '.$ink.'; padding-top: 1mm">'.e($role).'</div></td>';
        }

        return $html.'</tr></table>';
    }

    /**
     * দুই কলামের ঘর — বাঁয়ে-ডানে, নিচে ড্যাশ দাগ (মালিকের দেওয়া রসিদের ধাঁচ)।
     *
     * @param  list<array{0: string, 1: string}>  $left
     * @param  list<array{0: string, 1: string}>  $right
     */
    public function dottedPairs(array $left, array $right, float $labelMm = 29, string $font = '9.4pt'): string
    {
        $row = fn (?array $pair) => $pair === null
            ? '<td></td><td></td>'
            : '<td style="font-weight: bold; font-size: '.$font.'; padding: 2.4mm 2mm 0.5mm 0; width: '.$labelMm.'mm; vertical-align: bottom">'.e($pair[0]).'</td>'
                .'<td style="font-size: '.$font.'; padding: 2.4mm 1mm 0.5mm; border-bottom: 0.25mm dashed #333; vertical-align: bottom">'.$pair[1].'</td>';

        $html = '<table style="width: 100%; border-collapse: collapse">';

        for ($i = 0, $n = max(count($left), count($right)); $i < $n; $i++) {
            $html .= '<tr>'.$row($left[$i] ?? null).'<td style="width: 8mm"></td>'.$row($right[$i] ?? null).'</tr>';
        }

        return $html.'</table>';
    }

    /**
     * এক কলামের পুরো-চওড়া ঘর (বিবরণ, বিল সমন্বয়, কথায়)।
     *
     * @param  list<array{0: string, 1: string}>  $rows
     */
    public function dottedRows(array $rows, float $labelMm = 29, string $font = '9.4pt'): string
    {
        $html = '<table style="width: 100%; border-collapse: collapse">';

        foreach ($rows as [$label, $value]) {
            $html .= '<tr><td style="font-weight: bold; font-size: '.$font.'; padding: 2.4mm 2mm 0.5mm 0; width: '.$labelMm.'mm; vertical-align: bottom">'.e($label).'</td>'
                .'<td style="font-size: '.$font.'; padding: 2.4mm 1mm 0.5mm; border-bottom: 0.25mm dashed #333; vertical-align: bottom">'.$value.'</td></tr>';
        }

        return $html.'</table>';
    }

    /** ইতিমধ্যে নিরাপদ HTML-এ ঢোকানোর জন্য লেখা */
    public function e(string $text): string
    {
        return e($text);
    }

    private function isZero(string $amount): bool
    {
        $plain = str_replace([',', ' '], '', $amount);

        return $plain === '' || (is_numeric($plain) && bccomp($plain, '0', 4) === 0);
    }

    private function blankZero(string $amount): string
    {
        return $this->isZero($amount) ? '' : $this->n($amount);
    }
}
