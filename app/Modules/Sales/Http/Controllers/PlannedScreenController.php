<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * মেনুতে আগে বসানো, কোড পরে — উদ্ধৃতি ও বিক্রয় আদেশের পর্দাগুলো।
 *
 * ⭐ মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: *"Sales module e ড্যাশবোর্ড er por ei
 * duti menu bosaw, code pore korbo, age bosaw"*। ⓘ প্রতিটা সারি এই এক পাতায়
 * আসে, আর পাতাটা সৎভাবে বলে পর্দাটা এখনো তৈরি হয়নি, আর কী করবে।
 *
 * ⚠️ তালিকার বাইরের নাম ৪০৪ — ঠিকানায় যা খুশি লিখে একটা "পর্দা" বানানো যায় না।
 * যেদিন কোনো পর্দা তৈরি হবে, তার সারি module.php-তে নিজের রুটে সরবে আর নামটা
 * এখান থেকে মুছবে।
 */
final class PlannedScreenController extends Controller implements HasMiddleware
{
    /** @var list<string> */
    public const SCREENS = [
        // ⓘ 'quotation_new', 'quotation_list', 'quotation_compare', 'quotation_revision' নিজের পাতায় সরেছে ([[MOVED]], ৪ অক্টোবর ২০২৬)
        // ⓘ 'order_new' আর 'order_list' নিজের রুটে সরেছে — পুরনো আদেশের পাতা (২৮ সেপ্টেম্বর ২০২৬)
        // ⓘ 'order_pending', 'order_partial', 'order_back', 'order_history' — এখন অর্ডার তালিকার ট্যাব ([[FOLDED]])
        // ⓘ 'loading_sheet' নিজের পাতায় সরেছে ([[LoadingSheetController]], ২৯ সেপ্টেম্বর ২০২৬)
        // ⓘ 'do_new' নিজের পাতায় সরেছে — DO ডেস্কের বোতাম ([[DeliveryOrderDeskController]], ৩ অক্টোবর ২০২৬); আংশিক আর ব্যাক DO — মালিক, ২ অক্টোবর ২০২৬
        // ⓘ 'transport_assign' নিজের পাতায় সরেছে ([[TransportAssignmentController]], ৩ অক্টোবর ২০২৬)
        // ⓘ 'do_partial' আর 'do_back' DO ডেস্কের ট্যাবে সরেছে ([[DeliveryOrderDeskController]], ৩ অক্টোবর ২০২৬)
        // ⓘ 'pricing_customer', 'pricing_channel', 'pricing_territory', 'pricing_special' দর তালিকার পাতায় সরেছে ([[PRICE_BOOK]], ৫ অক্টোবর ২০২৬)
        'pricing_dynamic',
    ];

    /**
     * ⭐ পুরনো ঠিকানা → দর তালিকার পাতা — ৫ অক্টোবর ২০২৬ থেকে চার সারি আসল পাতা ([[PriceBookController]])।
     *
     * ⓘ মেনু তখনই নতুন পাতায় গিয়েছিল, কিন্তু নামগুলো [[SCREENS]]-এ থেকে গিয়েছিল — "তৈরি হচ্ছে" পাতাও খুলত, আর মেনুর দাবি
     * পুরনো লিংক খুঁজত, যা [[ThePricingPagesWereOnlyASignTest]] নিষেধ করে (১১ অক্টোবর ২০২৬)। ⚠️ বুকমার্ক মরে না, ঠিক তালিকায় নামে।
     *
     * @var array<string, string>
     */
    public const PRICE_BOOK = [
        'pricing_customer' => 'customer',
        'pricing_channel' => 'tier',
        'pricing_territory' => 'territory',
        'pricing_special' => 'all',
    ];

    /**
     * ⭐ পুরনো ঠিকানা → অর্ডার তালিকার ট্যাব — নকশার পর্যালোচনা, ধাপ ৭-এর ২ (১ অক্টোবর ২০২৬)।
     *
     * ⓘ চারটা সারি মেনু থেকে উঠে অর্ডার তালিকার ট্যাব হলো ([[OrderTracking::LIST_TABS]])। ⚠️ ঠিকানাগুলো মরেনি:
     * বুকমার্ক আর পুরনো লিংক ঠিক ট্যাবে নামে। চাবির পাহারা আগের মতোই — রিডাইরেক্টের আগে `can:sales.order.view`।
     *
     * @var array<string, string>
     */
    public const FOLDED = [
        'order_pending' => 'pending',
        'order_partial' => 'partial',
        'order_back' => 'back',
        'order_history' => 'history',
    ];

    /**
     * ⭐ পুরনো ঠিকানা → আসল পাতা — উদ্ধৃতির চার সারি (মালিকের আন্তর্জাতিক পরিকল্পনা, ৪ অক্টোবর ২০২৬)।
     *
     * ⓘ নতুন, তালিকা, তুলনা, সংস্করণ এখন [[SalesQuotationController]]-এর নিজের পাতা; ⚠️ বুকমার্ক আর পুরনো লিংক মরে না,
     * ঠিক পাতায় নামে। চাবির পাহারা [[FOLDED]]-এর মতোই — রিডাইরেক্টের আগে এই পাতার চাবি, পরে আসল পাতার নিজের চাবি।
     *
     * @var array<string, string>
     */
    public const MOVED = [
        'quotation_new' => 'sales.quotation.create',
        'quotation_list' => 'sales.quotation.index',
        'quotation_compare' => 'sales.quotation.compare',
        'quotation_revision' => 'sales.quotation.revisions',
    ];

    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.order.view')];
    }

    public function show(Request $request, string $screen): View|RedirectResponse
    {
        if (isset(self::FOLDED[$screen])) {
            return redirect()->route('sales.order.index', ['tab' => self::FOLDED[$screen]]);
        }

        if (isset(self::MOVED[$screen])) {
            return redirect()->route(self::MOVED[$screen]);
        }

        if (isset(self::PRICE_BOOK[$screen])) {
            return redirect()->route('sales.price_book.index', ['target' => self::PRICE_BOOK[$screen]]);
        }

        abort_unless(in_array($screen, self::SCREENS, true), 404);

        return view('sales::planned.show', [
            'menu' => $this->menu->forUser($request->user()),
            'screen' => $screen,
        ]);
    }
}
