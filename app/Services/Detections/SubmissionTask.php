<?php

namespace App\Services\Detections;

class SubmissionTask
{
    public const VERSION = 'tasks-v5';

    public const MEDIA_INSTRUCTION = 'Check whether this image or video shows signs of AI generation or manipulation';

    private const INSTRUCTIONS = [
        self::MEDIA_INSTRUCTION,
        'Fact-check this claim against trusted public sources and explain the evidence',
        'Find related fact-checks for this upload and show their source ratings',
    ];

    public static function isPresetInstruction(?string $text): bool
    {
        $text = trim((string) $text);
        foreach (self::INSTRUCTIONS as $instruction) {
            if ($text === $instruction.'.') {
                return true;
            }
        }

        return false;
    }

    public static function claimText(?string $text): string
    {
        $text = trim((string) $text);
        foreach (self::INSTRUCTIONS as $instruction) {
            $text = preg_replace('/^'.preg_quote($instruction, '/').'(?:[.!?:]+\s*|$)/iu', '', $text);
        }

        // A request describes what to do; only its accompanying content is a claim.
        do {
            $previous = $text;
            if (preg_match(self::questionPattern(), $text, $match)) {
                $proposition = self::questionClaim($match[0]);
                $text = trim($proposition."\n".substr($text, strlen($match[0])));
            }
        } while ($previous !== $text);

        return trim($text);
    }

    /** Preserve the verbatim proposition inside an event-verification request. */
    public static function questionClaim(string $question): string
    {
        $candidates = [];
        // Keep user spellings and qualifiers. The planner may expand search
        // variants, but this boundary must not invent or correct an event.
        if (preg_match_all('/\b(?:check|verify|confirm|fact[- ]check)\s+(?:if|whether|that)\s+(.+)/iu', trim($question), $matches)) {
            $candidates = $matches[1];
        }
        if (preg_match('/^(?:please\s+)?(?:is|was|are|were|did|does|has|have|can|could)\s+(.+)/iu', trim($question), $match)) {
            $candidates[] = $match[1];
        }
        foreach ($candidates as $candidate) {
            $candidate = preg_replace('/^(?:this|it|(?:this|the)\s+(?:image|photo|picture|video|statement|claim))\s+(?:(?:is|was)\s+)?(?:really\s+)?(?:real|true|accurate)\s+(?:that\s+)?/iu', '', $candidate);
            $candidate = trim($candidate, " \t\n\r\0\x0B.!?");
            if (preg_match('/^(?:this|it|that|these|those|or|and|you|we|ai|artificial intelligence)\b|^(?:the|this)\s+(?:image|photo|picture|video|statement|claim)\b/iu', $candidate)
                || count(preg_split('/\s+/u', $candidate, -1, PREG_SPLIT_NO_EMPTY)) < 3) {
                continue;
            }

            return $candidate;
        }

        return '';
    }

    private static function questionPattern(): string
    {
        return '/^(?:(?:is|was|are|were|has|did|do|does|can|could)\b[^\n?]{0,220}(?:\?|$)|(?:please\s+)?(?:fact[- ]check|check|verify|tell me|find related fact[- ]checks)\b[^\n.!?]{0,220}(?:[.!?]|$)|(?:ai(?:[- ]generated)?\s+ba|gawa\s+ba|ginawa\s+ba|totoo\s+ba|peke\s+ba|na[- ]?edit\s+ba|binago\s+ba|mapanlinlang\s+ba|suriin|pakisuri|paki[- ]?check)\b[^\n.!?]{0,220}(?:[.!?]|$))\s*/iu';
    }

    public static function question(array $context): string
    {
        foreach (['caption_text', 'notes'] as $field) {
            $text = trim((string) ($context[$field] ?? ''));
            $questions = [];
            while (preg_match(self::questionPattern(), $text, $matches)) {
                $questions[] = trim($matches[0]);
                $text = trim(substr($text, strlen($matches[0])));
            }
            if ($questions !== []) {
                return implode(' ', $questions);
            }
        }

        return '';
    }

    public static function explicitTask(array $context): ?string
    {
        return self::tasks($context)[0] ?? null;
    }

    public static function tasks(array $context): array
    {
        $question = self::question($context);
        $found = [];
        foreach (['media' => '/ai[- ](?:generation|generated|made)|(?:generated|made|created) (?:by|with|using) (?:ai|artificial intelligence)|did ai|(?:this|it|image|photo|picture|video)\s+(?:(?:is|was|be|looks?)\s+|made\s+(?:by|with)\s+)?ai\b|\bai\s+(?:or not|ba)\b|(?:gawa|ginawa)\b.*\bai\b|computer[- ]generated|\bsynthetic\b|\bdeepfake\b/iu',
            'manipulation' => '/\b(?:edited|editing|altered|manipulation|manipulated|photoshopped|doctored|spliced|na[- ]?edit|inedit|binago)\b/iu',
            'context' => '/\b(?:date|dated|location|reused|recycled|outdated|current|misleading|misrepresented|misattributed|context|satire|satirical|parody|mapanlinlang|konteksto|luma)\b|\b(?:old|older)\s+(?:image|photo|picture|video|footage|post|news|report)\b|\b(?:wrong|original)\s+place\b/iu',
            'claim' => '/\b(?:true|truth|statement|factual|claim|fact[- ]check|accurate|happen(?:ed)?|occurred|totoo|katotohanan)\b/iu'] as $task => $pattern) {
            if (preg_match($pattern, $question, $match, PREG_OFFSET_CAPTURE)) {
                $found[$task] = $match[0][1];
            }
        }
        if (($proposition = self::questionClaim($question)) !== '' && ! isset($found['claim'])) {
            $found['claim'] = strpos($question, $proposition) ?: 0;
        }
        asort($found);

        // A misleading or out-of-context question also needs the underlying
        // statement checked. The two findings must remain separate: a real
        // photograph can still carry an inaccurate or misleading caption.
        if (isset($found['context']) && ! isset($found['claim'])) {
            $found['claim'] = $found['context'] + 0.1;
        }
        asort($found);

        return array_keys($found);
    }

    /** The persisted task decision is shared by detector selection and composition. */
    public static function resolve(array $context, ?array $extraction = null): array
    {
        $primary = self::primary($context, $extraction);

        return ['question' => self::question($context), 'tasks' => self::tasks($context) ?: [$primary],
            'selected_mode' => $context['analysis_task'] ?? 'auto', 'resolved_task' => $primary,
            'resolver_version' => self::VERSION];
    }

    public static function primary(array $context, ?array $extraction = null): string
    {
        $tasks = self::tasks($context);
        // "Fact-check this misleading caption" is a context request even though
        // its generic instruction also contains the word "fact-check".
        if (in_array('context', $tasks, true) && in_array('claim', $tasks, true)
            && ($tasks[0] ?? null) === 'claim') {
            return 'context';
        }
        if ($explicit = self::explicitTask($context)) {
            return $explicit;
        }
        if (in_array($context['analysis_task'] ?? '', ['media', 'claim', 'context', 'manipulation'], true)) {
            return $context['analysis_task'];
        }
        // A general verification question ("was this real?", "is this legit?")
        // asks about the submitted content. It does not request an AI detector,
        // even when OCR fails or finds no readable claim. Explicit origin/edit
        // requests and the user's selected mode have already been handled.
        if (self::question($context) !== '') {
            return 'claim';
        }
        if (! in_array($context['media_type'] ?? '', ['image', 'video'], true)) {
            return 'claim';
        }
        $caption = trim((string) ($context['caption_text'] ?? ''));
        if (str_starts_with(strtolower($caption), strtolower(self::MEDIA_INSTRUCTION))) {
            return 'media';
        }
        // Auto-selection waits for OCR: a news screenshot is a factual submission even without a caption.
        if ($extraction !== null && trim($extraction['analysis_text'] ?? '') !== '') {
            return 'claim';
        }

        return self::claimText($caption) === '' && blank($context['source_url'] ?? null) ? 'media' : 'claim';
    }
}
