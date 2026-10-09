<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\Actor;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentOcr;
use App\Modules\Documents\Models\DocumentVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * স্ক্যান আর OCR — পাতাগুলো একটা PDF, আর ব্রাউজারে পড়া লেখা ভার্সনের সাথে (পরিকল্পনা §৭; পঞ্চম ধাপ)।
 *
 * ── ⓘ পথটা ────────────────────────────────────────────────────────────
 * ক্যামেরা বা স্ক্যানারের পাতা (ছবি) → ব্রাউজারে tesseract.js লেখা পড়ে → মানুষ লেখা আর তথ্য দেখে ঠিক করেন
 * → জমা: পাতাগুলো এক PDF হয় (ABOS-এর [[PrintEngine]], mPDF), সেটা কাগজের v1.0 ([[DocumentLibrary::upload()]]),
 * আর লেখা ঐ ভার্সনের ([[DocumentOcr]])। ⛔ ছবি বা লেখা কোথাও বাইরে যায় না।
 *
 * ⓘ আগে তোলা ছবির কাগজেও পরে লেখা পড়া যায় — একই ব্রাউজারের যন্ত্র, [[saveText()]] দিয়ে।
 */
final class DocumentScan
{
    /** একবারে কয়টা পাতা */
    public const MAX_PAGES = 10;

    /** ⓘ যে যন্ত্রে পড়া — সংস্করণসহ, যাতে পরে জানা যায় কোন ফলের পিছনে কোন যন্ত্র */
    public const ENGINE = 'tesseract.js 7.0.0 (browser, wasm)';

    /** @var list<string> */
    public const LANGUAGES = ['ben+eng', 'ben', 'eng'];

    public function __construct(
        private readonly DocumentLibrary $library,
        private readonly PrintEngine $print,
        private readonly SettingsService $settings,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('documents.ocr_enabled', true);
    }

    public function language(): string
    {
        $language = (string) $this->settings->get('documents.ocr_languages', 'ben+eng');

        return in_array($language, self::LANGUAGES, true) ? $language : 'ben+eng';
    }

    /**
     * পাতাগুলো থেকে নতুন কাগজ — এক PDF, প্রথম ভার্সন, আর তার লেখা।
     *
     * @param  list<UploadedFile>  $pages
     * @param  array<string, mixed>  $details  তোলার ফর্মের ঘর
     * @param  array<string, mixed>  $ocr  text, fields, confidence, language
     */
    public function store(array $pages, array $details, array $ocr): Document
    {
        $pdf = $this->pdfOf($pages, (string) ($details['name'] ?? 'scan'));

        try {
            $made = $this->library->upload([$pdf], $details);
            $document = $made[0];

            if (filled($ocr['text'] ?? null)) {
                $this->saveText($document, $document->currentVersion()->firstOrFail(), $ocr, count($pages));
            }

            return $document;
        } finally {
            @unlink($pdf->getPathname());
        }
    }

    /**
     * একটা ভার্সনের পড়া লেখা রাখা — নতুন বা আগেরটার জায়গায়।
     *
     * ⓘ লেখা ফাইল নয় — ফাইল আর ভার্সন যেমন ছিল তেমনই থাকে ([[DocumentVersion]] বদলায় না)।
     *
     * @param  array<string, mixed>  $ocr
     */
    public function saveText(Document $document, DocumentVersion $version, array $ocr, int $pages = 1): DocumentOcr
    {
        return DB::transaction(function () use ($document, $version, $ocr, $pages) {
            $fields = array_intersect_key((array) ($ocr['fields'] ?? []), array_flip(DocumentFieldExtractor::FIELDS));
            $fields = array_map(fn ($v) => filled($v) ? mb_substr(trim((string) $v), 0, 120) : null, $fields);

            $row = DocumentOcr::query()->firstOrNew(['version_id' => $version->id]);
            $row->fill([
                'company_id' => $document->company_id,
                'document_id' => $document->id,
                'text' => mb_substr((string) $ocr['text'], 0, 200000),
                'fields' => $fields,
                'language' => in_array($ocr['language'] ?? null, self::LANGUAGES, true) ? $ocr['language'] : $this->language(),
                'engine' => self::ENGINE,
                'confidence' => is_numeric($ocr['confidence'] ?? null) ? max(0, min(100, (float) $ocr['confidence'])) : null,
                'pages' => max(1, $pages),
                'updated_by' => Actor::userId(),
            ]);

            if (! $row->exists) {
                $row->created_by = Actor::userId();
            }

            $row->save();

            $document->auditAction('document_ocr_saved', 'v'.$version->label());

            return $row;
        });
    }

    /**
     * পাতাগুলো এক PDF — ABOS-এর ছাপার যন্ত্র দিয়ে, প্রতি পাতায় একটা ছবি।
     *
     * @param  list<UploadedFile>  $pages
     */
    private function pdfOf(array $pages, string $name): UploadedFile
    {
        $images = array_map(fn (UploadedFile $page) => 'data:'.$page->getMimeType().';base64,'
            .base64_encode((string) file_get_contents($page->getRealPath())), $pages);

        $bytes = $this->print->render('documents::scan.pages', ['title' => $name, 'images' => $images], PaperSize::A4);

        $path = tempnam(sys_get_temp_dir(), 'dms-scan-');
        file_put_contents($path, $bytes);

        $file = preg_replace('/[^\pL\pN\-_ ]+/u', '', $name) ?: 'scan';

        return new UploadedFile($path, mb_substr($file, 0, 80).'.pdf', 'application/pdf', null, true);
    }
}
