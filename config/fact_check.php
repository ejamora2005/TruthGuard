<?php

return [
    'verdict_calibration' => [
        'enabled' => env('EVIDENCE_CALIBRATION_ENABLED', false),
        'path' => env('EVIDENCE_CALIBRATION_PATH', ''),
        'sha256' => env('EVIDENCE_CALIBRATION_SHA256', ''),
    ],
    'enhanced_enabled' => env('FACT_CHECK_ENHANCED_ENABLED', true),
    'queue_enabled' => env('FACT_CHECK_QUEUE_ENABLED', false),
    'result_reuse' => [
        'enabled' => env('FACT_CHECK_RESULT_REUSE_ENABLED', true),
        'generation' => env('FACT_CHECK_RESULT_REUSE_GENERATION', 'fresh-start-2026-10-16-v2'),
        'media_seconds' => (int) env('FACT_CHECK_RESULT_REUSE_MEDIA_SECONDS', 604800),
        'claim_seconds' => (int) env('FACT_CHECK_RESULT_REUSE_CLAIM_SECONDS', 86400),
        'unresolved_seconds' => (int) env('FACT_CHECK_RESULT_REUSE_UNRESOLVED_SECONDS', 300),
        'wait_seconds' => 240,
    ],
    'openai_retry' => [
        'max_retries' => (int) env('FACT_CHECK_OPENAI_MAX_RETRIES', 1),
        'max_delay_seconds' => (float) env('FACT_CHECK_OPENAI_MAX_RETRY_DELAY', 3),
    ],
    'web_search' => [
        'enabled' => env('OPENAI_WEB_SEARCH_ENABLED', env('OPENAI_ANALYSIS_ENABLED', true)),
        'model' => env('OPENAI_WEB_SEARCH_MODEL', env('OPENAI_MODEL', 'gpt-5.4')),
        'timeout' => (int) env('OPENAI_WEB_SEARCH_TIMEOUT', 20),
        'max_calls' => (int) env('OPENAI_WEB_SEARCH_MAX_CALLS', 1),
        'max_tool_calls' => (int) env('OPENAI_WEB_SEARCH_MAX_TOOL_CALLS', 1),
        'context_size' => env('OPENAI_WEB_SEARCH_CONTEXT_SIZE', 'low'),
        'max_candidates' => (int) env('OPENAI_WEB_SEARCH_MAX_CANDIDATES', 5),
        'max_output_tokens' => (int) env('OPENAI_WEB_SEARCH_MAX_OUTPUT_TOKENS', 350),
    ],
    'retrieval' => [
        'max_candidates' => (int) env('FACT_CHECK_MAX_CANDIDATES', 16),
        'max_pages' => (int) env('FACT_CHECK_MAX_PAGES', 6),
        'max_search_requests' => (int) env('FACT_CHECK_MAX_SEARCH_REQUESTS', 6),
        'max_rounds' => (int) env('FACT_CHECK_MAX_ROUNDS', 1),
        'seconds' => (int) env('FACT_CHECK_RETRIEVAL_SECONDS', 25),
        'timeout' => (int) env('FACT_CHECK_FETCH_TIMEOUT', 5),
        'max_bytes' => (int) env('FACT_CHECK_MAX_BYTES', 2097152),
        'max_text_chars' => (int) env('FACT_CHECK_MAX_TEXT_CHARS', 9000),
        'cache_seconds' => (int) env('FACT_CHECK_CACHE_SECONDS', 300),
        'respect_robots' => true,
        'google_languages' => ['en', 'fil'],
        'google_pages' => (int) env('FACT_CHECK_GOOGLE_PAGES', 2),
        // Google's maxAgeDays is relative to now, not a historical cutoff.
        'google_max_age_days' => (int) env('FACT_CHECK_GOOGLE_MAX_AGE_DAYS', 0),
    ],
    'max_claims' => (int) env('FACT_CHECK_MAX_CLAIMS', 3),
    'max_input_chars' => 24000,
    'max_model_calls' => (int) env('FACT_CHECK_MAX_MODEL_CALLS', 3),
    'max_model_input_bytes' => (int) env('FACT_CHECK_MAX_MODEL_INPUT_BYTES', 22000),
    'model_output_tokens' => (int) env('FACT_CHECK_MODEL_OUTPUT_TOKENS', 1600),
    'plan_output_tokens' => (int) env('FACT_CHECK_PLAN_OUTPUT_TOKENS', 700),
    'pdf_binary' => env('FACT_CHECK_PDF_BINARY', 'pdftotext'),
    'media' => [
        'python_binary' => env('FACT_CHECK_MEDIA_PYTHON_BINARY', env('DEEPFAKE_MODEL_PYTHON_BINARY', 'python')),
        'timeout' => 30,
    ],
    // Hosts are checked at a domain boundary, never by publisher display name.
    'publishers' => [
        'official_government' => ['gov.ph', 'gov', 'gov.uk'],
        'institution' => ['who.int', 'un.org', 'unicef.org', 'unesco.org', 'worldbank.org', 'imf.org'],
        'research' => ['nature.com', 'science.org', 'thelancet.com', 'nejm.org'],
        'fact_check' => ['reuters.com', 'afp.com', 'apnews.com', 'verafiles.org', 'fullfact.org', 'factcheck.org', 'politifact.com', 'rappler.com', 'pressone.ph', 'tsek.ph', 'abs-cbn.com'],
        'reputable_news' => ['reuters.com', 'apnews.com', 'bbc.com', 'bbc.co.uk', 'gmanetwork.com', 'inquirer.net', 'philstar.com', 'abs-cbn.com'],
    ],
    'low_confidence_threshold' => 70,
];
