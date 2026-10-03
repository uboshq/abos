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
            $code = trim($this->codeOf($model));
            $out[(int) $model->getKey()] = $code === '' ? $this->nameOf($model) : $code.' · '.$this->nameOf($model);
        }

        asort($out);

        return $this->cache = $out;
    }

    public function resolve(string $raw): ?int
    {
        $raw = trim($raw);
        $options = $this->options();

        if ($raw === '') {
            return null;
        }

        // ⓘ নম্বর — কেবল তালিকায় থাকলে
        if (ctype_digit($raw)) {
            return isset($options[(int) $raw]) ? (int) $raw : null;
        }

        // ⓘ বাছাই-ঘরের লেখা — হুবহু "কোড · নাম", নয়তো কেবল কোড; ⛔ নাম দিয়ে নয় (এক নামে দুইজন থাকতে পারেন)
        $found = array_search($raw, $options, true);

        if ($found !== false) {
            return (int) $found;
        }

        foreach ($options as $id => $label) {
            if (str_starts_with($label, $raw.' · ')) {
                return (int) $id;
            }
        }

        return null;
    }
}
