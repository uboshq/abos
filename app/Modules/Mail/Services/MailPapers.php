<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ "ABOS থেকে সংযুক্ত করুন" (নমুনা v4, পর্দা ৩খ) — বিল, চালান, অর্ডার, রসিদ, ভাউচার/ক্রেডিট নোট, ক্রয়াদেশ, খতিয়ানের PDF মেইলে বসে।
 *
 * ⓘ PDF আসে কাগজের নিজের ছাপার ঠিকানা থেকে, পাঠানো কর্মীর নিজের নামে — তাই ছাপার সব নিয়ম (খসড়া ছাপা নয়, ভাড়া বাকি থাকলে নয়,
 *   অন্য শাখার কাগজ নয়, চাবি ছাড়া নয়) মেইলেও একই থাকে; এখানে নতুন করে কোনো পাহারা লেখা হয়নি, তাই ফাঁকও নেই।
 * ⛔ ছাপা না গেলে মেইল যায় না — কারণসহ ফেরে ([[MailController::send]])।
 */
final class MailPapers
{
    /** ⓘ কাগজের ধরন → ছাপার ঠিকানা, ঠিকানার নাম-ঘর, আর পর্দার নাম */
    public const KINDS = [
        'invoice' => ['route' => 'sales.print.invoice', 'param' => 'invoice', 'model' => \App\Modules\Sales\Models\SalesInvoice::class],
        'challan' => ['route' => 'sales.print.challan', 'param' => 'challan', 'model' => \App\Modules\Sales\Models\DeliveryChallan::class],
        'order' => ['route' => 'sales.print.order', 'param' => 'order', 'model' => \App\Modules\Sales\Models\SalesOrder::class],
        'receipt' => ['route' => 'sales.print.receipt', 'param' => 'collection', 'model' => \App\Modules\Sales\Models\Collection::class],
        'voucher' => ['route' => 'accounts.voucher.print', 'param' => 'voucher', 'model' => \App\Modules\Accounts\Models\Voucher::class],
        'purchase_order' => ['route' => 'purchase.print.order', 'param' => 'order', 'model' => \App\Modules\Purchase\Models\PurchaseOrder::class],
        'statement' => ['route' => 'accounts.statement', 'param' => null, 'model' => null],
    ];

    /**
     * কাগজের PDF — ছাপার পাতা যেমন দেয়।
     *
     * @return array{name: string, mime: string, bytes: string, no: string}
     */
    public function pdf(User $user, string $kind, int $id, string $partyType = 'customer'): array
    {
        $map = self::KINDS[$kind] ?? null;
        abort_if($map === null || ! Route::has($map['route']), 422, __('mail::message.paper_unknown'));

        $url = $map['param'] === null
            ? route($map['route'], ['type' => $partyType, 'id' => $id], false)
            : route($map['route'], [$map['param'] => $id], false);

        $original = app('request');
        $sub = Request::create($url, 'GET');
        $sub->setLaravelSession($original->hasSession() ? $original->session() : app('session.store'));
        $sub->setUserResolver(fn () => $user);

        try {
            $response = Route::dispatch($sub);
        } finally {
            app()->instance('request', $original);
        }

        $type = (string) $response->headers->get('Content-Type', '');
        $bytes = (string) $response->getContent();

        if ($response->getStatusCode() !== 200 || ! str_contains($type, 'pdf') || ! str_starts_with($bytes, '%PDF')) {
            abort(422, __('mail::message.paper_no_pdf'));
        }

        $no = $this->numberOf($kind, $id);

        return ['name' => ($no !== '' ? $no : $kind.'-'.$id).'.pdf', 'mime' => 'application/pdf', 'bytes' => $bytes, 'no' => $no];
    }

    /** ⓘ পর্দায় লেখা নম্বর থেকে কাগজ — কোম্পানির ভিতরে, ঠিক মিল */
    public function idOf(string $kind, string $no): ?int
    {
        $model = self::KINDS[$kind]['model'] ?? null;

        if ($model === null || ! class_exists($model) || trim($no) === '') {
            return null;
        }

        $id = $model::query()->where('document_no', trim($no))->value('id');

        return $id !== null ? (int) $id : null;
    }

    public function numberOf(string $kind, int $id): string
    {
        $model = self::KINDS[$kind]['model'] ?? null;

        if ($model === null || ! class_exists($model)) {
            return '';
        }

        return (string) ($model::query()->whereKey($id)->value('document_no') ?? '');
    }
}
