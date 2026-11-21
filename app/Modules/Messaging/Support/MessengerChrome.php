<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use App\Core\Contracts\Drillable;
use App\Core\Services\PhonePaperDoor;
use App\Core\Support\LookPreview;
use App\Core\Support\LookRegistry;
use App\Core\Support\Money;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/**
 * The messenger's pieces on every ABOS page (web sample M1UddRxK v7): the top-bar chat icon, the light poll, the
 * incoming-call popup, the call screen, and "বার্তায় পাঠান" in a paper's ⋯ row menu.
 *
 * ⛔ Only the three ABOS looks (rose "ABOS Smart", navy, blue). SAP (`tiles`) and Dynamic stay 100% their original —
 *    owner, 19–20 Nov 2026: nothing of this is even rendered there, so not one pixel can move.
 * ⓘ One answer per request (the row menu asks once per row).
 */
final class MessengerChrome
{
    /** ⓘ the three ABOS looks — the same three as [[Ui::FOLD_MENU_LOOKS]] */
    public const LOOKS = ['rose', 'navy', 'blue'];

    /** ⓘ the row the table is drawing right now — set by [[x-ui.table]] around its `:row-menu`, read by [[x-ui.row-actions]] */
    public const ROW = 'abos.row-menu.paper';

    private const ON = 'abos.messenger.chrome';

    private const TYPES = 'abos.messenger.types';

    /** Draw the messenger's pieces on this page? key + module door + an ABOS look. */
    public static function on(): bool
    {
        $attributes = request()->attributes;

        if (! $attributes->has(self::ON)) {
            $attributes->set(self::ON, self::decide());
        }

        return (bool) $attributes->get(self::ON);
    }

    public static function lookAllows(?string $chosenUi): bool
    {
        return in_array(LookRegistry::lookFor(LookPreview::orChosen($chosenUi)), self::LOOKS, true);
    }

    /**
     * The "বার্তায় পাঠান" row for this paper's ⋯ menu — or null when the paper is not one the phone print door knows.
     *
     * ⓘ Type = the model's class name exactly as [[PhonePaperDoor::printable()]] names it; id = the public_id. The summary
     *   line of the dialog (title · number · amount · party) comes only from what the row already holds — ⛔ no query per
     *   row (and lazy loading is refused on local).
     *
     * @return array<string, mixed>|null
     */
    public static function shareItem(mixed $paper): ?array
    {
        if (! $paper instanceof Model || ! self::on()) {
            return null;
        }

        $type = class_basename($paper);
        $known = self::types()[$type] ?? null;
        $publicId = $paper->getAttribute('public_id');

        if ($known !== $paper::class || ! is_string($publicId) || $publicId === '') {
            return null;
        }

        return [
            'label' => __('messaging::call.share.menu'),
            'icon' => 'chat',
            'share' => [
                'type' => $type,
                'id' => $publicId,
                'title' => self::titleOf($type, $paper),
                'no' => self::numberOf($paper),
                'amount' => self::amountOf($paper),
                'party' => self::partyOf($paper),
            ],
        ];
    }

    /**
     * Put the share row into a row menu: at the end of the "ছাপা ও পাঠানো" group; without that group, a new one just
     * before "কাজ"; without either, at the end.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function placeIn(array $items, array $share): array
    {
        $paperGroup = __('core.row_menu.group_paper');
        $workGroup = __('core.row_menu.group_work');
        $at = null;
        $inPaper = false;

        foreach ($items as $i => $item) {
            if (isset($item['group'])) {
                if ($inPaper) {
                    $at = $i;
                    break;
                }
                $inPaper = $item['group'] === $paperGroup;
            } elseif ($inPaper && ($item['line'] ?? false)) {
                $at = $i;
                break;
            }
        }

        if ($inPaper) {
            array_splice($items, $at ?? count($items), 0, [$share]);

            return $items;
        }

        foreach ($items as $i => $item) {
            if (($item['group'] ?? null) === $workGroup) {
                array_splice($items, $i, 0, [['group' => $paperGroup], $share]);

                return $items;
            }
        }

        $items[] = $share;

        return $items;
    }

    private static function decide(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! Route::has('messaging.index') || ! Route::has('messaging.x.unread')) {
            return false;
        }

        return self::lookAllows($user->ui) && $user->can('messaging.use');
    }

    /** @return array<string, class-string<Model>> type name → model class */
    private static function types(): array
    {
        $attributes = request()->attributes;

        if (! $attributes->has(self::TYPES)) {
            try {
                $types = array_map(fn (array $row): string => $row[1], app(PhonePaperDoor::class)->printable());
            } catch (\Throwable) {
                $types = [];
            }
            $attributes->set(self::TYPES, $types);
        }

        return $attributes->get(self::TYPES);
    }

    /**
     * ⓘ The card's own word first (`messaging::paper.<type>`, [[PaperCards::titleOf()]]), then the house's source name
     *   (`core.source.<drillSourceType>`). ⛔ Not `drillLabel()` — several load a relation, which is a query per row.
     */
    private static function titleOf(string $type, Model $paper): string
    {
        $key = 'messaging::paper.'.$type;
        $word = __($key);
        if (is_string($word) && $word !== $key) {
            return $word;
        }

        if ($paper instanceof Drillable) {
            $key = 'core.source.'.$paper::drillSourceType();
            $word = __($key);
            if (is_string($word) && $word !== $key) {
                return $word;
            }
        }

        return $type;
    }

    private static function numberOf(Model $paper): string
    {
        foreach (['document_no', 'number', 'voucher_no', 'code'] as $field) {
            $v = $paper->getAttribute($field);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return $paper instanceof Drillable ? $paper->drillDocumentNo() : '#'.$paper->getKey();
    }

    private static function amountOf(Model $paper): string
    {
        foreach (['grand_total', 'total_amount', 'net_total', 'total', 'amount'] as $field) {
            $v = $paper->getAttribute($field);
            if ($v !== null && is_numeric((string) $v)) {
                return '৳'.Money::format((string) $v, 0);
            }
        }

        return '';
    }

    /** ⓘ only a relation the list already loaded — never a query per row */
    private static function partyOf(Model $paper): string
    {
        foreach (['customer', 'supplier', 'party'] as $relation) {
            if (! $paper->relationLoaded($relation)) {
                continue;
            }
            $related = $paper->getRelation($relation);
            if ($related instanceof Model) {
                foreach (['name', 'name_'.app()->getLocale(), 'name_en', 'name_bn'] as $field) {
                    $v = $related->getAttribute($field);
                    if (is_string($v) && $v !== '') {
                        return $v;
                    }
                }
            }
        }

        return '';
    }
}
