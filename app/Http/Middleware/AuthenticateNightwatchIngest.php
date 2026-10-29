<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateNightwatchIngest
{
    /**
     * Authenticate ingest requests using the short-lived token
     * issued by the /api/agent-auth endpoint.
     *
     * A token records the version of the API key it was issued for and stops
     * working once that key has been rotated away (see
     * `Project::acceptsApiKeyVersion()`).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->unauthorized('Missing ingest token');
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return $this->unauthorized('Invalid ingest token');
        }

        if (! is_array($payload) || ! isset($payload['project_id'], $payload['expires_at'])) {
            return $this->unauthorized('Malformed ingest token');
        }

        if ($payload['expires_at'] < time()) {
            return $this->unauthorized('Expired ingest token');
        }

        $project = Project::find($payload['project_id']);

        if (! $project) {
            return $this->unauthorized('Unknown project');
        }

        $keyVersion = isset($payload['key_version']) ? (int) $payload['key_version'] : null;

        if (! $project->acceptsApiKeyVersion($keyVersion)) {
            return $this->unauthorized('Revoked ingest token');
        }

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
