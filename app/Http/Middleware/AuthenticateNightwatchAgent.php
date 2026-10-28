<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateNightwatchAgent
{
    /**
     * Authenticate the upstream Nightwatch agent's /api/agent-auth call.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->unauthorized('Missing environment token');
        }

        $project = Project::findByApiKey($token);

        if (! $project) {
            return $this->unauthorized('Invalid environment token');
        }

        $project->recordApiKeyUse();

        $request->attributes->set('watch_project', $project);

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'message' => $message,
            'stop' => true,
        ], 401);
    }
}
