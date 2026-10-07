<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Services\GatePassService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * গেট পাস — তালিকা, দেখা আর কারণসহ বাতিল। ⛔ তৈরির দরজা নেই: গেট পাস রওনায় নিজে জন্মায়
 * ([[GatePassService::issueFor()]]), হাতে বানানো কাগজ গেটে মিলত না।
 */
class GatePassController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly GatePassService $passes,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.gate_pass.view', only: ['index', 'show']),
            new Middleware('can:sales.gate_pass.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $term = trim((string) $request->query('q'));
        $showCancelled = $request->boolean('cancelled');

        $passes = GatePass::query()
            ->search($term === '' ? null : $term)
            ->when(! $showCancelled, fn ($q) => $q->where('status', GatePass::ISSUED))
            ->with(['challan.customer', 'issuer'])
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('sales::gate_pass.index', [
            'menu' => $this->menu->forUser($request->user()),
            'passes' => $passes,
            'q' => $term,
            'showCancelled' => $showCancelled,
        ]);
    }

    public function show(Request $request, GatePass $gatePass): View
    {
        $gatePass->load(['challan.customer', 'challan.lines.product.unit', 'issuer', 'canceller', 'shipment']);

        return view('sales::gate_pass.show', [
            'menu' => $this->menu->forUser($request->user()),
            'pass' => $gatePass,
        ]);
    }

    public function cancel(Request $request, GatePass $gatePass): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->passes->cancel($gatePass, $data['reason']);

        return redirect()
            ->route('sales.gate_pass.show', $gatePass)
            ->with('saved', __('sales::gate_pass.cancelled', ['no' => $gatePass->document_no]));
    }
}
