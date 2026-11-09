<?php

namespace App\Services\Detections;

class SubmissionTask
{
    public const VERSION = 'tasks-v12';

    public const MEDIA_INSTRUCTION = 'Check this image or video for AI generation and explain the relevant visible details. Describe only clear, specific irregularities or image-quality limitations that can actually be observed. Explain how those details relate to the assessment without claiming they prove how the media was created. Keep the explanation focused on the uploaded media, use plain language, and state any uncertainty without inventing artifacts or discussing technical system processes';

    public const CLAIM_INSTRUCTION = 'Fact-check the claims in the supplied content and explain what the evidence supports or contradicts. Preserve names, dates, locations, numbers and important qualifiers, and assess separate claims individually. Search broadly, prioritizing original records, relevant research and established sources, then consider other credible reporting when needed. Compare the actual evidence, cite the sources used, and explain missing context, conflicting information or uncertainty. Do not label a claim false simply because supporting evidence could not be found';

    public const CONTEXT_INSTRUCTION = 'Check the original date, place and context of the supplied content. Compare its caption and claims with the earliest relevant publication or available records, and check whether it is being presented as current or linked to the correct event. Identify any reused footage, outdated information, miscaptioning or missing context supported by the evidence. Explain what changed or was omitted, cite the relevant sources, and clearly state any details that cannot be confirmed';

    public const SOURCES_INSTRUCTION = 'Fact-check this content using original records and other relevant sources. Prioritize directly relevant research, official records, established institutions and matching published fact-checks, while keeping the search open to other useful reporting. Compare what each source actually establishes for the exact claim, including its date and scope, and distinguish independent evidence from repeated reporting. Provide source links, explain the evidence for and against the claim, and identify disagreements, inaccessible records or gaps that prevent a firm conclusion';

    private const INSTRUCTIONS = [
        self::MEDIA_INSTRUCTION,
        self::CLAIM_INSTRUCTION,
        self::CONTEXT_INSTRUCTION,
        self::SOURCES_INSTRUCTION,
        // Previously shipped shortcuts still appear in saved submissions.
        'Check this image or video for AI generation and explain visible details',
        'Fact-check the claims in this content and explain the evidence',
        'Check the original date, place and context of this content',
        'Fact-check this content using original records and other relevant sources',
        'Check the supplied image or video for AI generation and explain relevant visible details in plain language without inventing visual evidence',
        'Fact-check the factual claims in the supplied text, image, video, document or link using reliable evidence, preserve their exact meaning and scope, and explain the verdict with sources and any uncertainty',
        'Check whether the supplied text, image, video, document or link is shown with the correct date, location and context, and explain any outdated, reused, miscaptioned or misleading information',
        'Fact-check the supplied content using original records and relevant published fact-checks, compare the evidence for and against each claim, and cite the sources that actually address it',
        'Check the supplied image or video for AI generation, explain the recorded detector finding and its limits, and do not invent visual evidence or replace the answer with a scene description',
        'Fact-check the exact claim in the supplied content against reliable sources, preserve its names, dates and qualifiers, and explain what the evidence supports or contradicts',
        'Check the original date, place and context of the supplied content, and explain whether it is current, reused, miscaptioned or missing important context',
        'Fact-check the supplied content using matching fact-checks and primary sources, and explain which references directly address the claim rather than merely discussing the same topic',
        'Check whether this image or video shows signs of AI generation or manipulation, and explain what the detector can and cannot establish',
        'Fact-check the exact claim using reliable evidence, including its names, dates, places, numbers, and qualifiers, and explain the verdict and uncertainty',
        'Check whether this content is current and shown in its original date, place, event, and context, and explain what changed or was omitted',
        'Find matching fact-checks and primary sources, compare what each one actually establishes, and explain any disagreement',

        'Is this image or video AI-generated or manipulated',
        'Is the claim in this post true',
        'Has this claim already been fact-checked, and what ratings did trusted sources give it',
        'Fact-check this claim against trusted public sources and explain the evidence',
    ];

    public static function presetInstructions(): array
    {
        return self::INSTRUCTIONS;
    }

    public static function isPresetInstruction(?string $text): bool
    {
        $text = mb_strtolower(rtrim(trim((string) $text), '.!?'));
        foreach (self::INSTRUCTIONS as $instruction) {
            if ($text === mb_strtolower($instruction)) {
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
        // Open factual questions request a value or identity, not a yes/no
        // proposition. Preserve the exact question instead of adopting a
        // different assertion printed on its accompanying image.
        if (self::isInformationQuestion($question)) {
            return trim($question, " \t\n\r\0\x0B.!?");
        }
        $candidates = [];
        // Keep user spellings and qualifiers. The planner may expand search
        // variants, but this boundary must not invent or correct an event.
        if (preg_match_all('/\b(?:check|verify|confirm|fact[- ]check)\s+(?:if|whether|that)\s+(.+)/iu', trim($question), $matches)) {
            $candidates = $matches[1];
        }
        if (preg_match('/^(?:please\s+)*(?:is|was|are|were|did|does|has|have|can|could)\s+(.+)/iu', trim($question), $match)) {
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
        return '/^(?:(?:how\s+(?:much|many)|who|when|where|which|what|magkano|ilan|sino|kailan|saan|ano|alin)\b[^\n?]{0,500}(?:\?|$)|(?:is|was|are|were|has|did|do|does|can|could)\b[^\n?]{0,220}(?:\?|$)|(?:please\s+)*(?:fact[- ]check|check|verify|confirm|analy[sz]e|assess|detect|determine|screen|examine|identify|tell me|find out|find related fact[- ]checks)\b[^\n.!?]{0,220}(?:[.!?]|$)|(?:ai(?:[- ]generated)?\s+ba|gawa\s+ba|ginawa\s+ba|totoo\s+ba|peke\s+ba|na[- ]?edit\s+ba|binago\s+ba|mapanlinlang\s+ba|suriin|pakisuri|paki[- ]?check)\b[^\n.!?]{0,220}(?:[.!?]|$))\s*/iu';
    }

    /** User instructions only: never classify intent from OCR or fetched articles. */
    private static function requestSegments(array $context): array
    {
        $segments = [];
        foreach (['caption_text', 'notes'] as $field) {
            $text = trim((string) ($context[$field] ?? ''));
            if (self::isPresetInstruction($text)) {
                continue;
            }
            // A guide can remain selected while the user types a different request.
            foreach (self::INSTRUCTIONS as $instruction) {
                $text = preg_replace('/^'.preg_quote($instruction, '/').'(?:[.!?:]+\s*|\s*\R\s*)/iu', '', $text);
            }
            $segments = [...$segments, ...(preg_split('/(?<=[.!?])\s+|[\r\n]+|;\s*|\s+(?:and|also)\s+(?=(?:please\s+)?(?:check|verify|confirm|fact[- ]check|analy[sz]e|detect|determine|is|was)\b)/iu', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [])];
        }

        return array_values(array_filter(array_map('trim', $segments)));
    }

    private static function isMediaRequest(string $text): bool
    {
        $text = preg_replace('/\bA\.I\./iu', 'AI', trim($text));
        $origin = '(?:ai(?:[-\s]*(?:generated|generation|made|created))?|artificial[-\s]+intelligence|computer[-\s]+generated|deep[-\s]?fake|synthetic)';
        $words = '(?:(?:if|whether|that|this|these|those|it|the|my|an?|uploaded|attached|supplied|provided|image|photo(?:graph)?|picture|video|media|content|file|upload|clip|footage|post|is|was|are|were|be|been|has|looks?|seems?|like|really|actually|possibly|likely|not|made|created|generated|by|with|using|for|any|signs|of|or|you|think|ito|itong|iyan|iyon|ba|bang|kung|ng)\s+)*';
        $words = '(?:(?:if|whether|that)\s+)?(?:(?:this|that|it|these|those|(?:the|my|an?)\s+(?:uploaded|attached|supplied|provided|image|photo|picture|video|media|file|clip))\b[^.!?\n]{0,160}\s+|'.$words.')';
        $end = '(?=\s*(?:$|[?.!,;:]|and\s+(?:explain|describe|give|provide|tell|check|verify|fact[- ]check)\b|or\s+(?:not|real|human|edited|editing|manipulated|manipulation)\b|(?:image|photo|picture|video|content|ito|iyan|ba)\b\s*[?.!]*\s*$|(?:in|of|for)\s+(?:(?:this|the|my|uploaded|attached|supplied)\s+)*(?:image|photo|picture|video|content|media)\b))';
        if (preg_match('/^(?:ai(?:[- ]generated)?\s+ba|(?:gawa|ginawa)\s+ba\b[^.!?\n]*\bai\b|did\s+(?:ai|artificial intelligence)\s+(?:generate|make|create)\b|ai\s+(?:check|detection|analysis|screening)\b)/iu', $text)
            || preg_match('/^(?:please\s+|kindly\s+)*(?:(?:is|was|are|were|could|can|might|does)\s+|do\s+you\s+think\s+)'.$words.$origin.$end.'/iu', $text)
            || preg_match('/^'.$origin.$end.'/iu', $text)) {
            return true;
        }
        if (preg_match_all('/\b(?:check|verify|confirm|analy[sz]e|assess|detect|determine|screen|examine|identify|test|tell\s+me|find\s+out|paki[- ]?check|pakisuri|suriin)\s+'.$words.$origin.$end.'/iu', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$match, $offset]) {
                $before = substr($text, 0, $offset);
                if (! preg_match('/(?:do\s+not|don[\x{2019}\x{0027}]?t|never|skip|avoid|without|huwag|wag)\s*$/iu', $before)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function isInformationQuestion(string $question): bool
    {
        return (bool) preg_match('/^(?:how\s+(?:much|many)|who|when|where|which|what|magkano|ilan|sino|kailan|saan|ano|alin)\b/iu', trim($question));
    }

    public static function question(array $context): string
    {
        $segments = self::requestSegments($context);
        if (collect($segments)->contains(fn ($segment) => self::isMediaRequest($segment))) {
            return implode(' ', array_filter($segments, fn ($segment) => self::isMediaRequest($segment)
                || preg_match(self::questionPattern(), $segment)));
        }
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
        // The shipped AI-screening shortcut names the detector scope; it does
        // not ask for a separate editing verdict. Preserve explicit mixed tasks.
        $preset = rtrim(trim((string) ($context['caption_text'] ?? '')), '.?');
        if (trim((string) ($context['notes'] ?? '')) === ''
            && in_array($preset, [self::MEDIA_INSTRUCTION, 'Check whether this image or video shows signs of AI generation or manipulation, and explain what the detector can and cannot establish', 'Is this image or video AI-generated or manipulated'], true)) {
            return ['media'];
        }
        $segments = self::requestSegments($context);
        $mediaSegments = array_filter($segments, fn ($segment) => self::isMediaRequest($segment));
        $found = [];
        if ($mediaSegments !== [] || self::isMediaRequest($question)) {
            $found['media'] = -1;
            // Generic guide wording and "fact-check if AI-generated" are one
            // origin request. Only a separate factual/context request adds research.
            $question = implode(' ', array_filter(array_diff_key($segments, $mediaSegments),
                fn ($segment) => preg_match(self::questionPattern(), $segment)));
        }
        foreach (['manipulation' => '/\b(?:edited|editing|altered|manipulation|manipulated|photoshopped|doctored|spliced|na[- ]?edit|inedit|binago)\b/iu',
            'context' => '/\b(?:date|dated|location|reused|recycled|outdated|current|misleading|misrepresented|misattributed|context|satire|satirical|parody|mapanlinlang|konteksto|luma)\b|\b(?:old|older)\s+(?:image|photo|picture|video|footage|post|news|report)\b|\b(?:wrong|original)\s+place\b/iu',
            'claim' => '/\b(?:true|truth|statement|factual|claim|fact[- ]check|accurate|happen(?:ed)?|occurred|totoo|katotohanan)\b/iu'] as $task => $pattern) {
            if (preg_match($pattern, $question, $match, PREG_OFFSET_CAPTURE)) {
                $found[$task] = $match[0][1];
            }
        }
        if ($mediaSegments !== [] && preg_match('/\b(?:edited|editing|altered|manipulated|manipulation|photoshopped|doctored)\b/iu', implode(' ', $mediaSegments))) {
            $found['manipulation'] = 0;
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
