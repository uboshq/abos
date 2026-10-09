<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentSignature;
use App\Modules\Documents\Services\DocumentSignatures;
use App\Modules\Documents\Services\DocumentWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * সই কেন্দ্র — আমার সইয়ের অপেক্ষায়, আমার চাওয়া, আর সইয়ের ইতিহাস; সই চাওয়া আর যাচাই
 * (§১১; চতুর্থ ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ⓘ সই দেওয়া, "না" আর "সংশোধনে ফেরত" — ABOS-এর সইয়ের ইনবক্সে; এখানে তার সারি আর ইতিহাস।
 */
final class DocumentSignatureController extends Controller
{
    /** @var list<string> */
    private const TABS = ['to_sign', 'asked', 'signed'];

    public function __construct(
        private readonly DocumentSignatures $signatures,
        private readonly ApprovalEngine $engine,
        private readonly MenuBuilder $menu,
    ) {}

    public function center(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'to_sign';

        if ($tab === 'signed') {
            // ⛔ ইতিহাস কেবল যে কাগজ আমি দেখতে পাই তার — তিন দেয়াল আর বিভাগ
            $rows = DocumentSignature::query()
                ->whereIn('document_id', Document::query()->inViewedBranch()->visibleTo($user)->select('dms_documents.id'))
                ->with(['document', 'version', 'signer'])
                ->orderByDesc('signed_at')
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString();
        } else {
            $rows = ($tab === 'asked'
                ? Approval::query()->pending()->where('requested_by', $user->getKey())
                : $this->engine->pendingQueryFor($user))
                ->where('approvals.module', DocumentWorkflow::MODULE)
                ->where('approvals.action', DocumentSignatures::ACTION)
                ->with('requester')
                ->orderBy('approvals.due_at')
                ->orderBy('approvals.id')
                ->paginate(50)
                ->withQueryString();
        }

        $documents = $tab === 'signed' ? collect() : Document::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->whereIn('id', collect($rows->items())->pluck('approvable_id'))
            ->get()
            ->keyBy('id');

        return view('documents::signatures', [
            'menu' => $this->menu->forUser($user),
            'tab' => $tab,
            'rows' => $rows,
            'documents' => $documents,
        ]);
    }

    public function request(Request $request, Document $document): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        $this->signatures->request($document, $note);

        return redirect()->to(route('documents.show', $document).'#signatures')
            ->with('saved', __('documents::message.signature_asked'));
    }

    /** যাচাই — সইয়ের হ্যাশ বনাম ভার্সনের হ্যাশ বনাম ডিস্কের ফাইল; ফল অডিটেও */
    public function verify(Document $document, DocumentSignature $signature): RedirectResponse
    {
        $result = $this->signatures->verify($signature);

        $word = match (true) {
            ! $result['version'] || ! $result['file'] => 'verify_broken',
            ! $result['current'] => 'verify_old_version',
            default => 'verify_ok',
        };

        return redirect()->to(route('documents.show', $document).'#signatures')
            ->with($word === 'verify_broken' ? 'warning' : 'saved', __('documents::message.'.$word));
    }
}
