<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentSignature;
use App\Modules\Documents\Services\DocumentParties;
use App\Modules\Documents\Services\DocumentSignatures;
use App\Modules\Documents\Services\DocumentWorkflow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ দলিলের অনুমোদনের সারি আর সই কেন্দ্র, ফোনের নিজের পর্দায় — মালিক, ১৭ অক্টোবর ২০২৬: *"direct webpage app e bujajayna"*।
 *
 * ⓘ সারি ওয়েবের পাতার একই প্রশ্নে ([[DocumentWorkflowController::queue()]], [[DocumentSignatureController::center()]]) — একই চাবি
 * `documents.view`, একই দেয়াল। ⛔ সই বা ফেরত এই দরজায় নয়: ফোনের অনুমোদন-দরজা (`/approvals/{id}/approve|reject`) একই ইঞ্জিনে
 * সই নেয়, আর দলিলের সই সেখান থেকেই খাতায় বসে — দুই পথ থাকলে একদিন একটায় সংস্করণের ছাপ বসত না।
 * ⓘ সারি চেনা যায় public_id-তে, সংখ্যার id নয় (চুক্তি §৩ক)।
 */
final class DocumentQueueApiController extends Controller implements HasMiddleware
{
    private const SIGN_TABS = ['to_sign', 'asked', 'signed'];

    public function __construct(private readonly ApprovalEngine $engine) {}

    public static function middleware(): array
    {
        return [new Middleware('can:documents.view')];
    }

    /** `GET /documents/approvals?mine=&page=` — আমার সইয়ের অপেক্ষায়, বা `mine=1` আমার পাঠানো */
    public function queue(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $mine = $request->boolean('mine');

        $rows = ($mine
            ? Approval::query()->pending()->where('requested_by', $user->getKey())
            : $this->engine->pendingQueryFor($user))
            ->where('approvals.module', DocumentWorkflow::MODULE)
            ->with(['requester'])
            ->orderBy('approvals.due_at')
            ->orderBy('approvals.id')
            ->paginate(50, ['*'], 'page', max(1, (int) $request->query('page', 1)));

        return $this->approvals($rows, ! $mine);
    }

    /** `GET /documents/signatures?tab=to_sign|asked|signed&page=` — সই কেন্দ্রের তিন ঘর */
    public function signatures(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $tab = in_array($request->query('tab'), self::SIGN_TABS, true) ? (string) $request->query('tab') : 'to_sign';
        $page = max(1, (int) $request->query('page', 1));

        if ($tab === 'signed') {
            // ⛔ ইতিহাস কেবল যে কাগজ আমি দেখতে পাই তার — তিন দেয়াল আর বিভাগ (ওয়েবের পাতার একই শর্ত)
            $rows = DocumentSignature::query()
                ->whereIn('document_id', Document::query()->inViewedBranch()->visibleTo($user)->select('dms_documents.id'))
                ->with(['document', 'version', 'signer'])
                ->orderByDesc('signed_at')
                ->orderByDesc('id')
                ->paginate(50, ['*'], 'page', $page);

            // ⭐ নম্বরের পাশে পক্ষ — আলাদা ঘর `party`, পাতার একবারে (মালিক, ১৯ অক্টোবর ২০২৬; [[DocumentParties]]); পুরনো অ্যাপ উপেক্ষা করে
            $parties = app(DocumentParties::class)->of(collect($rows->items())->pluck('document_id'));

            return response()->json([
                'tab' => $tab,
                'rows' => collect($rows->items())->map(fn (DocumentSignature $s) => [
                    'document_id' => $s->document?->public_id,
                    'document_no' => (string) ($s->document?->document_no ?? ''),
                    'party' => $parties[(int) $s->document_id] ?? null,
                    'name' => (string) ($s->document?->name ?? ''),
                    'signer' => (string) ($s->signer?->name ?? ''),
                    'signed_at' => $s->signed_at ? DateFormat::formatWithTime($s->signed_at) : '',
                    'version' => $s->version ? 'v'.$s->version->label() : '',
                    // ⓘ ছাপের প্রথম বারো অক্ষর — ওয়েবের পাতার মতো; পুরোটা কাগজের নিজের পাতায়
                    'hash' => substr((string) $s->file_hash, 0, 12),
                ])->values(),
                'count' => $rows->total(),
                'next_page' => $rows->hasMorePages() ? $rows->currentPage() + 1 : null,
            ]);
        }

        $rows = ($tab === 'asked'
            ? Approval::query()->pending()->where('requested_by', $user->getKey())
            : $this->engine->pendingQueryFor($user))
            ->where('approvals.module', DocumentWorkflow::MODULE)
            ->where('approvals.action', DocumentSignatures::ACTION)
            ->with('requester')
            ->orderBy('approvals.due_at')
            ->orderBy('approvals.id')
            ->paginate(50, ['*'], 'page', $page);

        return $this->approvals($rows, $tab === 'to_sign', $tab);
    }

    /** অপেক্ষমাণ অনুমোদনের সারি, কাগজের নাম আর নম্বরসহ — `$decides` হলে ফোন সই/ফেরতের বোতাম দেখায় */
    private function approvals(LengthAwarePaginator $rows, bool $decides, ?string $tab = null): JsonResponse
    {
        $documents = Document::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->whereIn('id', collect($rows->items())->pluck('approvable_id'))
            ->get()
            ->keyBy('id');

        // ⭐ নম্বরের পাশে পক্ষ — আলাদা ঘর `party`, পাতার একবারে (মালিক, ১৯ অক্টোবর ২০২৬; [[DocumentParties]])
        $parties = app(DocumentParties::class)->of($documents->keys());

        return response()->json([
            'tab' => $tab,
            'rows' => collect($rows->items())->map(function (Approval $a) use ($documents, $decides, $parties) {
                $paper = $documents[(int) $a->approvable_id] ?? null;

                return [
                    // ⛔ ক্রমিক id কখনো নয় — চুক্তি §৩ক; সই/ফেরত এই id-তেই ফোনের অনুমোদন-দরজায়
                    'id' => (string) $a->public_id,
                    'document_id' => $paper?->public_id,
                    'document_no' => (string) ($paper?->document_no ?? ''),
                    'party' => $parties[(int) $a->approvable_id] ?? null,
                    'name' => (string) ($paper?->name ?? $a->requested_reason ?? ''),
                    'action' => (string) $a->action,
                    'version' => (string) (($a->payload['version'] ?? null) ?? ''),
                    'requester' => $a->requesterName(),
                    'due_at' => $a->due_at ? DateFormat::formatWithTime($a->due_at) : '',
                    'overdue' => (bool) ($a->due_at && $a->due_at->isPast()),
                    'can_decide' => $decides,
                ];
            })->values(),
            'count' => $rows->total(),
            'next_page' => $rows->hasMorePages() ? $rows->currentPage() + 1 : null,
        ]);
    }
}
