<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\User;
use App\Modules\Documents\Http\Requests\DocumentDetailsRequest;
use App\Modules\Documents\Http\Requests\DocumentUploadRequest;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentChoices;
use App\Modules\Documents\Services\DocumentFiles;
use App\Modules\Documents\Services\DocumentFinder;
use App\Modules\Documents\Services\DocumentGrants;
use App\Modules\Documents\Services\DocumentLibrary;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ডকুমেন্ট সেন্টার, আপলোড, বিস্তারিত — DOC-এর প্রথম ধাপের পর্দা (§৪, §৫, §৬; ৮ অক্টোবর ২০২৬)।
 *
 * ⓘ পাতলা: অনুমতি রুটের `can:`-এ ([[web.php]]), কাজ সেবায় ([[DocumentLibrary]],
 * [[DocumentFinder]])। ⛔ ঠিকানার কাগজ তিন দেয়াল পেরিয়েই আসে
 * ([[Document::resolveRouteBinding()]]) — এখানে আর আলাদা করে খোঁজা নয়।
 */
final class DocumentController extends Controller
{
    use SortsLists;

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DocumentFinder $finder,
        private readonly DocumentLibrary $library,
        private readonly DocumentChoices $choices,
        private readonly DocumentGrants $grants,
    ) {}

    /** ডকুমেন্ট সেন্টার — সব কাগজ, ফোল্ডার ধরে (§৪) */
    public function index(Request $request): View
    {
        return $this->list($request, DocumentFinder::CENTER);
    }

    /** আমার ডকুমেন্ট — আমি মালিক বা আমি তুলেছি */
    public function mine(Request $request): View
    {
        return $this->list($request, DocumentFinder::MINE);
    }

    /** সাম্প্রতিক — আমি যেগুলো খুলেছি বা বদলেছি, শেষেরটা আগে */
    public function recent(Request $request): View
    {
        return $this->list($request, DocumentFinder::RECENT);
    }

    /** আর্কাইভ — সেন্টার থেকে সরানো কাগজ, ফেরানোর জন্য (§১০, §২০) */
    public function archived(Request $request): View
    {
        return $this->list($request, DocumentFinder::ARCHIVE);
    }

    /** রিসাইকেল বিন — মোছা কাগজ, কে কবে মুছলেন; ফেরানো আর চিরতরে মোছা ([[DocumentBinController]]) */
    public function bin(Request $request): View
    {
        return $this->list($request, DocumentFinder::BIN);
    }

    /** বিস্তারিত খোঁজ — সব ছাঁকনি একসাথে, আর্কাইভসহ (§১৬) */
    public function search(Request $request): View
    {
        return $this->list($request, DocumentFinder::SEARCH);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new Document([
            'confidentiality' => DocumentCatalog::INTERNAL,
            'folder' => $request->query('folder') ?: null,
            'owner_id' => $request->user()?->getKey(),
            'branch_id' => $this->choices->defaultBranch($this->user($request)),
        ]));
    }

    public function store(DocumentUploadRequest $request): RedirectResponse
    {
        $made = $this->library->upload(array_values((array) $request->file('files', [])), $request->validated());

        if (count($made) === 1) {
            return redirect()->route('documents.show', $made[0])
                ->with('saved', __('documents::message.uploaded_one', ['no' => $made[0]->document_no]));
        }

        return redirect()->route('documents.mine')
            ->with('saved', trans_choice('documents::message.uploaded_many', count($made), ['count' => count($made)]));
    }

    /** বিস্তারিত — প্রিভিউ, বিবরণ, ভার্সন, অডিট (§৫) */
    public function show(Request $request, Document $document): View
    {
        $this->library->viewed($document);

        $document->load(['owner', 'creator', 'archiver', 'department', 'branch', 'currentVersion.attachment',
            'metadata.field', 'grants']);

        $versions = $document->versions()
            ->with(['attachment', 'author', 'restoredFrom'])
            ->orderByDesc('major')
            ->orderByDesc('minor')
            ->get();

        /* ⓘ অডিট — এই কাগজের সর্বশেষ ১০০টা সারি; পুরো খাতা নিরীক্ষার পর্দায় */
        $trail = AuditTrail::query()
            ->forRecord(Document::class, (int) $document->getKey())
            ->with('user')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('documents::show', [
            'menu' => $this->menu->forUser($request->user()),
            'document' => $document,
            'versions' => $versions,
            'trail' => $trail,
            'inline' => $this->library->opensInline($document->currentVersion),
            'mime' => $document->currentVersion?->attachment?->mime_type,
            'people' => $request->user()?->can('grant', $document) ? $this->grants->people() : [],
            'roles' => $request->user()?->can('grant', $document) ? $this->grants->roles() : [],
        ]);
    }

    public function edit(Request $request, Document $document): View
    {
        return $this->form($request, $document);
    }

    public function update(DocumentDetailsRequest $request, Document $document): RedirectResponse
    {
        $this->library->update($document, $request->validated());

        return redirect()->route('documents.show', $document)->with('saved', __('documents::message.updated'));
    }

    public function archive(Request $request, Document $document): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        $this->library->archive($document, $reason);

        return redirect()->route('documents.show', $document)->with('saved', __('documents::message.archived'));
    }

    public function unarchive(Document $document): RedirectResponse
    {
        $this->library->unarchive($document);

        return redirect()->route('documents.show', $document)->with('saved', __('documents::message.unarchived'));
    }

    public function destroy(Document $document): RedirectResponse
    {
        $this->library->delete($document);

        return redirect()->route('documents.index')->with('saved', __('documents::message.deleted'));
    }

    private function list(Request $request, string $view): View
    {
        $user = $this->user($request);
        $filters = [
            'q' => $request->query('q'),
            'folder' => $request->query('folder'),
            'doc_type' => $request->query('doc_type'),
            'department_id' => $request->query('department_id'),
            'confidentiality' => $request->query('confidentiality'),
            'expiry' => $request->query('expiry'),
            'archived' => $request->boolean('archived'),
            'status' => $request->query('status'),
            'branch_id' => $request->query('branch_id'),
            'owner_id' => $request->query('owner_id'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'version' => $request->query('version'),
            'tag' => $request->query('tag'),
            'content' => $request->query('content'),
        ];

        $query = $this->finder->query($user, $view, $filters);

        /* ⓘ সাম্প্রতিকের ক্রম নিজেই ঠিক — শেষ ছোঁয়া আগে; বাকি দুইটায় বাছা ক্রম */
        $sort = $view === DocumentFinder::RECENT ? 'recent' : $this->applySort($query, $request, $this->sorts());

        return view('documents::index', [
            'menu' => $this->menu->forUser($request->user()),
            'documents' => $query->paginate(50)->withQueryString(),
            'view' => $view,
            'filters' => $filters,
            'folderCounts' => $view === DocumentFinder::CENTER
                ? $this->finder->folderCounts($user, $filters['archived'])
                : [],
            'folders' => $this->choices->folders(),
            'types' => $this->choices->types(),
            'levels' => array_combine(DocumentCatalog::LEVELS, array_map(
                fn ($l) => __('documents::catalog.level.'.$l), DocumentCatalog::LEVELS)),
            'departments' => $this->choices->departments(),
            'statuses' => array_combine(DocumentCatalog::STATUSES, array_map(
                fn ($s) => __('documents::catalog.status.'.$s), DocumentCatalog::STATUSES)),
            'branches' => $this->choices->branches($user),
            'owners' => $this->choices->owners(),
            'sortOptions' => $view === DocumentFinder::RECENT ? [] : $this->sortLabels(),
            'sort' => $sort,
            'heading' => __('documents::menu.'.$view),
        ]);
    }

    private function form(Request $request, Document $document): View
    {
        $user = $this->user($request);

        return view('documents::form', [
            'menu' => $this->menu->forUser($request->user()),
            'document' => $document,
            'folders' => $this->choices->folders(),
            'types' => $this->choices->types(),
            'levels' => $document->exists
                ? $this->choices->levels($user) + [$document->confidentiality => __('documents::catalog.level.'.$document->confidentiality)]
                : $this->choices->levels($user),
            'branches' => $this->choices->branches($user),
            'companyWide' => $this->choices->companyWideAllowed($user),
            'departments' => $this->choices->departments(),
            'owners' => $this->choices->owners(),
            'accept' => '.'.str_replace(',', ',.', DocumentFiles::extensions()),
            'maxFiles' => DocumentFiles::MAX_FILES,
            'maxMb' => DocumentFiles::maxMb(),
            'metaFields' => $this->choices->metadataFields(all: true),
            'metaValues' => $document->exists ? $document->metadata()->pluck('value', 'field_id')->all() : [],
            'tagChoices' => $this->choices->tags(),
        ]);
    }

    /** @return array<string, callable> */
    private function sorts(): array
    {
        return [
            'updated' => fn ($q) => $q->orderByDesc('dms_documents.updated_at')->orderByDesc('dms_documents.id'),
            'name' => fn ($q) => $q->orderBy('dms_documents.name'),
            'number' => fn ($q) => $q->orderBy('dms_documents.document_no'),
            'document_date' => fn ($q) => $q->orderByDesc('dms_documents.document_date')->orderByDesc('dms_documents.id'),
            'expiry' => fn ($q) => $q->orderByRaw('dms_documents.expiry_date is null')->orderBy('dms_documents.expiry_date'),
        ];
    }

    /** @return array<string, string> */
    private function sortLabels(): array
    {
        return [
            'updated' => __('documents::sort.updated'),
            'name' => __('documents::sort.name'),
            'number' => __('documents::sort.number'),
            'document_date' => __('documents::sort.document_date'),
            'expiry' => __('documents::sort.expiry'),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
