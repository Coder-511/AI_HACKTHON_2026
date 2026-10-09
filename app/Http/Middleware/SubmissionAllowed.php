<?php

namespace App\Http\Middleware;

use App\Services\SubmissionAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SubmissionAllowed
{
    public function __construct(private SubmissionAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->access->ensure($request->user());

        return $next($request);
    }
}
