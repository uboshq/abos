<?php

declare(strict_types=1);

namespace App\Core\Phone;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ ফোনের নিজের পর্দায় একটা লেনদেনের তালিকা আর পাতা — মালিক, ১৭ অক্টোবর ২০২৬: *"mobile er ta mobile app style e zate
 * bujazay sei vabe koro, direct webpage app e bujajayna"* (fe-র ধাপ ৪: ক্রয়, বিক্রয়ের বাকি কাগজ আর স্থানান্তর)।
 *
 * <p>প্রতিটা ধরন তার মডিউলের ভিতরে, `module.php`-এর `phone_papers`-এ ([[PhonePaperRegistry]]); দরজা একটাই
 * ([[PhonePaperApiController]]): `GET /api/v1/papers/{key}` আর `GET /api/v1/papers/{key}/{public_id}`।
 *
 * <p>⛔ দেয়াল নতুন করে লেখা হয় না — [[query()]] মডেলের নিজের কোয়েরি, তার কোম্পানি, শাখা আর ডিলারের গ্লোবাল স্কোপসহ, ওয়েবের
 * তালিকার মতো। চাবি ওয়েবের পর্দার একই ([[permission()]]), পর্দার সুইচ ওয়েবের একই রুট ধরে ([[webRoute()]])।
 *
 * <p>⛔ কেবল দেখা — লেখার ফর্ম এখানে নেই (fe, ১৭ অক্টোবর ২০২৬)। ⓘ মান সবই লেখা (টাকা, তারিখ, নাম) — ফোন কেবল আঁকে।
 */
abstract class PhonePaper
{
    /** ফোনের পথের চাবি — `purchase_order`, `sales_return` … */
    abstract public function key(): string;

    /** ফোনের মডিউল-সুইচের কোড ([[PhoneModules::refuseUnlessReachable()]]) */
    abstract public function module(): string;

    /** ওয়েবের তালিকার নিজের চাবি */
    abstract public function permission(): string;

    /**
     * ওয়েবের তালিকার রুট — পর্দা বন্ধ থাকলে ফোনেও বন্ধ ([[RefuseSwitchedOffScreens::refuses()]])।
     *
     * @return array{0: string, 1: array<string, string>}
     */
    abstract public function webRoute(): array;

    /** @return Builder<Model> মডেলের নিজের কোয়েরি — দেয়াল তার গ্লোবাল স্কোপে */
    abstract public function query(): Builder;

    /**
     * তালিকার সারির নিজের ঘর — পক্ষ, পাশের কথা, অঙ্ক।
     *
     * @return array{party?: ?string, note?: ?string, total?: ?string}
     */
    abstract public function row(Model $paper, User $user): array;

    /**
     * কাগজের পাতা — ঘর, সারি আর যোগফল ([[PaperSheet]])।
     *
     * @return array{facts: list<array{label: string, value: string}>, columns: list<array{key: string, label: string, numeric?: bool}>, rows: list<array<string, ?string>>, totals: array<string, string>}
     */
    abstract public function detail(Model $paper, User $user): array;

    /** তালিকার তারিখের ঘর, টেবিলসহ — `null` হলে তারিখের ছাঁকনি নেই (দরের তালিকা) */
    public function dateColumn(): ?string
    {
        return $this->query()->getModel()->getTable().'.trx_date';
    }

    /** শাখার ঘর — `null` হলে শাখার ছাঁকনি নেই (দরের তালিকা শাখার নয়) */
    public function branchColumn(): ?string
    {
        return $this->query()->getModel()->getTable().'.branch_id';
    }

    /** @return array<string, string> অবস্থার কোড → নাম, ছাঁকনির চিপ */
    public function statuses(): array
    {
        return collect(DocumentStatus::ALL)->mapWithKeys(fn (string $s) => [$s => __('core.status.'.$s)])->all();
    }

    /**
     * অবস্থার ছাঁকনি — না বাছলে বাতিল লুকানো, যদি ওয়েবের তালিকাও লুকায় ([[hidesCancelled()]])।
     *
     * @param  Builder<Model>  $query
     */
    public function applyStatus(Builder $query, ?string $status): void
    {
        $column = $query->getModel()->getTable().'.status';

        if ($status !== null) {
            $query->where($column, $status);
        } elseif ($this->hidesCancelled()) {
            $query->where($column, '<>', DocumentStatus::CANCELLED);
        }
    }

    /**
     * না বাছলে বাতিল লুকায় কি না — ওয়েবের তালিকার একই উত্তর। ⓘ চাহিদাপত্র, RFQ আর চুক্তির ওয়েব-তালিকা বাতিলও দেখায়, তাই
     * ওদের কাগজ `false` বলে (a5-এর পর্যালোচনা, ১৭ অক্টোবর ২০২৬: ফোনে লুকানো ছিল, ওয়েবে দেখা যেত)।
     */
    protected function hidesCancelled(): bool
    {
        return true;
    }

    /** @param  Builder<Model>  $query */
    public function search(Builder $query, string $term): void
    {
        $query->search($term);
    }

    /** খোঁজা যায় কি না — ফোন খোঁজার ঘর দেখায় কি না */
    public function searchable(): bool
    {
        return true;
    }

    /** সর্বমোটের SQL (টেবিলসহ), বা `null` — যে কাগজে টাকা নেই, বা দেখার চাবি নেই */
    public function sumColumn(User $user): ?string
    {
        return null;
    }

    /** কাগজের নম্বর */
    public function numberOf(Model $paper): string
    {
        return (string) $paper->getAttribute('document_no');
    }

    /** কাগজের অবস্থা — নাম দিয়ে ([[statuses()]]) */
    public function statusOf(Model $paper): string
    {
        return (string) $paper->getAttribute('status');
    }

    /** তালিকার জন্য আগে টেনে আনা সম্পর্ক — সারিপ্রতি কোয়েরি নয় */
    public function with(): array
    {
        return [];
    }

    /**
     * তালিকার এক পাতার (বা খোলা একটা) কাগজ সারির আগে একসাথে — যা [[with()]]-এ সম্পর্ক হিসেবে আসে না (দেয়ালসহ নাম, পথ)।
     *
     * @param  Collection<int, Model>  $papers
     */
    public function prepare(Collection $papers): void {}

    /** ছাপার ধরন ([[DocumentApiController]]-এর `{type}`), বা `null` — ওয়েবে ছাপা নেই */
    public function printType(): ?string
    {
        return null;
    }

    /** একটা মডেলের নাম — বাংলায় থাকলে বাংলা, নইলে ইংরেজি, নইলে কোড */
    public static function nameOf(?Model $row): ?string
    {
        if ($row === null) {
            return null;
        }

        if (method_exists($row, 'name')) {
            $name = (string) $row->name();

            if ($name !== '') {
                return $name;
            }
        }

        $name = (string) ($row->getAttribute('name_bn') ?: $row->getAttribute('name_en') ?: '');

        return $name !== '' ? $name : ((string) ($row->getAttribute('code') ?? '') ?: null);
    }
}
