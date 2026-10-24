<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Core\Services\BranchSettings;
use App\Core\Support\PaperLanguage;
use App\Models\Company;

/**
 * "Set Invoice Information"-এর একমাত্র পড়ার জায়গা — ক্লাসিক বিল এখান থেকেই জানে কী ছাপবে।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"Set Invoice Information ei name alada tab koro"* → *"Print control er vitotre korte paro"*।
 * সেটিংগুলো ঘোষিত Sales-এর `module.php`-এ (গ্রুপ `invoice_info`); লেখে ছাপার নিয়ন্ত্রণের
 * ভেতরের ভাগটা ([[InvoiceInfoController]]), পড়ে এই ক্লাস।
 *
 * ⛔ চাবির নাম ছাঁচে হাতে লেখা হয় না — একটা বানান ভুল মানে একটা সুইচ যেটা কিছুই করে না,
 * আর সেটা কোনো পরীক্ষায় লাল হয় না। তাই ছাঁচ কেবল এই ক্লাসের পদ্ধতি ডাকে, আর
 * [[SetInvoiceInformationChangesThePaperTest]] প্রতিটা সুইচ বন্ধ করে কাগজ বদলায় কিনা দেখে।
 */
final class InvoicePrintLook
{
    /** মাথার যে ঘরগুলো বদলানো যায় — প্রতিটা `sales.print.header.{name}` */
    public const HEADER = ['name', 'address', 'phone', 'email', 'website'];

    /** দেখানো/লুকানোর সুইচ — প্রতিটা `sales.print.show.{name}`, ডিফল্টে চালু */
    public const SHOWS = ['bin', 'invoice_type', 'duplicate', 'order_no', 'transport', 'free', 'total_qty',
        'grand_total_row', 'previous_due', 'amount_words', 'deposits', 'qr', 'product_code', 'lot'];

    /** চালানের নিজের সুইচ — [[challanShows()]] */
    public const CHALLAN_SHOWS = ['product_code', 'lot', 'free', 'total_qty', 'qr', 'transport', 'order_no', 'prices'];

    public const MAX_BOXES = 4;

    /** খালি রাখা ঘরের নাম — নমুনার বাংলা নাম, ঘরের ক্রমে; চতুর্থটার নেই */
    private const DEFAULT_LABELS = ['received_by', 'prepared_by', 'approved_by'];

    /**
     * ⭐ শাখা ধরে পড়া — মালিক, ৩০ সেপ্টেম্বর ২০২৬: আলাদা শাখায় আলাদা ব্যবসা, তাই বিলের তথ্যও আলাদা।
     * ⓘ ছাপার সময় কাগজের শাখার বদল ([[BranchSettings::during()]]); বদল না থাকলে কোম্পানির সেটিং।
     */
    public function __construct(private readonly BranchSettings $settings) {}

    /**
     * কাগজের মাথা — বদল থাকলে সেটা, নাহলে প্রোফাইলের মান (ইংরেজিতে, নমুনার মতো)।
     *
     * @return array{name: string, address: string, phone: string, email: string, website: string}
     */
    public function header(Company $company): array
    {
        $own = [
            'name' => (string) $company->name('en'),
            'address' => (string) ($company->address('en') ?? ''),
            'phone' => (string) ($company->phone ?? ''),
            'email' => (string) ($company->email ?? ''),
            'website' => (string) ($company->website ?? ''),
        ];

        $header = [];

        foreach (self::HEADER as $field) {
            $set = trim((string) $this->settings->get("sales.print.header.{$field}"));
            $header[$field] = $set !== '' ? $set : $own[$field];
        }

        return $header;
    }

    /**
     * ⭐ চালান, আদেশ আর রসিদের মাথা — বিলের তথ্যের পাতায় বসানো নাম, ঠিকানা, ফোন আর ই-মেইল (মালিক, ৩ অক্টোবর ২০২৬:
     * চালানে *"Adress nai"*)। ⓘ আগে এই কাগজগুলো কেবল কোম্পানির প্রোফাইল পড়ত, তাই বিলের পাতায় বসানো ঠিকানা কেবল
     * বিলে উঠত। কাঠামো [[PaperLook::head()]]-এর মতোই, যাতে নকশাগুলো কিছু না বদলেই পড়ে।
     *
     * @return array{name: string, address: string, contact: string, tax: string, logo: ?string}
     */
    public function paperHead(Company $company, bool $withLogo = true): array
    {
        $head = \App\Core\Engines\Print\PaperLook::head($company, $withLogo);
        $own = $this->header($company);

        return [
            ...$head,
            'name' => $own['name'],
            'address' => $own['address'],
            'contact' => implode(' · ', array_filter([$own['phone'], $own['email']])),
        ];
    }

    /** একটা দেখানো/লুকানোর সুইচ */
    public function shows(string $what): bool
    {
        return (bool) $this->settings->get("sales.print.show.{$what}");
    }

    /**
     * চালানে কী ছাপা হবে — শাখা ধরে (মালিক, ৩ অক্টোবর ২০২৬)। ⓘ চালানের আলাদা সারি, বিলের সুইচ থেকে স্বাধীন:
     * গুদামের কাগজে কোড চাই, বিলে নয় — এমনও হয়।
     */
    public function challanShows(string $what): bool
    {
        return (bool) $this->settings->get("sales.print.challan_show.{$what}");
    }

    /** কয়টা সইয়ের ঘর — ২ থেকে ৪, বাইরের মান সীমায় টেনে আনা */
    public function boxCount(): int
    {
        return max(2, min(self::MAX_BOXES, (int) $this->settings->get('sales.print.signature_count')));
    }

    /** খালি রাখা ঘরে যে নাম বসে — পাতায় placeholder হিসেবেও দেখানো হয় */
    public function defaultLabel(int $index): string
    {
        $key = self::DEFAULT_LABELS[$index] ?? null;

        return $key === null ? '' : (string) __('sales::print.classic.'.$key, [], 'bn');
    }

    /**
     * কাগজে যে সইয়ের ঘরগুলো ছাপা হবে — প্রথম `boxCount()`টা।
     *
     * ⛔ নামহীন ঘর বাদ: চতুর্থ ঘরের নিজের নাম নেই, আর নামহীন একটা দাগ "কে সই করবেন"
     * প্রশ্নটাই খুলে রাখে।
     *
     * @return list<string>
     */
    public function signatures(): array
    {
        $out = [];

        for ($i = 0; $i < $this->boxCount(); $i++) {
            $set = trim((string) $this->settings->get('sales.print.signature.'.($i + 1)));
            $label = $set !== '' ? $set : $this->defaultLabel($i);

            if ($label !== '') {
                $out[] = $label;
            }
        }

        return $out;
    }

    /** নিচের লাল বাক্য — খালি থাকলে ভাষার ফাইলেরটা */
    public function footnote(bool $thermal = false, string $language = PaperLanguage::BN): string
    {
        if ($language === PaperLanguage::EN) {
            return $this->englishFootnote($thermal);
        }

        /* ⭐ মালিক, ৩ অক্টোবর ২০২৬: A4/A5-এ তিন দফা, সরু রোলে এক লাইন — দুইটাই বিলের তথ্যের পাতায় বদলানো যায় */
        $set = trim((string) $this->settings->get($thermal ? 'sales.print.invoice_footnote_thermal' : 'sales.print.invoice_footnote'));
        $bengali = $set !== '' ? $set : (string) __(self::footnoteText($thermal), [], 'bn');

        // ⓘ দুই ভাষার কাগজে দুটোই, বাংলা আগে — ইংরেজি না থাকলে কেবল বাংলা
        if ($language === PaperLanguage::BOTH) {
            return implode("\n", array_filter([$bengali, $this->englishFootnote($thermal)], fn (string $t) => $t !== ''));
        }

        return $bengali;
    }

    /**
     * ⭐ ইংরেজি কাগজের নোট (ভা-১৬ ধাপ ২, ২৪ অক্টোবর ২০২৬; [[PaperLanguage]])।
     *
     * ⛔ বাংলা নোট কখনো ইংরেজি কাগজে নীরবে বসে না। ক্রম: ইংরেজি ঘরে লেখা থাকলে সেটা; নইলে বাংলা নোট যদি কোম্পানির নিজের লেখা হয়
     * (সাধারণ লেখা নয়), তাহলে কোনো নোট নয় — ভুল শর্ত ছাপার চেয়ে না ছাপা ভালো, আর বিলের তথ্যের পাতা "ইংরেজি নোট নেই" বলে
     * ([[englishFootnoteMissing()]]); নইলে সাধারণ ইংরেজি লেখা।
     */
    private function englishFootnote(bool $thermal): string
    {
        $set = trim((string) $this->settings->get(($thermal ? 'sales.print.invoice_footnote_thermal' : 'sales.print.invoice_footnote').'_en'));

        if ($set !== '') {
            return $set;
        }

        return $this->ownBengaliFootnote($thermal) ? '' : (string) __(self::footnoteText($thermal), [], 'en');
    }

    /**
     * ⭐ বাংলা নোট কোম্পানির নিজের, অথচ ইংরেজি নোট নেই বা কেবল সাধারণ লেখা — বিলের তথ্যের পাতার সতর্কবার্তার জন্য।
     */
    public function englishFootnoteMissing(bool $thermal = false): bool
    {
        if (! $this->ownBengaliFootnote($thermal)) {
            return false;
        }

        $english = self::lines((string) $this->settings->get(($thermal ? 'sales.print.invoice_footnote_thermal' : 'sales.print.invoice_footnote').'_en'));

        return $english === '' || $english === self::lines((string) __(self::footnoteText($thermal), [], 'en'));
    }

    /** বাংলা নোট বসানো আর সাধারণ লেখা থেকে আলাদা — ⓘ বিলের তথ্যের পাতা খালি ঘরে সাধারণ লেখা ভরে সেভ করে, তাই "বসানো" যথেষ্ট নয় */
    private function ownBengaliFootnote(bool $thermal): bool
    {
        $set = self::lines((string) $this->settings->get($thermal ? 'sales.print.invoice_footnote_thermal' : 'sales.print.invoice_footnote'));

        return $set !== '' && $set !== self::lines((string) __(self::footnoteText($thermal), [], 'bn'));
    }

    private static function footnoteText(bool $thermal): string
    {
        return $thermal ? 'sales::print.classic.footnote_thermal' : 'sales::print.classic.footnote';
    }

    /** লাইনের শেষ এক রকম, দুই পাশের ফাঁকা বাদ — ফর্মের CRLF আর ভাষার ফাইলের LF এক */
    private static function lines(string $text): string
    {
        return trim(str_replace("\r\n", "\n", $text));
    }
}
