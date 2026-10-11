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
use App\Modules\Documents\Services\DocumentWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * অনুমোদনের ধারার দরজা — জমা, ফেরত নেওয়া, প্রকাশ (§১০; তৃতীয় ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ⓘ সই দেওয়া, "না" বা সংশোধনে ফেরত — ABOS-এর সইয়ের ইনবক্সে ([[ApprovalInboxController]]);
 * এখানে কেবল কাগজের মালিকের দিকের কাজ। চাবি রুটের `can:`-এ ([[DocumentPolicy]])।
 */
final class DocumentWorkflowController extends Controller
{
    public function __construct(
        private readonly DocumentWorkflow $workflow,
        private readonly ApprovalEngine $engine,
        private readonly MenuBuilder $menu,
    ) {}

    /**
     * অনুমোদনের সারি (§১০ Approval Queue) — ⭐ ABOS-এর সইয়ের ইনবক্স, কেবল ডকুমেন্টের অনুরোধ।
     *
     * ⓘ সারিগুলো ইনবক্সের নিজের প্রশ্ন থেকে ([[ApprovalEngine::pendingQueryFor()]]) — যা ইনবক্সে
     * আমার সামনে, ঠিক তা-ই এখানে; সই দেওয়া ইনবক্সের পাতাতেই। ⓘ "আমার পাঠানো" ট্যাবে যেগুলো আমি
     * জমা দিয়েছি আর এখনো ঝুলে আছে। ⛔ নতুন কোনো সারির খাতা নয় — দুই জায়গায় দুই সত্য হত।
     */
    public function queue(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $mine = $request->boolean('mine');

        $query = $mine
            ? Approval::query()->pending()->where('requested_by', $user->getKey())
            : $this->engine->pendingQueryFor($user);

        $rows = $query->where('approvals.module', DocumentWorkflow::MODULE)
            ->with(['requester'])
            ->orderBy('approvals.due_at')
            ->orderBy('approvals.id')
            ->paginate(50)
            ->withQueryString();

        $documents = Document::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->whereIn('id', collect($rows->items())->pluck('approvable_id'))
            ->get()
            ->keyBy('id');

        return view('documents::approval-queue', [
            'menu' => $this->menu->forUser($user),
            'rows' => $rows,
            'documents' => $documents,
            'mine' => $mine,
        ]);
    }

    public function submit(Request $request, Document $document): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        $fresh = $this->workflow->submit($document, $note);

        return redirect()->to(route('documents.show', $document).'#approval')
            ->with('saved', __('documents::message.submitted_'.$fresh->status));
    }

    public function withdraw(Request $request, Document $document): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $this->workflow->withdraw($document, $user);

        return redirect()->to(route('documents.show', $document).'#approval')
            ->with('saved', __('documents::message.withdrawn'));
    }

    public function publish(Document $document): RedirectResponse
    {
        $this->workflow->publish($document);

        return redirect()->route('documents.show', $document)->with('saved', __('documents::message.published'));
    }
}
