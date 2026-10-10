<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentLibrary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * রিসাইকেল বিনের দুই কাজ — ফেরানো আর চিরতরে মোছা (§১৯; দ্বিতীয় ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ⓘ তালিকাটা সেন্টারের ছাঁচেই ([[DocumentController::bin()]])।
 *
 * ⛔ মোছা কাগজ সাধারণ ঠিকানায় খোলে না (রুটের কাগজ মোছা সারি চেনে না) — তাই এখানে নিজে
 * খোঁজা, কিন্তু একই তিন দেয়ালের ভিতরে: কোম্পানি আর শাখা (গ্লোবাল স্কোপ), হেডারের শাখা, আর
 * দেখার নিয়ম ([[Document::scopeVisibleTo()]])। তারপর পলিসি (দ্বিতীয় তালা)।
 */
final class DocumentBinController extends Controller
{
    public function __construct(private readonly DocumentLibrary $library) {}

    public function restore(Request $request, int $document): RedirectResponse
    {
        $found = $this->trashed($request, $document);
        $this->authorize('restore', $found);

        $this->library->restoreFromBin($found);

        return redirect()->route('documents.show', $found)->with('saved', __('documents::message.restored_from_bin'));
    }

    public function purge(Request $request, int $document): RedirectResponse
    {
        $found = $this->trashed($request, $document);
        $this->authorize('forceDelete', $found);

        $this->library->purge($found);

        return redirect()->route('documents.bin')->with('saved', __('documents::message.purged'));
    }

    private function trashed(Request $request, int $id): Document
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Document::query()
            ->onlyTrashed()
            ->inViewedBranch()
            ->visibleTo($user)
            ->whereKey($id)
            ->firstOrFail();
    }
}
