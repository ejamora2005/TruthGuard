<?php

namespace App\Services\Detections;

use App\Services\Detections\Evidence\ResponsesTransport;
use Illuminate\Http\Client\Response;

/** Explains a failed input-reading step, never an assessment of the claim. */
final class OpenAiExtractionFailure
{
    public static function reason(Response $response): string
    {
        return match (true) {
            ResponsesTransport::quotaExhausted($response) => 'quota_exhausted',
            $response->json('error.code') === 'unsupported_country_region_territory' => 'unsupported_region',
            $response->status() === 401 => 'authentication_failed',
            $response->status() === 403 => 'access_denied',
            $response->status() === 404 || $response->json('error.code') === 'model_not_found' => 'model_unavailable',
            $response->status() === 429 => 'rate_limited',
            $response->status() >= 500 => 'provider_unavailable',
            default => 'http_error',
        };
    }

    public static function recordedReason(array $extraction): ?string
    {
        $reason = $extraction['failure_reason'] ?? null;

        // Older records grouped permission denials with invalid credentials.
        return $reason === 'authentication_failed' && ($extraction['http_status'] ?? null) === 403 ? 'access_denied' : $reason;
    }

    public static function explanation(array $extraction): ?string
    {
        return match (self::recordedReason($extraction)) {
            'access_denied' => 'OpenAI denied access to the image-reading request (HTTP 403). No image text was extracted, so TruthGuard could not assess the claim. This is an API access problem, not a finding that the claim is true or false.',
            'model_unavailable' => 'The configured image-reading model is not available to this API project. No image text was extracted, so TruthGuard could not assess the claim. An administrator must enable the model or select an accessible model before retrying.',
            'authentication_failed' => 'OpenAI did not accept the API credentials used to read this image. The image text was not extracted, so no factual conclusion was reached. An administrator must check the API credentials before retrying.',
            'unsupported_region' => 'OpenAI could not accept the image-reading request from this region. No image text was extracted and no factual conclusion was reached.',
            'quota_exhausted' => 'OpenAI could not read this image because the API account had no remaining credits or had reached its billing limit. No image text was extracted and no factual conclusion was reached.',
            'not_configured' => 'Image reading was not available because OpenAI analysis was disabled or no API key was configured. No image text was extracted and no factual conclusion was reached.',
            'rate_limited' => 'The image-reading service temporarily limited this request. No image text was extracted and no factual conclusion was reached. The assessment can be retried after the service limit clears.',
            'timeout', 'network_failure', 'provider_unavailable' => 'The image-reading service could not complete this request. No image text was extracted and no factual conclusion was reached. The assessment can be retried when the service is available.',
            default => null,
        };
    }

    public static function action(array $extraction): string
    {
        return match (self::recordedReason($extraction)) {
            'access_denied', 'model_unavailable', 'authentication_failed', 'not_configured' => 'Ask an administrator to check the API key and model access, then retry this assessment.',
            'quota_exhausted' => 'Ask an administrator to restore API billing before retrying this assessment.',
            'unsupported_region' => 'Ask an administrator to check the API service availability for this deployment region.',
            default => 'Retry the assessment when the image-reading service is available.',
        };
    }
}
