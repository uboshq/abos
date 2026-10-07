<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\AuditFieldChange;
use App\Models\AuditTrail;
use App\Modules\Inventory\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * মূল্য তালিকা — পণ্যের বিক্রয়-দাম দেখা, বদলানো, আর কে কবে কত থেকে বদলাল।
 *
 * ── কেন নতুন কোনো টেবিল নেই ───────────────────────────────────────────
 * মালিকের নির্দেশ, ২৭ সেপ্টেম্বর ২০২৬: "মূল্য তালিকা" পাতাটা এতদিন "তৈরি
 * হচ্ছে" দেখাত। ⓘ দাম থাকে পণ্যের নিজের `sale_price` ঘরে, আর পণ্য আগে
 * থেকেই [[IsAudited]] — প্রতিটা বদলে আগের আর নতুন মান অডিট-খাতায় বসে।
 * ⛔ আলাদা একটা ইতিহাসের টেবিল মানে একই ঘটনার দুই খাতা, যা একদিন
 * আলাদা কথা বলত (পণ্যের পর্দা থেকে বদলালে একটায় উঠত, অন্যটায় না)।
 * তাই ইতিহাস অডিট-খাতা থেকেই পড়া — পণ্যের পর্দা, আমদানি বা এই পাতা,
 * যেখান থেকেই বদলাক, সব এক জায়গায়।
 */
final class SalePriceBook
{
    /** যে ঘরের ইতিহাস এই পাতা দেখায়। */
    public const FIELD = 'sale_price';

    /**
     * চালু পণ্য, নাম বা কোড ধরে খোঁজা যায়।
     *
     * @return LengthAwarePaginator<Product>
     */
    public function list(?string $q): LengthAwarePaginator
    {
        return Product::query()
            ->active()
            ->with('unit')
            ->when(filled($q), function ($query) use ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $q)).'%';

                $query->where(fn ($w) => $w->where('code', 'like', $like)
                    ->orWhere('name_en', 'like', $like)
                    ->orWhere('name_bn', 'like', $like));
            })
            ->orderBy('name_en')
            ->paginate(50)
            ->withQueryString();
    }

    /**
     * একটা পণ্যের বিক্রয়-দাম বসানো।
     *
     * ⓘ সারিতে তালা দিয়ে, যাতে দুইজন একই মুহূর্তে বদলালে দ্বিতীয়জন প্রথমজনের
     * দাম দেখে বদলান — নইলে অডিটের "আগে কত" মিথ্যা হত। দাম একই থাকলে কিছুই
     * লেখা হয় না, তাই ইতিহাসে ফাঁকা সারি জমে না।
     */
    public function setPrice(Product $product, mixed $price): Product
    {
        $price = trim((string) $price);

        if (! is_numeric($price) || bccomp($price, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'sale_price' => __('sales::price_list.not_a_price'),
            ]);
        }

        return DB::transaction(function () use ($product, $price) {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            if (bccomp((string) $locked->sale_price, $price, 4) !== 0) {
                $locked->sale_price = bcadd($price, '0', 4);
                $locked->save();
            }

            return $locked;
        });
    }

    /**
     * দামের ইতিহাস — নতুন আগে।
     *
     * @return Collection<int, object{when: mixed, user: ?string, old: ?string, new: ?string}>
     */
    public function history(Product $product): Collection
    {
        return AuditFieldChange::query()
            ->where('field', self::FIELD)
            ->whereIn('audit_trail_id', AuditTrail::query()->forRecord(Product::class, (int) $product->getKey())->select('id'))
            ->with('trail.user')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AuditFieldChange $c) => (object) [
                'when' => $c->trail?->created_at,
                'user' => $c->trail?->user?->name,
                'old' => $c->old_value,
                'new' => $c->new_value,
            ]);
    }

    /**
     * এই পাতার প্রতিটা পণ্যের শেষ দাম-বদল — একটা কোয়েরিতে।
     *
     * @param  iterable<Product>  $products
     * @return array<int, object{when: mixed, user: ?string}>
     */
    public function lastChanges(iterable $products): array
    {
        $ids = collect($products)->map(fn (Product $p) => (int) $p->getKey())->all();

        if ($ids === []) {
            return [];
        }

        $rows = AuditFieldChange::query()
            ->where('field', self::FIELD)
            ->join('audit_trails as t', 't.id', '=', 'audit_field_changes.audit_trail_id')
            ->where('t.auditable_type', Product::class)
            ->whereIn('t.auditable_id', $ids)
            ->orderByDesc('audit_field_changes.id')
            ->get(['t.auditable_id', 't.created_at', 't.user_id']);

        $users = DB::table('users')->whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->auditable_id] ??= (object) [
                'when' => $row->created_at,
                'user' => $users[$row->user_id] ?? null,
            ];
        }

        return $out;
    }
}
