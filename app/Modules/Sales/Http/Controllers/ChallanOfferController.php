<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\ChallanOffers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ খসড়া চালানে অফার বসানো আর তোলা — দুইটাই মানুষের চাপা বোতাম (অডিট §১১, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ⓘ চাবি `promotion.apply` — অফারের চাবি, কারণ কাজটা অফার বসানো; দরজাটা বিক্রয়ের,
 * কারণ কাগজটা বিক্রয়ের। ⚠️ Promotion মডিউল বন্ধ থাকলে চাবিটাই কারও থাকে না, আর
 * থাকলেও [[NoSalesOffers]] পরিষ্কার "না" বলে।
 *
 * ⛔ কাগজের দিকের সব পাহারা সেবায় ([[ChallanOffers]]) — খসড়া কি না, কাউন্টারের কি না,
 * সারিটা এই চালানের কি না। ⓘ দরজা কেবল চাবি দেখে; ঠিকানা টাইপ করলেও সেবা থামায়।
 */
final class ChallanOfferController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ChallanOffers $offers) {}

    public static function middleware(): array
    {
        return [new Middleware('can:promotion.apply')];
    }

    public function store(Request $request, DeliveryChallan $challan): RedirectResponse
    {
        $data = $request->validate([
            'line_id' => ['required', 'integer'],
            'offer_id' => ['required', 'integer'],
        ]);

        $this->offers->apply($challan, (int) $data['line_id'], (int) $data['offer_id']);

        return redirect()
            ->route('sales.challan.show', $challan)
            ->with('saved', __('sales::offers.applied'));
    }

    public function destroy(DeliveryChallan $challan, int $line, int $offer): RedirectResponse
    {
        $this->offers->remove($challan, $line, $offer);

        return redirect()
            ->route('sales.challan.show', $challan)
            ->with('saved', __('sales::offers.removed'));
    }
}
