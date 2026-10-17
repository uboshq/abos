<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\PhoneInput;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentChoices;
use App\Modules\Documents\Services\DocumentFinder;
use App\Modules\Documents\Services\DocumentLibrary;
use App\Modules\Documents\Services\DocumentSignatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ⭐ ফোনে ডকুমেন্টস — যে কাগজ দেখার অধিকার আছে তার তালিকা, একটা কাগজ, তার ফাইল, আর সইয়ের অবস্থা (মালিকের আদেশ,
 * ১৩ অক্টোবর ২০২৬: বাকি মডিউল অ্যাপে)।
 *
 * `GET /api/v1/documents/library?view=center|mine|shared|expiry&q=&page=` · `GET …/library/{public_id}` · `GET …/library/{public_id}/file?how=preview|download`
 *
 * ⓘ সব ওয়েবের নিজের পথে: তালিকা [[DocumentFinder::query()]] (দেখার শাখা, গোপনীয়তার ধাপ, বিভাগ, মালিকানা, অধিকার — তিন
 * দেয়াল), একটা কাগজ [[Document::resolveRouteBinding()]] (দেয়ালের বাইরে কেবল অপেক্ষার সইকারী) আর নীতি `view`, ফাইল
 * [[DocumentLibrary::stream()]] (পাতার ভিতরে কেবল PDF আর ছবি; বাকিটা নামানো, তাই নামানোর অধিকার চায়; প্রতিটা খোলা অডিটে)।
 * ⛔ সাধারণ সংযুক্তির দরজা (`/attachments/{id}`) এ কাগজ চেনে না, ইচ্ছা করে — গোপনীয়তা আর অডিট পাশ কাটানো যেত।
 *
 * ⭐ সই — নতুন দরজা নয়: কাগজের সই "ডকুমেন্টের সই" অনুমোদনের একটা ধাপ ([[DocumentSignatures]]), আর ফোনের অনুমোদনের দরজাই
 * (`/api/v1/approvals/{id}/approve|reject`) ওয়েবের একই [[ApprovalEngine]] ডাকে; শেষ সইয়ে লিসেনার সইয়ের সারি লেখে। এখানে কেবল
 * বলা হয় অবস্থা কী, আর এই মানুষটা এখন সই দিতে পারেন কি না (তখন অনুমোদনের public_id)।
 */
final class DocumentLibraryApiController extends Controller implements HasMiddleware
{
    private const PER_PAGE = 30;

    /** ফোনের তালিকার দৃশ্য — ওয়েবের সেন্টারের চারটা; বিন, আর্কাইভ আর বিস্তারিত খোঁজ ওয়েবে */
    /**
     * ⭐ ওয়েবের আটটা তালিকা-ঘরই — সাম্প্রতিক, আর্কাইভ, রিসাইকেল বিন আর বিস্তারিত খোঁজও (মালিক, ১৭ অক্টোবর ২০২৬: "direct webpage app e
     * bujajayna")। ⓘ প্রতিটার দেয়াল আর শর্ত ওয়েবের পাতার একই [[DocumentFinder::query()]]-এ।
     */
    private const VIEWS = [DocumentFinder::CENTER, DocumentFinder::MINE, DocumentFinder::SHARED, DocumentFinder::EXPIRY,
        DocumentFinder::RECENT, DocumentFinder::ARCHIVE, DocumentFinder::BIN, DocumentFinder::SEARCH];

    public function __construct(
        private readonly DocumentFinder $finder,
        private readonly DocumentLibrary $library,
        private readonly DocumentSignatures $signatures,
        private readonly DocumentChoices $choices,
        private readonly ApprovalEngine $approvals,
    ) {}

    public static function middleware(): array
    {
        // ⓘ ওয়েবের গোটা ডকুমেন্টস-গ্রুপের চাবি ([[Documents/Routes/web.php]]); কাগজ ধরে নীতি পদ্ধতিতে
        return [
            new Middleware('can:documents.view'),
            // ⭐ ফোন থেকে তোলা — ওয়েবের `/documents/upload`-এর একই চাবি (মালিক, ১৭ অক্টোবর ২০২৬)
            new Middleware('can:documents.upload', only: ['uploadSetup', 'upload']),
        ];
    }

    /**
     * `GET /documents/library/upload/setup` — তোলার ফর্মের পছন্দ, ওয়েবের পাতার একই তালিকা: ফোল্ডার, ধরন, এই মানুষের গোপনীয়তার ধাপ,
     * নাগালের শাখা (public_id), গোটা কোম্পানিতে রাখা যায় কি না, আর ফাইলের সীমা।
     */
    public function uploadSetup(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $default = $this->choices->defaultBranch($user);
        $branches = $this->choices->branches($user);
        $publicIds = \App\Models\Branch::query()->whereIn('id', array_keys($branches))->pluck('public_id', 'id');

        $pairs = fn (array $map) => collect($map)->map(fn ($label, $code) => ['code' => (string) $code, 'label' => (string) $label])->values();

        return response()->json([
            'folders' => $pairs($this->choices->folders()),
            'types' => $pairs($this->choices->types()),
            'levels' => $pairs($this->choices->levels($user)),
            'branches' => collect($branches)->map(fn ($label, $id) => [
                'id' => (string) ($publicIds[$id] ?? ''), 'label' => (string) $label, 'default' => (int) $id === (int) $default,
            ])->values(),
            'company_wide' => $this->choices->companyWideAllowed($user),
            'max_files' => \App\Modules\Documents\Services\DocumentFiles::MAX_FILES,
            'max_mb' => \App\Modules\Documents\Services\DocumentFiles::maxMb(),
        ]);
    }

    /**
     * `POST /documents/library` (multipart) — ওয়েবের তোলার একই নিয়ম আর সেবা ([[DocumentLibrary::upload()]]): ফাইলের ধরন ও মাপ,
     * নাগালের শাখা, নিজের বিভাগ, নিজের ধাপের গোপনীয়তা; প্রতিটা ফাইলে একটা কাগজ, ভার্সন আর অডিটসহ।
     */
    public function upload(\App\Modules\Documents\Http\Requests\PhoneDocumentUploadRequest $request): JsonResponse
    {
        $made = $this->library->upload(array_values((array) $request->file('files', [])), $request->validated());

        return response()->json([
            'message' => count($made) === 1
                ? __('documents::message.uploaded_one', ['no' => $made[0]->document_no])
                : trans_choice('documents::message.uploaded_many', count($made), ['count' => count($made)]),
            'rows' => collect($made)->map(fn (Document $d) => $this->facts($d))->values(),
        ], 201);
    }

    public function documents(Request $request): JsonResponse
    {
        $asked = PhoneInput::text($request, 'view', DocumentFinder::CENTER);
        $view = in_array($asked, self::VIEWS, true) ? $asked : DocumentFinder::CENTER;

        $query = $this->finder->query($this->user($request), $view, ['q' => PhoneInput::text($request, 'q')]);

        // ⓘ ওয়েবের ক্রম — মেয়াদের দৃশ্য নিজেই সাজানো; বাকিগুলো সর্বশেষ বদল আগে (ওয়েবের ডিফল্ট "updated")
        if ($view !== DocumentFinder::EXPIRY) {
            $query->orderByDesc('dms_documents.updated_at')->orderByDesc('dms_documents.id');
        }

        $page = $query->paginate(self::PER_PAGE);

        return response()->json([
            'view' => $view,
            'rows' => collect($page->items())->map(fn (Document $d) => $this->facts($d))->values(),
            // ⭐ গোটা ছাঁকনির গোনা, পাতার নয় — "সব পর্দায় মোট"
            'count' => $page->total(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $document = $this->open($id, 'view');

        // ⓘ ওয়েবের বিস্তারিত পাতার মতোই অডিটে "দেখা"
        $this->library->viewed($document);
        $document->load(['currentVersion.attachment', 'owner']);

        $mime = (string) ($document->currentVersion?->attachment?->mime_type ?? '');
        $approval = $this->approvals->latestFor($document, DocumentSignatures::ACTION);
        $pending = $approval !== null && $approval->status === Approval::PENDING;

        return response()->json($this->facts($document) + [
            'description' => (string) ($document->description ?? ''),
            'tags' => $document->tagList(),
            'owner' => (string) ($document->owner?->name ?? ''),
            'file' => $document->currentVersion === null ? null : [
                'name' => (string) ($document->currentVersion->attachment?->original_name ?? ''),
                'mime' => $mime,
                'size' => (int) ($document->currentVersion->attachment?->size_bytes ?? 0),
                'version' => $document->currentVersion->label(),
                // ⓘ পাতার ভিতরে দেখা যায় কি না — PDF আর ছবি; বাকিটা কেবল নামানো, নামানোর অধিকারে
                'inline' => $this->library->opensInline($document->currentVersion),
                'download' => $user->can('download', $document),
            ],
            'signature' => [
                'state' => $this->signatures->state($document),
                // ⓘ সই দিতে পারলে তবেই অনুমোদনের public_id — ফোন তখন অনুমোদনের দরজায় সই দেয়
                'approval_id' => $pending && $this->maySign($approval, $user) ? (string) $approval->public_id : null,
                'signed' => $document->signatures()->with('signer')->orderByDesc('signed_at')->orderByDesc('id')->limit(20)->get()
                    ->map(fn ($s) => [
                        'by' => (string) ($s->signer?->name ?? ''),
                        'at' => $s->signed_at?->toIso8601String(),
                        'current' => (int) $s->version_id === (int) $document->current_version_id,
                    ])->values(),
            ],
        ]);
    }

    /** চলতি ভার্সনের ফাইল — `how=preview` পাতার ভিতরে দেখা, `how=download` নামানো */
    public function file(Request $request, string $id): StreamedResponse
    {
        $how = PhoneInput::text($request, 'how', 'preview') === 'download' ? 'download' : 'preview';

        // ⓘ ওয়েবের দুই রুটের দুই নীতি — প্রিভিউ `view`, নামানো `download`
        $document = $this->open($id, $how === 'download' ? 'download' : 'view');
        $version = $document->currentVersion;
        abort_if($version === null, 404);

        return $this->library->stream($document, $version, $how);
    }

    /** @return array<string, mixed> */
    private function facts(Document $d): array
    {
        return [
            'id' => (string) $d->public_id,
            'no' => (string) $d->document_no,
            'name' => (string) $d->name,
            'folder' => (string) $d->folder,
            'folder_name' => $this->choices->folderName($d->folder),
            'type' => (string) $d->doc_type,
            'type_name' => $this->choices->typeName($d->doc_type),
            'level' => (string) $d->confidentiality,
            'status' => (string) $d->status,
            'document_date' => $d->document_date?->toDateString(),
            'expiry_date' => $d->expiry_date?->toDateString(),
            'version' => $d->currentVersion?->label(),
            // ⓘ রিসাইকেল বিনের কাগজ — খোলা যায় না (দরজা ৪০৪), ফেরানো ওয়েবে `documents.restore`-এ
            'deleted' => $d->trashed(),
        ];
    }

    /**
     * একটা কাগজ — ওয়েবের ঠিকানার একই দেয়াল আর একই নীতি; না-দেখার আর না-থাকার কাগজ দুইটাই ৪০৪।
     */
    private function open(string $id, string $ability): Document
    {
        $document = (new Document)->resolveRouteBinding($id, 'public_id');
        abort_unless($document instanceof Document, 404);

        Gate::authorize($ability, $document);

        return $document;
    }

    /** অনুমোদনের দরজার একই দুই প্রশ্ন — সিদ্ধান্তের চাবি আর এই ধাপে এই মানুষ ([[ApprovalApiController]]) */
    private function maySign(Approval $approval, User $user): bool
    {
        return $user->can('approval.decide') && $this->approvals->canDecide($approval, $user);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
