<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Requests;

use App\Modules\Documents\Services\DocumentFieldExtractor;
use App\Modules\Documents\Services\DocumentFiles;
use App\Modules\Documents\Services\DocumentScan;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * স্ক্যান — পাতার ছবি, কাগজের বিবরণ, আর ব্রাউজারে পড়া লেখা ও তথ্য (§৭; পঞ্চম ধাপ)।
 *
 * ⛔ পাতা কেবল ছবি (JPEG, PNG, WebP) — বাইট পড়ে দেখা, নামের লেজ নয়। ⓘ ছবি তোলা বন্ধ থাকলেও স্ক্যান চলে,
 * কারণ পাতাগুলো ছবি হয়ে থাকে না — এক PDF হয়ে কাগজে বসে ([[DocumentScan]])।
 */
class DocumentScanRequest extends DocumentDetailsRequest
{
    private const PAGE_KINDS = ['image/jpeg', 'image/png', 'image/webp'];

    protected function nameIsRequired(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'pages' => ['required', 'array', 'min:1', 'max:'.DocumentScan::MAX_PAGES],
            'pages.*' => ['required', 'file', 'max:'.(DocumentFiles::maxMb() * 1024), 'extensions:jpg,jpeg,png,webp'],
            'comment' => ['nullable', 'string', 'max:500'],
            'ocr_text' => ['nullable', 'string', 'max:200000'],
            'ocr_confidence' => ['nullable', 'numeric', 'between:0,100'],
            'ocr_language' => ['nullable', Rule::in(DocumentScan::LANGUAGES)],
            'ocr_fields' => ['nullable', 'array'],
            'ocr_fields.*' => ['nullable', 'string', 'max:120'],
            'ocr_fields.date' => ['nullable', 'date'],
            'ocr_fields.amount' => ['nullable', 'numeric'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ((array) $this->file('pages', []) as $i => $page) {
                    if ($validator->errors()->has('pages.'.$i)) {
                        continue;
                    }

                    $path = $page->getRealPath();
                    $sniffed = $path === false ? '' : strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));

                    if (! in_array($sniffed, self::PAGE_KINDS, true)) {
                        $validator->errors()->add('pages.'.$i, __('documents::message.page_not_image', ['name' => $page->getClientOriginalName()]));
                    }
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'pages' => __('documents::field.pages'),
            'pages.*' => __('documents::field.page'),
            'ocr_text' => __('documents::field.ocr_text'),
            ...collect(DocumentFieldExtractor::FIELDS)->mapWithKeys(fn ($f) => ['ocr_fields.'.$f => __('documents::field.ocr_'.$f)])->all(),
        ];
    }
}
