<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\AssetAcknowledgement;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Services\AssetVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ দায়িত্ব আর লেবেল — স্থায়ী সম্পদ ধাপ ৪।
 *
 * ⓘ "আমার সম্পদ": দায়িত্বে থাকা কর্মী নিজের জিনিস দেখেন আর "বুঝে নিয়েছি" বলেন — হিসাবের চাবি ছাড়াই, দেয়ালটা দায়িত্বের
 * ([[FixedAssetPolicy::viewOwn()]], [[FixedAssetPolicy::acknowledge()]])।
 * ⓘ লেবেল: ট্যাগ নম্বর দাগে (Code 128) আর লেখায় — QR নয়, মালিক বাদ দিয়েছেন।
 */
class AssetCustodyController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly AssetVerificationService $custody,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.asset.view', only: ['labels'])];
    }

    public function mine(Request $request): View
    {
        $this->authorize('viewOwn', FixedAsset::class);

        $assets = $this->custody->mine((int) $request->user()->id);
        $assets->load('acknowledgements');

        return view('accounts::asset.mine', [
            'menu' => $this->menu->forUser($request->user()),
            'assets' => $assets,
        ]);
    }

    public function acknowledge(Request $request, int $asset): RedirectResponse
    {
        // ⓘ শাখার দেয়াল নয় — দায়িত্বই দেয়াল; অন্যের জিনিসে নিয়মটাই না বলে
        $target = FixedAsset::acrossBranches()->findOrFail($asset);
        $this->authorize('acknowledge', $target);

        $data = $request->validate([
            'condition' => ['required', Rule::in(AssetAcknowledgement::CONDITIONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->custody->acknowledge($target, (int) $request->user()->id, $data['condition'], $data['note'] ?? null);

        return back()->with('status', __('accounts::asset.ack_saved'));
    }

    /**
     * ⭐ লেবেলের শীট — বাছাই করা সম্পদ, নয়তো এক শাখার সব চালু সম্পদ।
     */
    public function labels(Request $request, PrintEngine $print, SettingsService $settings): Response
    {
        $data = $request->validate([
            'assets' => ['nullable', 'array'],
            'assets.*' => ['integer'],
            'branch_id' => ['nullable', 'integer'],
            'paper' => ['nullable', 'string'],
        ]);

        $assets = FixedAsset::query()->inService()
            ->when($data['assets'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->when($data['branch_id'] ?? null, fn ($q, $branch) => $q->where('branch_id', $branch))
            ->with('branch')->orderBy('tag_no')->limit(500)->get();

        abort_if($assets->isEmpty(), 404);

        $paper = PaperSize::chosen($data['paper'] ?? null, $settings->get('accounts.print.paper.asset_labels'));

        $pdf = $print->render(
            template: 'accounts::print.asset-labels',
            data: [
                'title' => __('accounts::asset.labels_title'),
                'labels' => $assets->map(fn (FixedAsset $a) => [
                    'name' => $a->name,
                    // ⓘ দাগে কেবল ASCII যায় — ট্যাগ না থাকলে কাগজের নম্বর
                    'payload' => (string) ($a->tag_no ?: $a->document_no),
                    'branch' => (string) $a->branch?->name(),
                ]),
            ],
            paper: $paper,
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="asset-labels.pdf"',
        ]);
    }
}
