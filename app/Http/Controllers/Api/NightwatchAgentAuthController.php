<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class NightwatchAgentAuthController extends Controller
{
    /**
     * Issue a short-lived ingest token to the upstream Nightwatch agent.
     *
     * The agent calls this on startup, then again every {refresh_in} seconds.
     * The response shape is the contract enforced by agent.phar's
     * IngestDetailsRepository::parseResponse.
     *
     * The token records the version of the key the agent authenticated with, so
     * rotating the key revokes the tokens issued under it.
     */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('watch_project');

        $expiresIn = 3600;
        $refreshIn = 1800;

        $token = Crypt::encryptString(json_encode([
            'project_id' => $project->id,
            'expires_at' => time() + $expiresIn,
            'key_version' => $project->apiKeyVersionFor((string) $request->bearerToken()),
        ], JSON_THROW_ON_ERROR));

        return response()->json([
            'token' => $token,
            'expires_in' => $expiresIn,
            'refresh_in' => $refreshIn,
            'ingest_url' => route('api.nightwatch.ingest'),
        ]);
    }
}
