<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Core\Contracts\Drillable;
use App\Core\Services\PaperTrail;
use App\Core\Services\PhonePaperDoor;
use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Messaging\Models\Message;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * The ERP paper card inside a message — sample: "বার্তায় পাঠানো কাগজ খোলে কেবল যাঁর সেই কাগজ দেখার অনুমতি আছে; বাকিরা শুধু নম্বর দেখেন".
 *
 * ⭐ The paper words and the open rule are the print door's own ([[PhonePaperDoor]]), never a second list.
 * ⛔ Without the right to open, the card carries only type, id, number and title — no amount, party or status.
 * ⓘ The card grants nothing: opening always goes through the paper's own door and policy.
 */
final class PaperCards
{
    /** ⓘ the attach picker asks each paper type for at most this many rows ([[search()]]) */
    public const PER_TYPE = 5;

    /** @var array<string, array{0: string, 1: class-string<Model>, 2: Model}|null> */
    private array $seen = [];

    public function __construct(private readonly PhonePaperDoor $door) {}

    /** @return array{0: string, 1: class-string<Model>, 2: Model}|null */
    public function find(string $type, string $publicId): ?array
    {
        $key = $type.'#'.$publicId;

        if (! array_key_exists($key, $this->seen)) {
            try {
                $this->seen[$key] = $this->door->find($type, $publicId);
            } catch (\Throwable) {
                $this->seen[$key] = null;
            }
        }

        return $this->seen[$key];
    }

    /** May this person open the paper (their own keys AND their own branch)? */
    public function mayOpen(User $user, string $type, string $publicId): bool
    {
        $found = $this->find($type, $publicId);

        if ($found === null) {
            return false;
        }

        try {
            return $this->door->mayOpen($user, $found[0], $found[2], checkBranch: true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The composer's attach → "ERP কাগজ" (web sample v7, tab খ): MY papers whose number matches, newest first — only
     * the ones I may open myself (the same rule as sending one, [[MessengerService::sharePaper()]]).
     *
     * ⛔ A paper type whose print keys I lack is not even queried. ⓘ Empty term = my most recent papers.
     * ⓘ Each type is asked for at most PER_TYPE rows by its own number column (`document_no`, `number`, `voucher_no`,
     *   `code` — the first one the table has); a type without one is skipped.
     *
     * @return list<array{type: string, id: string, no: string, title: string, amount: string, party: string}>
     */
    public function search(User $me, string $term, int $limit = 20): array
    {
        $term = trim(mb_substr($term, 0, 60));
        $like = '%'.addcslashes($term, '\\%_').'%';
        $out = [];

        foreach ($this->door->printable() as $type => [$documentType, $class]) {
            if (count($out) >= $limit) {
                break;
            }

            $abilities = PaperTrail::abilitiesFor($documentType);
            if ($abilities === [] || array_filter($abilities, fn (string $a): bool => ! $me->can($a)) !== []) {
                continue;
            }

            try {
                $model = new $class;
                $columns = Schema::getColumnListing($model->getTable());
                $numberColumn = array_values(array_intersect(['document_no', 'number', 'voucher_no', 'code'], $columns))[0] ?? null;
                if ($numberColumn === null || ! in_array('public_id', $columns, true)) {
                    continue;
                }

                $rows = $class::query()
                    ->when($term !== '', fn ($q) => $q->where($numberColumn, 'like', $like))
                    ->orderByDesc($model->getQualifiedKeyName())
                    ->limit(self::PER_TYPE)
                    ->get();
            } catch (\Throwable) {
                continue;
            }

            foreach ($rows as $paper) {
                $publicId = (string) $paper->getAttribute('public_id');
                if ($publicId === '') {
                    continue;
                }
                try {
                    if (! $this->door->mayOpen($me, $documentType, $paper, checkBranch: true)) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }

                $out[] = [
                    'type' => $type,
                    'id' => $publicId,
                    'no' => $this->numberOf($paper),
                    'title' => $this->titleOf($type, $paper),
                    'amount' => $this->amountOf($paper),
                    'party' => $this->partyOf($paper),
                ];

                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    public function numberOf(Model $paper): string
    {
        if ($paper instanceof Drillable) {
            return $paper->drillDocumentNo();
        }

        foreach (['document_no', 'number', 'voucher_no', 'code'] as $field) {
            $v = $paper->getAttribute($field);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return '#'.$paper->getKey();
    }

    public function titleOf(string $type, ?Model $paper): string
    {
        $key = 'messaging::paper.'.$type;
        $word = __($key);

        if ($word !== $key) {
            return $word;
        }

        if ($paper instanceof Drillable) {
            return trim(str_replace($paper->drillDocumentNo(), '', $paper->drillLabel())) ?: $type;
        }

        return $type;
    }

    /**
     * The card for one message, as `$viewer` sees it (contract §1 `paper`).
     *
     * @return array<string, mixed>
     */
    public function card(Message $message, User $viewer): array
    {
        $type = (string) $message->paper_type;
        $id = (string) $message->paper_id;
        $found = $this->find($type, $id);
        $open = $found !== null && $this->mayOpen($viewer, $type, $id);
        $paper = $found[2] ?? null;

        return [
            'type' => $type,
            'id' => $id,
            'no' => (string) ($message->paper_no ?? ($paper ? $this->numberOf($paper) : '')),
            'title' => $this->titleOf($type, $paper),
            'amount' => $open && $paper ? $this->amountOf($paper) : '',
            'party' => $open && $paper ? $this->partyOf($paper) : '',
            'status' => $open && $paper ? $this->statusOf($paper) : '',
            'can_open' => $open,
            'path' => null,
            // ⓘ web only — the paper's own page; null when it cannot be opened
            'web_url' => $open && $paper instanceof Drillable ? $this->webUrl($paper) : null,
        ];
    }

    private function amountOf(Model $paper): string
    {
        foreach (['grand_total', 'total_amount', 'net_total', 'total', 'amount'] as $field) {
            $v = $paper->getAttribute($field);
            if ($v !== null && is_numeric((string) $v)) {
                return '৳'.Money::format((string) $v, 0);
            }
        }

        return '';
    }

    private function partyOf(Model $paper): string
    {
        foreach (['customer', 'supplier', 'party', 'account'] as $relation) {
            if (! method_exists($paper, $relation)) {
                continue;
            }
            try {
                $related = $paper->{$relation};
            } catch (\Throwable) {
                continue;
            }
            if ($related instanceof Model) {
                foreach (['name', 'name_en', 'name_bn'] as $field) {
                    $v = $related->getAttribute($field);
                    if (is_string($v) && $v !== '') {
                        return $v;
                    }
                }
            }
        }

        return '';
    }

    private function statusOf(Model $paper): string
    {
        $status = $paper->getAttribute('status');

        if ($status instanceof \BackedEnum) {
            $status = $status->value;
        }

        if (! is_string($status) || $status === '') {
            return '';
        }

        $key = 'core.status.'.$status;
        $word = __($key);

        return $word === $key ? '' : $word;
    }

    private function webUrl(Drillable $paper): ?string
    {
        try {
            $route = $paper->drillRoute();
            $name = $route['name'] ?? $route[0] ?? null;
            $params = $route['params'] ?? $route[1] ?? [];

            return is_string($name) ? route($name, $params) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
