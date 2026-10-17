<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

use App\Core\Contracts\ReportFilterSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * মডেল-ভিত্তিক ছাঁকনির উৎস — মডিউলগুলো কেবল কোয়েরি, কোড আর নাম বলে দেয় ([[ReportFilterSource]])।
 *
 * ⓘ কোয়েরিটা মডেলের নিজের দেয়াল নিয়েই আসে (কোম্পানি, শাখা, ডেটার পরিধি); এখানে কোনো দেয়াল তোলা হয় না। একবার
 * পড়া তালিকা অনুরোধ-জুড়ে মনে রাখা — পর্দা আর যাচাই একই তালিকা দেখে।
 */
abstract class ModelFilterSource implements ReportFilterSource
{
    /** ⓘ বাছাই-ঘরে এর বেশি সারি নয় — বড় তালিকায় লিখে খুঁজতে হয় (datalist নিজেই ছাঁকে) */
    public const LIMIT = 2000;

    /** @var array<int, string>|null */
    private ?array $cache = null;

    /** দেয়াল-সহ কোয়েরি — যাঁর যা দেখার, কেবল তাই */
    abstract protected function query(): Builder;

    protected function codeOf(Model $model): string
    {
        return (string) ($model->getAttribute('code') ?? '');
    }

    /** ⓘ মডেলের নিজের `name()` থাকলে সেটা, নাহলে ভাষা ধরে `name_bn`/`name_en` */
    protected function nameOf(Model $model): string
    {
        if (method_exists($model, 'name')) {
            return (string) $model->name();
        }

        $bn = (string) ($model->getAttribute('name_bn') ?? '');
        $en = (string) ($model->getAttribute('name_en') ?? $model->getAttribute('name') ?? '');

        return app()->getLocale() === 'bn' && $bn !== '' ? $bn : ($en !== '' ? $en : $bn);
    }

    public function options(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $out = [];

        foreach ($this->query()->limit(self::LIMIT)->get() as $model) {
            $out[(int) $model->getKey()] = $this->labelOf($model);
        }

        asort($out);

        return $this->cache = $out;
    }

    /**
     * ⭐ ফোনের বাছাই — মান public_id (মডেলে থাকলে), লেখা কেবল দেখানোর (fe, ১৭ অক্টোবর ২০২৬)।
     *
     * ⛔ লেখা মান হলে একই লেখার দুইটা সারি (নম্বরহীন দুইটা ঋণ, একই ব্যাংক) এক হয়ে যেত, আর খুলত ভুলটার খাতা — ভুলের
     * কথা ছাড়াই। ⓘ দেখানোর ছাদ [[LIMIT]]; তার বেশি হলে `capped`, আর পর্দা সার্ভারে খোঁজে ([[search()]])।
     *
     * @return array{0: list<array{value: string, label: string}>, 1: bool}
     */
    public function phoneChoices(): array
    {
        $rows = $this->query()->limit(self::LIMIT + 1)->get();
        $capped = $rows->count() > self::LIMIT;

        $out = $rows->take(self::LIMIT)->map(fn (Model $m): array => ['value' => $this->valueOf($m), 'label' => $this->labelOf($m)])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        return [$out, $capped];
    }

    /**
     * ⭐ দেয়ালের ভিতরে খোঁজা — ছাদের বাইরের সারিও (লেখার যেকোনো অংশ, বড়-ছোট হাতের অক্ষর নির্বিশেষে)।
     *
     * @return list<array{value: string, label: string}>
     */
    public function search(string $q, int $limit = 50): array
    {
        $q = mb_strtolower(trim($q));
        $out = [];

        if ($q === '') {
            return $out;
        }

        foreach ($this->walk() as $model) {
            $label = $this->labelOf($model);
            if (str_contains(mb_strtolower($label), $q)) {
                $out[] = ['value' => $this->valueOf($model), 'label' => $label];
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * ঠিকানার মান থেকে নম্বর — public_id, নম্বর, বা বাছাই-ঘরের "কোড · নাম" (নয়তো কেবল কোড)।
     *
     * ⛔ দেখানোর তালিকার ছাদে নয়, দেয়াল-সহ কোয়েরিতেই খোঁজা (fe, ১৭ অক্টোবর ২০২৬): আগে ২০০০-তম সারির পরের লট বা মানুষ
     * দুই দরজাতেই ফেরত যেত, দেয়ালের ভিতরে থেকেও। ⛔ একই লেখার দুইটা সারি হলে `null` — রিপোর্ট ফেরে, অনুমানে একটা খোলে না।
     * ⛔ নাম দিয়ে নয় (এক নামে দুইজন থাকতে পারেন)।
     */
    public function resolve(string $raw): ?int
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $probe = $this->query()->getModel();

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $raw) === 1 && $this->hasPublicId($probe)) {
            $id = $this->query()->where($probe->qualifyColumn('public_id'), $raw)->value($probe->getQualifiedKeyName());

            return $id === null ? null : (int) $id;
        }

        // ⓘ নম্বর — কেবল দেয়ালের ভিতরে থাকলে
        if (ctype_digit($raw)) {
            return $this->query()->whereKey((int) $raw)->exists() ? (int) $raw : null;
        }

        $exact = [];
        $byCode = [];

        foreach ($this->walk() as $model) {
            $label = $this->labelOf($model);
            if ($label === $raw) {
                $exact[] = (int) $model->getKey();
            } elseif (str_starts_with($label, $raw.' · ')) {
                $byCode[] = (int) $model->getKey();
            }
        }

        $found = $exact !== [] ? $exact : $byCode;

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * দেয়াল-সহ সব সারি, টুকরো টুকরো — ⚠️ প্রাথমিক চাবি শেষ ক্রমে, নইলে একই নামের সারি দুই টুকরোর সীমানায় হারাত বা দুবার আসত
     *
     * @return \Illuminate\Support\LazyCollection<int, Model>
     */
    private function walk(): \Illuminate\Support\LazyCollection
    {
        $query = $this->query();

        return $query->orderBy($query->getModel()->getQualifiedKeyName())->lazy(500);
    }

    /** "কোড · নাম", কোড না থাকলে কেবল নাম — দেখানোর তালিকার একই লেখা */
    protected function labelOf(Model $model): string
    {
        $code = trim($this->codeOf($model));

        return $code === '' ? $this->nameOf($model) : $code.' · '.$this->nameOf($model);
    }

    private function valueOf(Model $model): string
    {
        return $this->hasPublicId($model) && filled($model->getAttribute('public_id'))
            ? (string) $model->getAttribute('public_id')
            : $this->labelOf($model);
    }

    private function hasPublicId(Model $model): bool
    {
        return in_array(\App\Core\Concerns\HasPublicId::class, class_uses_recursive($model), true);
    }
}
