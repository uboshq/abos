<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Services\DocumentLibrary;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ফাইলের দরজা — প্রিভিউ, নামানো, ছাপা, আর পুরনো ভার্সন নামানো (§৫, §৯; ৮ অক্টোবর ২০২৬)।
 *
 * ── ⛔ ফাইল কেবল এই দরজা দিয়ে ─────────────────────────────────────────
 * ফাইল থাকে সার্ভারের ভিতরের ডিস্কে (`storage/app/private`), `public/`-এ কখনো নয় —
 * ঠিকানা জানলেই কেউ খুলতে পারতেন না। ⓘ প্রতিটা খোলার আগে তিন দেয়াল (কোম্পানি, শাখা,
 * গোপনীয়তা — [[Document::resolveRouteBinding()]]) আর কাজের চাবি (রুটের `can:`),
 * আর পরে অডিটে একটা নামসহ সারি ([[DocumentLibrary::stream()]])।
 */
final class DocumentFileController extends Controller
{
    public function __construct(private readonly DocumentLibrary $library) {}

    /** চলতি ভার্সন, পাতার ভিতরে — ছবি আর PDF; বাকি সব নামানো হিসেবে */
    public function preview(Document $document): StreamedResponse
    {
        return $this->library->stream($document, $this->current($document), 'preview');
    }

    public function download(Document $document): StreamedResponse
    {
        return $this->library->stream($document, $this->current($document), 'download');
    }

    /** ছাপা — ফাইলটা নিজের ট্যাবে খোলে, ব্রাউজারের ছাপা দিয়ে; অডিটে "ছাপা" */
    public function print(Document $document): StreamedResponse
    {
        return $this->library->stream($document, $this->current($document), 'print');
    }

    /** পুরনো ভার্সন নামানো — ⓘ রুটের scopeBindings: ভার্সনটা এই কাগজেরই, নইলে ৪০৪ */
    public function version(Document $document, DocumentVersion $version): StreamedResponse
    {
        return $this->library->stream($document, $version, 'download');
    }

    private function current(Document $document): DocumentVersion
    {
        $version = $document->currentVersion;

        abort_if($version === null, 404);

        return $version;
    }
}
