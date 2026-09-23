<?php

declare(strict_types=1);

namespace App\Core\Engines\Print;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Illuminate\Support\Facades\View;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * ছাপার একমাত্র পথ — প্ল্যান সেকশন ২.২, পঞ্চম engine।
 *
 * ছয়টা ডকুমেন্ট × তিনটা কাগজ × দুই ভাষা = ৩৬ রকম। ছত্রিশটা টেমপ্লেট হাতে
 * লেখা মানে একটা ফিল্ড যোগ করতে ছত্রিশ জায়গায় হাত দেওয়া, আর তার একটাতে
 * ভুল হলে সেটা ধরা পড়বে যখন কোনো গ্রাহক ভুল রসিদ নিয়ে ফিরে আসবে।
 *
 * তাই টেমপ্লেট একটাই প্রতি ডকুমেন্টে। কাগজ বদলালে CSS বদলায়, ভাষা বদলালে
 * lang ফাইল — মার্কআপ নয়।
 *
 * mPDF, DomPDF নয়: Phase 0-তে প্রমাণ করা হয়েছে DomPDF বাংলা যুক্তাক্ষর
 * ভাঙে আর mPDF ভাঙে না।
 */
final class PrintEngine
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * একটা ডকুমেন্ট ছাপো।
     *
     * @param  array<string, mixed>  $data
     */
    public function render(
        string $template,
        array $data,
        string $paper = PaperSize::A4,
        ?string $locale = null,
        ?Company $company = null,
        ?string $watermark = null,

        /*
         * ⭐ কোন কাগজের সুইচগুলো মানা হবে — ২২ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ নাল মানে *"এই কাগজ এখনো সুইচের আওতায় আসেনি"*, আর তখন
         * [[PrintProfile::everything()]] — সব চালু, কোনো সেটিং পড়া হয়
         * না। ⛔ এখানে চুপচাপ `'invoice'` ধরে নিলে বিলের একটা সুইচ বন্ধ
         * করামাত্র ভাউচার ও বেতনশিটের কাগজও বদলে যেত।
         */
        ?string $profile = null,
    ): string {
        $size = PaperSize::of($paper);
        $locale = $locale ?? app()->getLocale();
        $company = $company ?? $this->currentCompany();

        $view = $this->resolveTemplate($template);

        // ভাষাটা সাময়িকভাবে বদলানো হয়, তারপর ফেরত — নাহলে একটা ইনভয়েস
        // বাংলায় ছাপার পর বাকি রিকোয়েস্টটাও বাংলা হয়ে যেত, এমনকি
        // ব্যবহারকারী ইংরেজিতে কাজ করলেও।
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $html = View::make($view, [
                ...$data,
                'company' => $company,
                'paper' => $size,
                'locale' => $locale,
                'settings' => $this->settings,
                'profile' => $profile === null
                    ? PrintProfile::everything()
                    : PrintProfile::for($profile, $this->settings),
            ])->render();
        } finally {
            app()->setLocale($previous);
        }

        return $this->toPdf($html, $size, $data['title'] ?? $template, $watermark);
    }

    /**
     * ⭐ নমুনা — PDF নয়, হুবহু সেই HTML.
     *
     * ── ⭐ মালিকের নির্দেশ, ২৩ সেপ্টেম্বর ২০২৬ ────────────
     * *"print e invoice template vew kore deke select korar bebosta koro.
     * zate age sample dekha zay tarpor select kora zay"*।
     *
     * ── ⚠️ কেন PDF নয় ─────────────────────────────────────
     * তেরোটা রূপের তেরোটা PDF বানানো মানে mPDF তেরোবার চালানো,
     * আর পড়ার জন্য প্রতিটার দরকার একটা করে PDF দেখার যন্ত্র। ⛔ ফোনে
     * একটা পাতায় তেরোটা PDF দর্শক খুললে পাতাটাই খুলত না।
     *
     * ℹ HTML-টা একই লেআউট, একই CSS, একই ব্লেড — অর্থাৎ যা
     * দেখা যায় তাই ছাপা হয়। ⚠️ নমুনার জন্য আলাদা একটা ছান্চ লিখলে
     * সেটা একদিন আসল কাগজ থেকে সরে যেত, আর মালিক একটা দেখে আরেকটা
     * পেতেন — যে ভুলটা ধরা পড়ত কেবল ছাপার পরে।
     *
     * @param  array<string, mixed>  $data
     */
    public function preview(
        string $template,
        array $data,
        string $paper,
        PrintProfile $profile,
        ?string $locale = null,
        ?Company $company = null,
    ): string {
        $size = PaperSize::of($paper);
        $locale = $locale ?? app()->getLocale();
        $company = $company ?? $this->currentCompany();

        $view = $this->resolveTemplate($template);

        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return View::make($view, [
                ...$data,
                'company' => $company,
                'paper' => $size,
                'locale' => $locale,
                'settings' => $this->settings,
                'profile' => $profile,
            ])->render();
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * ছাপার ভাষা ডকুমেন্টের সাথে সেভ করা থাকে (সেকশন ১৮.৫)।
     *
     * গ্রাহক বাংলায় ইনভয়েস পেলে পুনঃপ্রিন্টেও বাংলাই আসতে হবে — নাহলে
     * দুইটা কাগজ দেখতে দুই রকম, আর কোনটা আসল সেই প্রশ্ন ওঠে।
     */
    public function renderAsIssued(
        string $template,
        array $data,
        string $paper,
        ?string $issuedLocale,
    ): string {
        return $this->render($template, $data, $paper, $issuedLocale);
    }

    /** @return list<string> কোন কোন কাগজে এই ডকুমেন্ট ছাপা যায় */
    public function papersFor(string $template): array
    {
        // রিপোর্ট থার্মালে ছাপা অর্থহীন — ৫৮mm-এ ট্রায়াল ব্যালেন্স পড়া যায় না।
        return str_starts_with($template, 'report.')
            ? [PaperSize::A4]
            : PaperSize::all();
    }

    private function resolveTemplate(string $template): string
    {
        // মডিউল নিজের টেমপ্লেট নিজের ফোল্ডারে রাখে (সেকশন ১৯.১), তাই
        // "accounts::print.voucher" রূপে আসে। কোরের নিজের টেমপ্লেটও আছে।
        $candidates = [$template, 'print.'.$template];

        foreach ($candidates as $candidate) {
            if (View::exists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            "No print template found for '{$template}'. Looked for: ".implode(', ', $candidates).'.'
        );
    }

    /**
     * থার্মাল রসিদ ঠিক যতটুকু লাগে ততটুকু লম্বা।
     *
     * mPDF-কে পাতার উচ্চতা তৈরির সময়েই দিতে হয়, অথচ কতটুকু লাগবে সেটা
     * লেখার পরেই জানা যায়। তাই দুইবার: প্রথমবার লম্বা পাতায় লিখে মেপে
     * নেওয়া, দ্বিতীয়বার মাপমতো পাতায়।
     *
     * এটা না করলে দুই লাইনের রসিদও তিন মিটার কাগজ খেয়ে ফেলত — রোল
     * প্রিন্টার থামে না, সে যতটুকু পাতা তত ছাপে।
     */
    private function toThermalPdf(string $html, PaperSize $size, string $title): string
    {
        $measure = $this->newMpdf($size, $size->format[1]);
        $measure->WriteHTML($html);

        // মাপা উচ্চতা + নিচের মার্জিন + কাটার জন্য একটু বাড়তি
        $used = $measure->y + $size->margin + 4;

        return $this->write($this->newMpdf($size, max($used, 40)), $html, $title);
    }

    private function toPdf(string $html, PaperSize $size, string $title, ?string $watermark = null): string
    {
        if ($size->isThermal) {
            /*
             * থার্মালে জলছাপ নয়।
             *
             * রোল প্রিন্টার তাপ দিয়ে ছাপে -- ধূসর বলে কিছু নেই, সব
             * কালো। জলছাপ বসালে ওটা লেখার উপর দিয়েই যেত আর কাগজটা
             * পড়াই যেত না। উপরের বাক্সটা ওখানেও থাকে, আর ৫৮mm কাগজে
             * ওটাই যথেষ্ট -- এত ছোট কাগজে বাক্সটা এড়ানোর উপায় নেই।
             */
            return $this->toThermalPdf($html, $size, $title);
        }

        $mpdf = $this->newMpdf($size);

        if ($watermark !== null && $watermark !== '') {
            /*
             * বাতিল কাগজের গায়ে কোনাকুনি জলছাপ।
             *
             * ---- কেন উপরের বাক্সটা যথেষ্ট নয়, ৩০ আগস্ট ২০২৬ ----
             * বাতিল ভাউচারে উপরে একটা বাক্সে "বাতিল" লেখা ওঠে, আর
             * সেটা ১৪ আগস্ট HP-র ধরা একটা ভুলের সমাধান ছিল।
             *
             * কিন্তু উপরের একটা বাক্স কাগজের **একটা কোণে** থাকে: ভাঁজ
             * করলে ঢাকা পড়ে, ফটোকপিতে কেটে ফেলা যায়, আর স্ক্যান করে
             * উপরের অংশটুকু বাদ দিলে বাকিটা হুবহু বৈধ একটা কাগজ।
             *
             * জলছাপ লেখার **উপর দিয়ে** যায়, তাই কেটে বাদ দিলে
             * সংখ্যাগুলোও যায়। ওটাই আসল পার্থক্য।
             *
             * mPDF-এর নিজের ব্যবস্থা ব্যবহার করা হয়, CSS ঘুরিয়ে নয় --
             * ঘোরানো লেখা mPDF-এ পাতার প্রবাহ নষ্ট করে, আর জলছাপটা
             * তখন সারির মাঝখানে জায়গা দখল করত।
             */
            $mpdf->SetWatermarkText($watermark);
            $mpdf->showWatermarkText = true;

            /*
             * হালকা, কিন্তু অস্বীকার করার মতো নয়।
             *
             * mPDF-এর ডিফল্ট এত ফিকে যে ফটোকপিতে হারিয়ে যায়, আর
             * হারানো জলছাপ না-থাকার সমান।
             */
            $mpdf->watermarkTextAlpha = 0.12;
        }

        return $this->write($mpdf, $html, $title);
    }

    /**
     * @param  float|null  $height  থার্মালে মাপা উচ্চতা; A4-তে null, কারণ
     *                              সেখানে মাপটা কাগজের নিজের।
     */
    private function newMpdf(PaperSize $size, ?float $height = null): Mpdf
    {
        return new Mpdf([
            'mode' => 'utf-8',
            'format' => $height === null ? $size->format : [$size->format[0], $height],
            'margin_left' => $size->margin,
            'margin_right' => $size->margin,
            'margin_top' => $size->margin,
            'margin_bottom' => $size->margin,
            'default_font_size' => $size->fontSize,
            'default_font' => 'hindsiliguri',
            'tempDir' => storage_path('framework/cache'),
            'fontDir' => $this->fontDirs(),
            'fontdata' => $this->fontData(),
        ]);
    }

    private function write(Mpdf $mpdf, string $html, string $title): string
    {
        $mpdf->SetTitle($title);
        $mpdf->SetCreator('ABOS');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /** @return list<string> */
    private function fontDirs(): array
    {
        return [
            ...(new ConfigVariables)->getDefaults()['fontDir'],
            storage_path('fonts'),
        ];
    }

    /** @return array<string, mixed> */
    private function fontData(): array
    {
        return (new FontVariables)->getDefaults()['fontdata'] + [
            'hindsiliguri' => [
                'R' => 'HindSiliguri-Regular.ttf',
                'B' => 'HindSiliguri-Bold.ttf',
                // useOTL ছাড়া বাংলা যুক্তাক্ষর ভেঙে যায় — Phase 0-এর
                // পরীক্ষায় ঠিক এটাই যাচাই করা হয়েছিল।
                'useOTL' => 0xFF,
                'useKashida' => 75,
            ],
        ];
    }

    private function currentCompany(): Company
    {
        $id = CompanyContext::id();

        if ($id === null) {
            throw new RuntimeException('Cannot print without a company in context.');
        }

        return Company::query()->findOrFail($id);
    }
}
