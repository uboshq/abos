<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Core\Services\SettingsService;
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
        'grand_total_row', 'previous_due', 'amount_words', 'deposits', 'qr'];

    public const MAX_BOXES = 4;

    /** খালি রাখা ঘরের নাম — নমুনার বাংলা নাম, ঘরের ক্রমে; চতুর্থটার নেই */
    private const DEFAULT_LABELS = ['received_by', 'prepared_by', 'approved_by'];

    public function __construct(private readonly SettingsService $settings) {}

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

    /** একটা দেখানো/লুকানোর সুইচ */
    public function shows(string $what): bool
    {
        return (bool) $this->settings->get("sales.print.show.{$what}");
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
    public function footnote(): string
    {
        $set = trim((string) $this->settings->get('sales.print.invoice_footnote'));

        return $set !== '' ? $set : (string) __('sales::print.classic.footnote', [], 'bn');
    }
}
