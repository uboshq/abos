<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Documents\Http\Requests\DocumentScanRequest;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Services\DocumentChoices;
use App\Modules\Documents\Services\DocumentFieldExtractor;
use App\Modules\Documents\Services\DocumentScan;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * স্ক্যান ও OCR-এর দরজা (§৭; পঞ্চম ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ⓘ লেখা পড়া হয় ব্রাউজারে ([[resources/js/document-scan.js]]); এখানে কেবল পাতা আর ফল জমা নেওয়া, আর লেখা
 * থেকে তথ্যের প্রস্তাব ([[DocumentFieldExtractor]]) — দুইটাই আমাদের নিজের সার্ভারে।
 */
final class DocumentScanController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DocumentChoices $choices,
        private readonly DocumentScan $scan,
        private readonly DocumentFieldExtractor $extractor,
    ) {}

    public function create(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return view('documents::scan.create', [
            'menu' => $this->menu->forUser($user),
            'enabled' => $this->scan->enabled(),
            'language' => $this->scan->language(),
            'folders' => $this->choices->folders(),
            'types' => $this->choices->types(),
            'levels' => $this->choices->levels($user),
            'branches' => $this->choices->branches($user),
            'companyWide' => $this->choices->companyWideAllowed($user),
            'defaultBranch' => $this->choices->defaultBranch($user),
            'maxPages' => DocumentScan::MAX_PAGES,
            'fields' => DocumentFieldExtractor::FIELDS,
            'internal' => DocumentCatalog::INTERNAL,
        ]);
    }

    public function store(DocumentScanRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $document = $this->scan->store(
            array_values((array) $request->file('pages', [])),
            $data,
            [
                'text' => $data['ocr_text'] ?? null,
                'fields' => $data['ocr_fields'] ?? [],
                'confidence' => $data['ocr_confidence'] ?? null,
                'language' => $data['ocr_language'] ?? null,
            ],
        );

        return redirect()->route('documents.show', $document)
            ->with('saved', __('documents::message.scanned', ['no' => $document->document_no]));
    }

    /** লেখা থেকে তথ্যের প্রস্তাব — বিল নম্বর, তারিখ, পক্ষ, অঙ্ক; মানুষ দেখে ঠিক করেন */
    public function fields(Request $request): JsonResponse
    {
        $text = (string) $request->validate(['text' => ['required', 'string', 'max:200000']])['text'];

        return response()->json($this->extractor->extract($text));
    }

    /** আগে তোলা ছবির ভার্সনে পড়া লেখা রাখা — ⓘ রুটের scopeBindings: ভার্সনটা এই কাগজেরই */
    public function saveText(Request $request, Document $document, DocumentVersion $version): RedirectResponse
    {
        $data = $request->validate([
            'ocr_text' => ['required', 'string', 'max:200000'],
            'ocr_confidence' => ['nullable', 'numeric', 'between:0,100'],
            'ocr_language' => ['nullable', 'in:'.implode(',', DocumentScan::LANGUAGES)],
            'ocr_fields' => ['nullable', 'array'],
            'ocr_fields.*' => ['nullable', 'string', 'max:120'],
        ]);

        $this->scan->saveText($document, $version, [
            'text' => $data['ocr_text'],
            'fields' => $data['ocr_fields'] ?? [],
            'confidence' => $data['ocr_confidence'] ?? null,
            'language' => $data['ocr_language'] ?? null,
        ]);

        return redirect()->to(route('documents.show', $document).'#ocr')->with('saved', __('documents::message.ocr_saved'));
    }
}
