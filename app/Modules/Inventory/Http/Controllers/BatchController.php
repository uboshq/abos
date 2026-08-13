<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Services\BatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * লটের সংশোধন — ছাপা দাম আর মেয়াদ।
 *
 * দুইটাই আলাদা অনুমতির পেছনে (`inventory.batch.reprice`), কারণ দুইটাই
 * সিলিং সরাতে পারে: দাম সরাসরি, আর মেয়াদ পিছিয়ে দিয়ে মেয়াদোত্তীর্ণ
 * মাল আবার বিক্রয়যোগ্য করে।
 */
class BatchController extends Controller implements HasMiddleware
{
    public function __construct(private readonly BatchService $batches) {}

    public static function middleware(): array
    {
        return [new Middleware('can:inventory.batch.reprice')];
    }

    public function reprice(Request $request, Batch $batch): RedirectResponse
    {
        $this->assertOurs($batch);

        $data = $request->validate([
            // খালি চলে — "গায়ে দাম নেই", তখন সিলিংও নেই
            'mrp' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->batches->reprice($batch, $data['mrp'] ?? null, $data['reason']);

        return back()->with('saved', __('inventory::message.batch_repriced', [
            'batch' => $batch->batch_no,
        ]));
    }

    public function correctExpiry(Request $request, Batch $batch): RedirectResponse
    {
        $this->assertOurs($batch);

        $data = $request->validate([
            'expiry_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->batches->correctExpiry($batch, $data['expiry_date'] ?? null, $data['reason']);

        return back()->with('saved', __('inventory::message.batch_expiry_corrected', [
            'batch' => $batch->batch_no,
        ]));
    }

    /**
     * অন্য কোম্পানির লট এখানে পৌঁছায়ই না — Batch-এ কোম্পানির গ্লোবাল
     * স্কোপ আছে, তাই রুট-বাইন্ডিংই ৪০৪ দেয়। তবু ধরে নেওয়া হয় না;
     * স্কোপটা কোনোদিন সরলে এই লাইনটাই শেষ পাহারা।
     */
    private function assertOurs(Batch $batch): void
    {
        abort_unless((int) $batch->company_id === CompanyContext::id(), 404);
    }
}
