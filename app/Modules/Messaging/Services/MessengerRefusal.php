<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A 403 with a code the app understands — `{ error, message }` (contract §0).
 * ⓘ On the web the same refusal becomes a JSON answer too: the messages page talks to the server only by fetch.
 */
final class MessengerRefusal extends HttpException
{
    public function __construct(public readonly string $code_, string $message)
    {
        parent::__construct(403, $message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['error' => $this->code_, 'message' => $this->getMessage()], 403);
    }
}
