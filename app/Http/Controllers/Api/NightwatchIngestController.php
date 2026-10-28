<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWatchEvent;
use App\Models\Project;
use App\Watch\NightwatchTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NightwatchIngestController extends Controller
{
    /**
     * Compressed bytes fed to zlib per step. One step can inflate to roughly
     * 1000x its size, so this bounds how far past the limit we can overshoot.
     */
    private const INFLATE_CHUNK_BYTES = 1024;

    public function __construct(private readonly NightwatchTranslator $translator) {}

    /**
     * Accept a gzipped batch of Nightwatch wire records, translate, and queue.
     */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('watch_project');

        $body = $this->readBody($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->error('Invalid JSON payload: '.$e->getMessage(), status: 400);
        }

        $records = $decoded['records'] ?? null;

        if (! is_array($records)) {
            return $this->error('Missing records array', status: 422);
        }

        $received = count($records);
        $maxRecords = (int) config('watch.ingest.max_records');

        if ($received > $maxRecords) {
            return $this->error("Too many records: {$received} exceeds the limit of {$maxRecords} per request", status: 413);
        }

        $queued = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $event = $this->translator->translate($record);

            if ($event === null) {
                continue;
            }

            ProcessWatchEvent::dispatch($project->id, $event);
            $queued++;
        }

        return response()->json([
            'records_received' => $received,
            'records_queued' => $queued,
        ]);
    }

    /**
     * Read the request body, enforcing the decoded size limit.
     *
     * Returns the body, or the error response to send back.
     */
    private function readBody(Request $request): string|JsonResponse
    {
        $raw = $request->getContent();

        if ($raw === '' || $raw === false) {
            return '';
        }

        $encoding = strtolower((string) $request->header('Content-Encoding', ''));

        if (str_contains($encoding, 'gzip')) {
            return $this->inflateBody($raw);
        }

        return strlen($raw) > $this->maxDecodedBytes() ? $this->payloadTooLarge() : $raw;
    }

    /**
     * Gunzip the body without ever inflating more than the limit allows.
     *
     * gzdecode()'s $max_length is a soft cap and returns false for both a
     * corrupt stream and an exceeded limit, so we inflate incrementally
     * instead and stop as soon as the output passes the limit. That makes the
     * outcomes distinct: too large is 413, bad or truncated gzip is 400.
     */
    private function inflateBody(string $compressed): string|JsonResponse
    {
        $maxBytes = $this->maxDecodedBytes();
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        $decoded = '';

        for ($offset = 0, $length = strlen($compressed); $offset < $length; $offset += self::INFLATE_CHUNK_BYTES) {
            $inflated = @inflate_add($context, substr($compressed, $offset, self::INFLATE_CHUNK_BYTES), ZLIB_NO_FLUSH);

            if ($inflated === false) {
                return $this->error('Unable to decode payload', status: 400);
            }

            $decoded .= $inflated;

            if (strlen($decoded) > $maxBytes) {
                return $this->payloadTooLarge();
            }
        }

        if (inflate_get_status($context) !== ZLIB_STREAM_END) {
            return $this->error('Unable to decode payload', status: 400);
        }

        return $decoded;
    }

    private function maxDecodedBytes(): int
    {
        return (int) config('watch.ingest.max_decoded_bytes');
    }

    private function payloadTooLarge(): JsonResponse
    {
        return $this->error("Payload too large: the decoded body exceeds {$this->maxDecodedBytes()} bytes", status: 413);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'stop' => false,
        ], $status);
    }
}
