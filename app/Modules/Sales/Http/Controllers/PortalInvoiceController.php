<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\CustomerPapers;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐ গ্রাহকের বিলের ঘর — তালিকা, একটা বিল, আর তার PDF (গ্রাহক পোর্টাল, ধাপ ১, ১১ অক্টোবর ২০২৬)।
 *
 * ── মালিকের নিয়ম ─────────────────────────────────────────────────────────
 * বিলটা গ্রাহকের নিজের কাগজ, তাই এখানে **দামসহ পুরো বিল** — ট্র্যাকিংয়ে দাম থাকে না, সেটা আলাদা পাতা।
 * বাকি আর শোধের অঙ্ক অফিসের আদায়ের পর্দার সেই একই হিসাব ([[CustomerPapers::bills()]]) — পোর্টাল কেবল দেখায়।
 *
 * ⛔ প্রতিটা কাজে বিলটা আসে [[CustomerPapers]] থেকে, ঠিকানার আইডি দিয়ে সরাসরি নয়: অন্যের বিলের public id বসালে ৪০৪,
 * "আছে কি নেই" সেটাও বলা হয় না।
 */
class PortalInvoiceController extends Controller
{
    /** ⓘ তালিকার তিন ঘর — খোলা আগে, কারণ গ্রাহক আসেন "কোন বিলগুলো বাকি" জানতে */
    public const STATES = ['open', 'paid', 'all'];

    public function __construct(private readonly CustomerPapers $papers) {}

    public function index(Request $request): View
    {
        $state = in_array($request->query('state'), self::STATES, true) ? (string) $request->query('state') : 'open';
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        return view('sales::portal.bill-index', [
            'customer' => $this->papers->customer(),
            'state' => $state,
            'from' => $from,
            'to' => $to,
            'bills' => $this->papers->bills($state, $from, $to),
        ]);
    }

    public function show(string $id): View
    {
        return view('sales::portal.bill-show', [
            'customer' => $this->papers->customer(),
            'bill' => $this->papers->bill($id),
        ]);
    }

    /**
     * বিলের PDF — অফিসের সেই একই নকশায়; `?download=1` হলে ফাইল, নাহলে ব্রাউজারে খোলে (ছাপার জন্য)।
     *
     * ⓘ খাতায় ওঠে দুই ভাবেই — নামানো আর খোলা আলাদা চিহ্নে, গ্রাহকের নামসহ ([[SalesPrintController::customerCopy()]])।
     * ⓘ `callAction` দিয়ে, যাতে বিলটা তার নিজের শাখার নকশা আর মাপে আঁকা হয় — অফিসের ছাপার মতোই।
     */
    public function pdf(Request $request, string $id): Response
    {
        $bill = $this->papers->bill($id);

        return app(SalesPrintController::class)->callAction('customerCopy', [$request, $bill]);
    }

    /** ⓘ ভাঙা তারিখ ছাঁকনি নয় — চুপচাপ বাদ, যাতে পাতা ৫০০ না দেয় */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return strtotime($value) === false ? null : $value;
    }
}
