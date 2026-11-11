<?php

declare(strict_types=1);

namespace App\Core\Engines\Assistant;

/**
 * ⭐ নিজের শব্দ-তালিকা — মডেল না থাকলে বা Mac বন্ধ হলে সাধারণ প্রশ্ন বোঝা (পূর্ণ পরিকল্পনা অংশ ১০)।
 *
 * ⓘ প্রতিটা দরজা নিজের শব্দ দেয় (`describe()['keywords']` — বাংলা, বাংলিশ, ইংরেজি); প্রশ্নে যে দরজার শব্দ সবচেয়ে বেশি মেলে, সেটা।
 *   মান হিসেবে পুরো প্রশ্নটাই যায় (`q`) — দরজা নিজে তার থেকে পণ্য বা গ্রাহকের নাম খোঁজে।
 * ⓘ কোনো শব্দ না মিললে null — কোপাইলট তখন "বুঝিনি" বলে, আন্দাজ করে না।
 */
final class WordMatcher
{
    /**
     * @param  array<string, class-string<AssistantTool>>  $tools
     * @return array{tool: string, args: array<string, mixed>}|null
     */
    public function match(string $question, array $tools): ?array
    {
        $q = mb_strtolower($question);
        $best = null;
        $bestScore = 0;

        foreach ($tools as $name => $class) {
            $score = 0;

            foreach ($class::describe()['keywords'] ?? [] as $word) {
                $word = mb_strtolower((string) $word);

                if ($word !== '' && str_contains($q, $word)) {
                    // ⓘ লম্বা শব্দ বেশি নিশ্চিত — "কত লাগবে" "কত"-র চেয়ে বেশি বলে
                    $score += mb_strlen($word);
                }
            }

            if ($score > $bestScore) {
                [$best, $bestScore] = [$name, $score];
            }
        }

        return $best === null ? null : ['tool' => $best, 'args' => ['q' => $question]];
    }
}
