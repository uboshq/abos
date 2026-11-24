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
    private static bool $looking = false;

    public function __construct(private readonly PhonePaperDoor $papers) {}

    /**
     * Is a paper being looked at, not printed? ⭐ "Not printed before approval", the reprint limit and DUPLICATE are rules of
     * printing; the signer looks exactly because it is not approved yet (owner, 24 Nov 2026: "আমি তো ছাপতে বলি নাই … ভিউটা দেখাইতে বলছি").
     */
    public static function looking(): bool
    {
        return self::$looking;
    }

    /** The paper's print type, or null when it has no printed paper. */
    public function typeOf(?object $document): ?string
    {
        if (! $document instanceof Model) {
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
        // ⓘ a one-action controller (the voucher's) names its class, not `__invoke`
        $method = method_exists($controller, $route->getActionMethod()) ? $route->getActionMethod() : '__invoke';

        DB::beginTransaction();
        self::$looking = true;

        try {
            /** @var Response $response */
            $response = method_exists($controller, 'callAction')
                ? $controller->callAction($method, [$request, $document])
                : $controller->{$method}($request, $document);
        } catch (ValidationException $e) {
            // ⓘ a refusal no look should meet — said in the window rather than a blank page
            $response = response((string) collect($e->errors())->flatten()->first(), 422, ['Content-Type' => 'text/plain; charset=UTF-8']);
        } finally {
            self::$looking = false;
            DB::rollBack();
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
