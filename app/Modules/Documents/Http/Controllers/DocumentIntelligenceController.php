<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Services\DocumentChoices;
use App\Modules\Documents\Services\DocumentIntelligence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Document Intelligence (ABE)-এর পর্দা — একটা কাগজ বেছে ছয়টা কাজ (§৮; ষষ্ঠ ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ⛔ কাগজ আসে তিন দেয়াল আর বিভাগ পেরিয়েই ([[Document::scopeVisibleTo()]]) — যা দেখতে পান না, তার উপর কোনো
 * কাজ নয়। ⓘ প্রতিটা ব্যবহার অডিটে, কোন কাজ সহ — ঠিক "দেখা"-র মতোই।
 */
final class DocumentIntelligenceController extends Controller
{
    /** @var list<string> */
    public const TOOLS = ['classify', 'extract', 'summarize', 'compare', 'ask', 'translate'];

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DocumentIntelligence $abe,
        private readonly DocumentChoices $choices,
        private readonly SettingsService $settings,
    ) {}

    /** ⓘ একটা কাজের পাতা — কাগজ বাছার ছোট তালিকা পাতা-ভাগসহ, তারপর ছয়টা কাজ */
    public function workbench(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $enabled = (bool) $this->settings->get('documents.abe_enabled', true);
        $document = null;

        if ($request->filled('document')) {
            $document = Document::query()->inViewedBranch()->visibleTo($user)
                ->with(['currentVersion'])
                ->whereKey((int) $request->query('document'))
                ->firstOrFail();
        }

        $tool = in_array($request->query('tool'), self::TOOLS, true) ? (string) $request->query('tool') : 'classify';
        $result = null;
        $text = null;
        $versions = collect();

        if ($document !== null && $enabled) {
            $text = $this->abe->textOf($document->currentVersion);
            $versions = $document->versions()->with('author')->orderByDesc('major')->orderByDesc('minor')->get();
            $result = $this->run($tool, $document, $text, $versions, $request);
            $document->auditAction('document_abe_used', $tool);
        }

        $q = trim((string) $request->query('q', ''));

        return view('documents::intelligence', [
            'menu' => $this->menu->forUser($user),
            'enabled' => $enabled,
            'document' => $document,
            'tool' => $tool,
            'tools' => self::TOOLS,
            'text' => $text,
            'result' => $result,
            'versions' => $versions,
            'choices' => $this->choices,
            'q' => $q,
            'picks' => $document === null
                ? Document::query()->inViewedBranch()->visibleTo($user)->notArchived()
                    ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('dms_documents.name', 'like', '%'.addcslashes($q, '\\%_').'%')
                        ->orWhere('dms_documents.document_no', 'like', '%'.addcslashes($q, '\\%_').'%')))
                    ->orderByDesc('dms_documents.updated_at')
                    ->paginate(20)
                    ->withQueryString()
                : null,
        ]);
    }

    /** শ্রেণির প্রস্তাব কাগজে বসানো — বিবরণ বদলের নিয়মেই ([[DocumentPolicy::update()]]) */
    public function apply(Request $request, Document $document): RedirectResponse
    {
        $data = $request->validate([
            'doc_type' => ['required', Rule::in(array_keys($this->choices->types()))],
            'folder' => ['nullable', Rule::in(array_keys($this->choices->folders()))],
        ]);

        $document->forceFill([
            'doc_type' => $data['doc_type'],
            'folder' => $data['folder'] ?? $document->folder,
        ])->save();

        $document->auditAction('document_abe_used', 'classify → '.$data['doc_type']);

        return redirect()->route('documents.intelligence', ['document' => $document->id, 'tool' => 'classify'])
            ->with('saved', __('documents::message.abe_applied'));
    }

    /**
     * @param  Collection<int, DocumentVersion>  $versions
     */
    private function run(string $tool, Document $document, ?string $text, $versions, Request $request): mixed
    {
        if ($tool === 'compare') {
            $old = $versions->firstWhere('id', (int) $request->query('from')) ?? $versions->get(1);
            $new = $versions->firstWhere('id', (int) $request->query('to')) ?? $versions->first();

            return ($old !== null && $new !== null && $old->id !== $new->id) ? $this->abe->compare($old, $new) : null;
        }

        if ($text === null) {
            return null;
        }

        return match ($tool) {
            'classify' => $this->abe->classify($text),
            'extract' => $this->abe->extract($text, $document->doc_type),
            'summarize' => $this->abe->summarize($text, max(1, min(15, (int) $this->settings->get('documents.abe_summary_lines', 5)))),
            'ask' => $this->abe->ask($text, (string) $request->query('ask', '')),
            default => $this->abe->glossaryFor($text),
        };
    }
}
