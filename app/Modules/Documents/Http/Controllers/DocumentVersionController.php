<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Http\Requests\DocumentVersionRequest;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Services\DocumentLibrary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ভার্সন — নতুন তোলা আর পুরনো ফেরানো (§৯; ৮ অক্টোবর ২০২৬)।
 *
 * ⛔ দুই পথেই কেবল নতুন সারি; কোনো ভার্সন বা ফাইল নিজের জায়গায় বদলায় না
 * ([[DocumentVersion]])। অনুমতি রুটের `can:addVersion` / `can:restoreVersion`-এ।
 */
final class DocumentVersionController extends Controller
{
    public function __construct(private readonly DocumentLibrary $library) {}

    public function store(DocumentVersionRequest $request, Document $document): RedirectResponse
    {
        $version = $this->library->addVersion(
            $document,
            $request->file('file'),
            $request->boolean('major'),
            $request->validated('comment'),
        );

        return redirect()->to(route('documents.show', $document).'#versions')
            ->with('saved', __('documents::message.version_added', ['version' => 'v'.$version->label()]));
    }

    public function restore(Request $request, Document $document, DocumentVersion $version): RedirectResponse
    {
        $comment = $request->validate(['comment' => ['nullable', 'string', 'max:500']])['comment'] ?? null;

        $made = $this->library->restoreVersion(
            $document,
            $version,
            $comment ?: __('documents::message.restored_comment', ['version' => 'v'.$version->label()]),
        );

        return redirect()->to(route('documents.show', $document).'#versions')
            ->with('saved', __('documents::message.version_restored', [
                'from' => 'v'.$version->label(),
                'version' => 'v'.$made->label(),
            ]));
    }
}
