<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Core\Engines\Assistant\ToolRegistry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;

/**
 * ⭐ The full-screen Copilot page's answer — what the engine found, shaped the way the owner's sample draws it
 *   (web sample copilot-web-sample, tab খ, 21 Nov 2026): a short text, a table, a small chart, source chips.
 *
 * ⛔ Nothing here counts or fetches. Every figure is the tool's own ([[AssistantEngine::ask()]] → [[ToolRegistry::call()]],
 *   which checks the person's key again); this class only arranges what came back. So the page can never show more
 *   than the quick ask or the phone would — CopilotSeesOnlyWhatItsPersonMaySee holds here too.
 * ⓘ Digits stay as the tools print them (English digits, owner 16 Nov 2026).
 */
final class CopilotAnswer
{
    /** ⓘ the small chart shows at most this many bars */
    public const BARS = 8;

    /** ⓘ the start screen shows at most this many suggestion chips */
    public const CHIPS = 6;

    /**
     * ⓘ the suggestion chip of each tool — icon and colour tone (colours live in copilot-page.css, not in Blade);
     *   the words are the tool's own example ([[assistant::ask.example]]).
     */
    private const CHIP_LOOKS = [
        'sales.summary' => ['icon' => 'sales', 'tone' => 'green'],
        'credit.overdue' => ['icon' => 'people', 'tone' => 'orange'],
        'stock.on_hand' => ['icon' => 'inventory', 'tone' => 'violet'],
        'sales.product_need' => ['icon' => 'calendar', 'tone' => 'blue'],
        'stock.summary' => ['icon' => 'list', 'tone' => 'cyan'],
    ];

    public function __construct(private readonly ToolRegistry $tools) {}

    /**
     * The answer line kept in the talk and sent to the page.
     *
     * @param  array{state: string, tool: ?string, result: ?array<string, mixed>, model: ?string}  $answer  from [[AssistantEngine::ask()]]
     * @return array<string, mixed>
     */
    public function present(array $answer, ?int $answerId = null): array
    {
        $state = (string) $answer['state'];
        $result = $state === 'answered' ? (array) $answer['result'] : null;
        $model = $answer['model'] ?? null;

        $line = [
            'role' => 'ai',
            'at' => Carbon::now()->toIso8601String(),
            'state' => $state,
            'tool' => $answer['tool'] ?? null,
            'text' => $result !== null ? (string) ($result['summary'] ?? '') : (string) __('assistant::ask.state.'.$state),
            'headings' => [],
            'columns' => [],
            'rows' => [],
            'chart' => null,
            'sources' => [],
            'by' => $model === null ? '' : ($model === 'words' ? (string) __('assistant::ask.words') : (string) $model),
            'answer_id' => $answerId,
        ];

        if ($result === null) {
            return $line;
        }

        $columns = array_values(array_map('strval', (array) ($result['columns'] ?? [])));
        $rows = [];

        foreach ((array) ($result['rows'] ?? []) as $row) {
            $row = (array) $row;
            $clean = [];

            foreach ($columns as $col) {
                $clean[$col] = (string) ($row[$col] ?? '');
            }

            $clean['href'] = self::safeHref($row['href'] ?? null);
            $rows[] = $clean;
        }

        $line['columns'] = $columns;
        $line['headings'] = array_map(fn (string $col): string => self::heading($col), $columns);
        $line['rows'] = $rows;
        $line['chart'] = self::chart($columns, $rows);
        $line['sources'] = $this->sources((array) ($result['sources'] ?? []));

        return $line;
    }

    /**
     * ⛔ A stored answer is shown again only while its tool is still the person's — a key taken away since then hides
     *   the old table too (the talk never widens what the person may see today).
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public function stillTheirs(User $user, array $messages): array
    {
        $mine = $this->tools->for($user);
        $out = [];

        foreach ($messages as $m) {
            $tool = $m['tool'] ?? null;

            if (($m['role'] ?? '') === 'ai' && is_string($tool) && $tool !== '' && ! array_key_exists($tool, $mine)) {
                $m = array_merge($m, [
                    'state' => 'blocked', 'text' => (string) __('assistant::ask.state.blocked'),
                    'headings' => [], 'columns' => [], 'rows' => [], 'chart' => null, 'sources' => [], 'answer_id' => null,
                ]);
            }

            $out[] = $m;
        }

        return $out;
    }

    /**
     * ⭐ The greeting — first name and the time of day ("শুভ দুপুর, শুভ — কী জানতে চান?").
     *
     * @return array{hello: string, ask: string}
     */
    public function greeting(User $user, ?Carbon $now = null): array
    {
        $hour = (int) ($now ?? Carbon::now())->format('G');
        $part = match (true) {
            $hour >= 5 && $hour < 12 => 'morning',
            $hour >= 12 && $hour < 16 => 'noon',
            $hour >= 16 && $hour < 18 => 'afternoon',
            $hour >= 18 && $hour < 21 => 'evening',
            default => 'night',
        };
        $first = trim((string) strtok(trim((string) $user->name), " \t"));

        return [
            'hello' => (string) __('assistant::chat.hello.'.$part, ['name' => $first === '' ? (string) $user->name : $first]),
            'ask' => (string) __('assistant::chat.hello_ask'),
        ];
    }

    /**
     * ⭐ The start screen's chips — only questions this person may ask (their tools), each with its icon and tone.
     *
     * @return list<array{text: string, icon: string, tone: string}>
     */
    public function chips(User $user, ?string $moduleFirst = null): array
    {
        $names = array_keys($this->tools->for($user));

        if ($moduleFirst !== null && $moduleFirst !== '') {
            usort($names, fn (string $a, string $b): int => (int) ! self::belongsTo($a, $moduleFirst) <=> (int) ! self::belongsTo($b, $moduleFirst));
        }

        $out = [];

        foreach ($names as $name) {
            $key = 'assistant::ask.example.'.str_replace('.', '_', $name);

            if (! Lang::has($key)) {
                continue;
            }

            $look = self::CHIP_LOOKS[$name] ?? ['icon' => 'brain', 'tone' => 'slate'];
            $out[] = ['text' => (string) __($key), 'icon' => $look['icon'], 'tone' => $look['tone']];

            if (count($out) >= self::CHIPS) {
                break;
            }
        }

        return $out;
    }

    /** ⓘ a short title for the talk — the first question, cut */
    public static function titleOf(string $question): string
    {
        $q = trim(preg_replace('/\s+/u', ' ', $question) ?? $question);

        return mb_strlen($q) > 80 ? mb_substr($q, 0, 79).'…' : $q;
    }

    /** ⓘ "sales.summary" belongs to the sales page, "stock.*" to inventory, "credit.*" to credit control */
    private static function belongsTo(string $tool, string $module): bool
    {
        $head = strtok($tool, '.');
        $aliases = ['stock' => 'inventory', 'credit' => 'credit_control'];

        return $head === $module || ($aliases[$head] ?? null) === $module;
    }

    private static function heading(string $col): string
    {
        $key = 'assistant::chat.col.'.$col;

        return Lang::has($key) ? (string) __($key) : $col;
    }

    /**
     * ⓘ The small chart: one bar per row, from the second column — only when the rows are alike (a list of customers,
     *   warehouses, products), not a "what · value" summary whose values are different things (taka, a count).
     *
     * @param  list<string>  $columns
     * @param  list<array<string, string>>  $rows
     * @return array{title: string, bars: list<array{label: string, value: string, pct: int}>}|null
     */
    public static function chart(array $columns, array $rows): ?array
    {
        if (count($rows) < 2 || count($columns) < 2 || $columns[0] === 'what') {
            return null;
        }

        $valueCol = $columns[1];
        $bars = [];

        foreach (array_slice($rows, 0, self::BARS) as $row) {
            $number = self::number((string) ($row[$valueCol] ?? ''));

            if ($number === null) {
                return null;
            }

            $bars[] = ['label' => (string) ($row[$columns[0]] ?? ''), 'value' => (string) ($row[$valueCol] ?? ''), 'n' => $number];
        }

        // ⓘ the bar lengths in bcmath — the values are often taka, and taka never passes through a float (MoneyIsNeverAFloatTest)
        $max = '0';
        foreach ($bars as $b) {
            if (bccomp(self::magnitude($b['n']), $max, 4) > 0) {
                $max = self::magnitude($b['n']);
            }
        }

        if (bccomp($max, '0', 4) <= 0) {
            return null;
        }

        return [
            'title' => self::heading($valueCol),
            'bars' => array_map(fn (array $b): array => [
                'label' => $b['label'], 'value' => $b['value'],
                // ⓘ rounded half up: +0.5 then cut to a whole
                'pct' => max(2, (int) bcadd(bcdiv(bcmul(self::magnitude($b['n']), '100', 4), $max, 4), '0.5', 0)),
            ], $bars),
        ];
    }

    /** "৳3,48,200" → "348200"; "62 দিন" → "62"; Bengali digits read too; no digit → null — a bcmath string, never a float */
    public static function number(string $text): ?string
    {
        $text = strtr($text, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

        if (! preg_match('/(-?)([\d,]*\.?\d+)/', $text, $m)) {
            return null;
        }

        $digits = str_replace(',', '', $m[2]);

        // ⓘ ".5" → "0.5" — bcmath wants a whole part
        return $m[1].(str_starts_with($digits, '.') ? '0'.$digits : $digits);
    }

    /** the size of a bcmath number, its sign dropped */
    private static function magnitude(string $n): string
    {
        return ltrim($n, '-');
    }

    /**
     * ⭐ "উৎস: …" chips — each source the tool named, with a link; the first one carries the date it was read.
     *
     * @param  array<int, mixed>  $sources
     * @return list<array{label: string, href: string}>
     */
    private function sources(array $sources): array
    {
        $out = [];

        foreach ($sources as $i => $s) {
            $s = (array) $s;
            $label = trim((string) ($s['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            $out[] = [
                'label' => $out === [] ? (string) __('assistant::chat.source_first', ['label' => $label, 'date' => Carbon::today()->format('d-m-Y')]) : $label,
                'href' => self::safeHref($s['href'] ?? null),
            ];
        }

        return $out;
    }

    /** ⛔ only our own addresses — a relative path or this app's URL; anything else is dropped */
    private static function safeHref(mixed $href): string
    {
        $href = is_string($href) ? trim($href) : '';

        if ($href === '') {
            return '';
        }

        if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
            return $href;
        }

        $base = rtrim((string) url('/'), '/');

        return str_starts_with($href, $base.'/') || $href === $base ? $href : '';
    }
}
