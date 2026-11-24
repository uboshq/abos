<?php

declare(strict_types=1);

namespace App\Core\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ The paper exactly as it prints — to look at, never a print (owner, 24 Nov 2026: "অরিজিনাল যে ভাউচারটা আছে প্রিন্ট করলে যেটা
 * বের হবে সেটা দেখাবে").
 *
 * ⓘ It runs the paper's own print action ([[PaperTrail::DOCUMENT_ROUTES]]), so design, paper size, branch head, "খসড়া" and
 *   "বাতিল" marks are the print's own; there is no second template to drift.
 * ⛔ Everything that action writes (the print trail, the print count behind DUPLICATE) is rolled back: looking is not printing.
 * ⛔ It asks no key itself — the caller has already decided that this person may read this very paper.
 */
final class PaperPreview
{
    public function __construct(private readonly PhonePaperDoor $papers) {}

    /** The paper's print type, or null when it has no printed paper, or may not be printed yet (a draft bill, owner's rule). */
    public function typeOf(?object $document): ?string
    {
        if (! $document instanceof Model || (method_exists($document, 'isNotFinalYet') && $document->isNotFinalYet())) {
            return null;
        }

        [$type, $class] = $this->papers->printable()[class_basename($document)] ?? [null, null];

        return $type !== null && $document instanceof $class ? $type : null;
    }

    public function render(Model $document): Response
    {
        $type = $this->typeOf($document);
        $route = $type === null ? null : Route::getRoutes()->getByName(PaperTrail::DOCUMENT_ROUTES[$type]);

        abort_if($route === null, 404);

        // ⓘ a fresh request: no paper size, download or copy asked by the address — the owner's set paper, inline
        $request = Request::create(request()->url(), 'GET');
        $request->setUserResolver(fn () => request()->user());

        $controller = $route->getController();
        $method = $route->getActionMethod();

        DB::beginTransaction();

        try {
            /** @var Response $response */
            $response = method_exists($controller, 'callAction')
                ? $controller->callAction($method, [$request, $document])
                : $controller->{$method}($request, $document);
        } catch (ValidationException $e) {
            // ⓘ the print itself refuses (e.g. the fare is not set yet) — the window says why
            $response = response((string) collect($e->errors())->flatten()->first(), 422, ['Content-Type' => 'text/plain; charset=UTF-8']);
        } finally {
            DB::rollBack();
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
