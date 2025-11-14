<?php

namespace LaraGram\Watchdog\Manager\Middleware;

use Closure;
use LaraGram\Request\Request;
use LaraGram\Request\Response;

class IsManagerAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\LaraGram\Request\Request): (\LaraGram\Request\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response|bool
    {
        $userId = $request->message->from->id ??
            $request->callback_query->from->id ??
            null;

        if ($userId !== null && in_array($userId, config('watchdog.manager.admins'))) {
            return $next($request);
        }

        return false;
    }
}
