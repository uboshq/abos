<?php

declare(strict_types=1);

namespace App\Core\Engines\Assistant;

use App\Core\Support\CompanyContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ মানে ধরে নাম খোঁজা — অক্ষর না মিললেও ("cosmos biskut", "বিস্কিট", "soyabin tel") ঠিক পণ্যটা (১৩ নভেম্বর ২০২৬)।
 *
 * ⓘ রাতে [[refresh()]] প্রতিটা নামের ছাপ রাখে; প্রশ্নের সময় [[nearest()]] কেবল প্রশ্নের শব্দের একটা ছাপ চায় (আধা সেকেন্ড) আর রাখা ছাপের
 *   সাথে মেলায় — শব্দের মিল ([[ProductInQuestion]]) কিছু না পেলে তবেই।
 * ⛔ আন্দাজ নয়: সবচেয়ে কাছেরটা [[MIN_SCORE]]-এর নিচে, বা দ্বিতীয়টার চেয়ে [[MIN_GAP]]-এর কম এগিয়ে থাকলে null — তখন "পাইনি"।
 *   সীমা দুটো Mac mini-র bge-m3-এ মেপে (১৩ নভেম্বর ২০২৬): ঠিক মিল ০.৪৯–০.৬৮, সম্পর্কহীন ("মোবাইল ফোন", "সিমেন্ট") ০.৪০-এর নিচে;
 *   "চিনির গুঁড়া" চিনি আর গুঁড়া দুধের মাঝে মাত্র ০.০২৭ ব্যবধান — দুটোই না বলা ঠিক।
 */
final class MeaningIndex
{
    public const MIN_SCORE = 0.47;

    public const MIN_GAP = 0.07;

    /** ⓘ এক ডাকে কয়টা নাম — গেটওয়ের মিনিটে ৬০ ডাকের সীমার ভেতরে, একটা ডাক ২০ সেকেন্ডের ভেতরে */
    public const BATCH = 32;

    /** @var array<string, array<int, list<float>>> */
    private array $loaded = [];

    public function __construct(private readonly ModelClient $model) {}

    /**
     * রাখা ছাপ হালনাগাদ — নতুন বা বদলানো নামের ছাপ বানায়, হারানো নামের ছাপ মোছে।
     *
     * @param  array<int, string>  $texts  ref_id => নাম
     * @return array{made: int, removed: int, failed: bool}
     */
    public function refresh(string $kind, array $texts): array
    {
        $companyId = (int) CompanyContext::id();
        $model = $this->model->embedModel();
        $have = DB::table('assistant_vectors')->where('company_id', $companyId)->where('kind', $kind)
            ->get(['ref_id', 'hash', 'model'])->keyBy('ref_id');

        $todo = [];

        foreach ($texts as $id => $text) {
            $hash = sha1($text);
            $row = $have->get($id);

            if ($row === null || $row->hash !== $hash || $row->model !== $model) {
                $todo[(int) $id] = $text;
            }
        }

        $made = 0;
        $failed = false;

        foreach (array_chunk($todo, self::BATCH, true) as $chunk) {
            $vectors = $this->model->embed(array_values($chunk));

            if ($vectors === null) {
                $failed = true;

                break;
            }

            $now = Carbon::now();

            foreach (array_keys($chunk) as $i => $id) {
                DB::table('assistant_vectors')->updateOrInsert(
                    ['company_id' => $companyId, 'kind' => $kind, 'ref_id' => $id],
                    ['model' => $model, 'hash' => sha1($chunk[$id]), 'vector' => self::pack($vectors[$i]), 'updated_at' => $now, 'created_at' => $now],
                );
                $made++;
            }
        }

        $gone = array_diff($have->keys()->map(fn ($id) => (int) $id)->all(), array_map('intval', array_keys($texts)));
        $removed = $gone === [] ? 0 : DB::table('assistant_vectors')->where('company_id', $companyId)->where('kind', $kind)->whereIn('ref_id', $gone)->delete();

        unset($this->loaded[$companyId.':'.$kind]);

        return ['made' => $made, 'removed' => $removed, 'failed' => $failed];
    }

    /**
     * ⭐ প্রশ্নের শব্দের সবচেয়ে কাছের নাম — নিশ্চিত হলে তবেই।
     *
     * @param  list<int>|null  $only  কেবল এই আইডিগুলোর মধ্যে (যেমন সক্রিয় পণ্য)
     * @return array{id: int, score: float}|null
     */
    public function nearest(string $kind, string $text, ?array $only = null): ?array
    {
        $text = trim($text);

        if (mb_strlen($text) < 2) {
            return null;
        }

        $vectors = $this->vectors($kind);

        if ($only !== null) {
            $vectors = array_intersect_key($vectors, array_flip($only));
        }

        if (count($vectors) === 0) {
            return null;
        }

        $asked = $this->model->embed([$text])[0] ?? null;

        if ($asked === null) {
            return null;
        }

        $best = null;
        $bestScore = -1.0;
        $second = -1.0;

        foreach ($vectors as $id => $vector) {
            $score = self::cosine($asked, $vector);

            if ($score > $bestScore) {
                [$second, $bestScore, $best] = [$bestScore, $score, $id];
            } elseif ($score > $second) {
                $second = $score;
            }
        }

        if ($best === null || $bestScore < self::MIN_SCORE || ($second > -1.0 && $bestScore - $second < self::MIN_GAP)) {
            return null;
        }

        return ['id' => (int) $best, 'score' => round($bestScore, 4)];
    }

    /** @return array<int, list<float>> */
    private function vectors(string $kind): array
    {
        $companyId = (int) CompanyContext::id();
        $key = $companyId.':'.$kind;

        if (! array_key_exists($key, $this->loaded)) {
            $this->loaded[$key] = [];

            foreach (DB::table('assistant_vectors')->where('company_id', $companyId)->where('kind', $kind)
                ->where('model', $this->model->embedModel())->get(['ref_id', 'vector']) as $row) {
                $this->loaded[$key][(int) $row->ref_id] = self::unpack((string) $row->vector);
            }
        }

        return $this->loaded[$key];
    }

    /** @param  list<float>  $vector */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /** @return list<float> */
    public static function unpack(string $blob): array
    {
        return $blob === '' ? [] : array_values(unpack('g*', $blob) ?: []);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        $dot = $na = $nb = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }

        return $na > 0 && $nb > 0 ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }
}
