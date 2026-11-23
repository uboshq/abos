<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\Print\PaperSize;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\PaperShareController;
use App\Models\DocumentShare;
use App\Models\PartyMessageTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Who may send a paper to its party, and with what — one place for the web share box, the log's "আবার পাঠান" and the
 * phone's doors (WhatsApp/SMS step 3, owner "ok" 23 Nov 2026, sample HUXqpfhxng8823eEFrvH54).
 *
 * The rules are the web share flow's ([[PaperShareController::authorizeAndPrepare()]]), unchanged:
 *   404  the link belongs to another company;
 *   403  the paper's web print route keys (an empty list is "unknown", and unknown stays shut);
 *   404  the paper is outside this person's reach — the print door's own route binding and the model's `view` policy
 *        ([[PaperShareController::visibleDocument()]]);
 *   410  a dead link — the party would get something that does not open;
 *   422  the template is not usable for that paper, or the number is not a mobile number.
 * ⛔ A second copy of these rules in the phone door would one day disagree with the web — so both ask this class.
 */
final class PartyMessageGuard
{
    public function __construct(private readonly PaperTrail $trail) {}

    /** ⛔ The paper's print keys — the person who may print a paper may send it (no separate "share" right). */
    public function maySend(string $documentType): void
    {
        $abilities = PaperTrail::abilitiesFor($documentType);

        abort_if($abilities === [], 403);

        foreach ($abilities as $ability) {
            Gate::authorize($ability);
        }
    }

    /** The paper, keys first, then as its own print door sees it (branch, warehouse, policy) — 404 outside the reach. */
    public function paper(string $documentType, int $documentId): Model
    {
        $this->maySend($documentType);

        return PaperShareController::visibleDocument($documentType, $documentId);
    }

    /**
     * ⭐ The first half of [[PaperShareController::authorizeAndPrepare()]] — the share link's paper, in the same order.
     */
    public function forShare(DocumentShare $share): Model
    {
        abort_unless((int) $share->company_id === (int) CompanyContext::id(), 404);

        $document = $this->paper((string) $share->document_type, (int) $share->document_id);

        // ⛔ a dead link sent to a party is something that does not open
        abort_unless($share->isAlive(), 410);

        return $document;
    }

    /**
     * ⭐ The second half of [[PaperShareController::authorizeAndPrepare()]] — the chosen template, language and number.
     * ⓘ The template must be one the box shows for that channel and paper ([[PartyMessages::typesFor()]]).
     * ⓘ The text is never taken from the screen: it is rendered on the server from the template ([[PartyMessages::record()]]).
     *
     * @param  array<string, mixed>  $input
     * @return array{0: PartyMessageTemplate, 1: string, 2: string} [template, phone (international digits), locale]
     *
     * @throws ValidationException
     */
    public function choice(array $input, Model $document, DocumentShare $share, string $channel): array
    {
        $data = Validator::make($input, [
            'template_id' => ['required', 'integer'],
            'locale' => ['required', Rule::in(['bn', 'en'])],
            'phone' => ['required', 'string', 'max:30'],
        ])->validate();

        $template = PartyMessageTemplate::query()
            ->usableFor($channel, app(PartyMessages::class)->typesFor($document, $share))
            ->whereKey((int) $data['template_id'])
            ->first();

        $phone = PartyMessages::phone((string) $data['phone']);

        if ($template === null || $phone === null) {
            throw ValidationException::withMessages(array_filter([
                'template_id' => $template === null ? __('core.print.wa_template_gone') : null,
                'phone' => $phone === null ? __('core.print.wa_phone_bad') : null,
            ]));
        }

        return [$template, $phone, (string) $data['locale']];
    }

    /**
     * The paper's living share link — the one the web made, or a new one (the `{link}` in every template).
     *
     * ⓘ Reuses any living link of that paper that is bound to its own document ([[PaperTrail::isBoundToItsDocument()]]);
     *   otherwise a new one on the asked paper size, else A4 — what [[PaperTrail::share()]] makes for the web's button.
     * ⛔ Call only after [[paper()]]: the link opens the paper without a sign-in.
     */
    public function shareFor(string $documentType, Model $document, ?string $paper = null): DocumentShare
    {
        $routeName = PaperTrail::DOCUMENT_ROUTES[$documentType] ?? abort(404);
        $params = PaperTrail::routeParamsFor($documentType, (int) $document->getKey()) ?? abort(404);
        $paper = PaperSize::chosen($paper, null);

        $alive = DocumentShare::query()
            ->alive()
            ->where('route_name', $routeName)
            ->where('document_type', $documentType)
            ->where('document_id', (int) $document->getKey())
            ->orderByRaw('paper = ? DESC', [$paper])
            ->latest('id')
            ->get()
            ->first(fn (DocumentShare $share): bool => PaperTrail::isBoundToItsDocument($share));

        if ($alive !== null) {
            return $alive;
        }

        $number = $document->getAttribute('document_no');

        return $this->trail->share(
            routeName: $routeName,
            routeParams: $params,
            documentType: $documentType,
            documentId: (int) $document->getKey(),
            paper: $paper,
            documentNo: is_string($number) && $number !== '' ? $number : null,
        );
    }
}
