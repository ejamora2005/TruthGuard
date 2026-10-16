<?php

namespace App\Services\Detections\Evidence;

use App\Services\Detections\OpenAiDetectionReportService;
use Illuminate\Http\UploadedFile;

class SubmissionExtractor
{
    public function __construct(private readonly OpenAiDetectionReportService $vision, private readonly SafeArticleFetcher $fetcher) {}

    public function extract(array $context, ?UploadedFile $file, RetrievalBudget $budget): array
    {
        $text = trim((string) ($context['caption_text'] ?? ''));
        $notes = trim((string) ($context['notes'] ?? ''));
        $record = ['submitted_text' => $text, 'submitted_notes' => $notes, 'visible_text' => '', 'inferred_claim' => '',
            'article_text' => '', 'status' => 'completed', 'limitations' => [], 'input_scope' => 'submitted text', 'source_record' => null];
        if ($file && in_array($context['media_type'] ?? '', ['image', 'video'], true)) {
            $this->vision->extractClaimText($context, $file);
            $record = array_replace($record, $this->vision->extractionDetails());
            $record['input_scope'] = ($context['media_type'] === 'video') ? 'sampled video frames and supplied caption; no audio transcript' : 'visible image text and supplied caption';
            $record['limitations'][] = 'Image text is machine-transcribed and may contain OCR errors; compare it with the original submission.';
            if ($record['media_preparation']['resized'] ?? false) {
                $record['limitations'][] = 'A smaller copy was prepared for OCR within the image-size limit. The original upload is preserved; small lettering may be harder to read.';
            }
            if ($context['media_type'] === 'video') {
                $record['limitations'][] = 'Only sampled frames were read. Audio and the complete video were not transcribed.';
            }
            if ($record['status'] !== 'completed') {
                $record['limitations'][] = 'Visible text extraction was unavailable or incomplete.';
            }
        } elseif ($file && $context['media_type'] === 'document') {
            $record['article_text'] = $this->fetcher->pdfText((string) file_get_contents($file->getRealPath()));
            $record['status'] = $record['article_text'] === '' ? 'missing_text' : 'partial';
            $record['input_scope'] = 'PDF text, up to the first 30 pages';
            $record['limitations'][] = 'PDF extraction covers up to 30 pages; scanned pages require readable OCR text.';
        } elseif (filled($context['source_url'] ?? null) && config('services.live_verification.enabled', true)) {
            $page = $this->fetcher->fetch($context['source_url'], $budget);
            $record['source_record'] = $page;
            $record['article_text'] = $page['text'];
            $record['status'] = $page['status'];
            $record['input_scope'] = 'submitted link and supplied text';
            if ($page['status'] !== 'retrieved') {
                $record['limitations'][] = 'The submitted link could not be fully read ('.$page['status'].'). Supply its relevant text or an accessible original source.';
            }
            if ($page['text_truncated'] ?? false) {
                $record['limitations'][] = 'The submitted article exceeds the extraction limit; only the recorded excerpt was available.';
            }
        } elseif (filled($context['source_url'] ?? null)) {
            $record['status'] = 'retrieval_disabled';
            $record['limitations'][] = 'Link retrieval is disabled; only the supplied text was available.';
        }
        // Do not substitute a vision model's inferred paraphrase for the actual visible words.
        $parts = array_values(array_filter([$text, $record['visible_text'], $record['article_text'], $notes]));
        $record['original_text'] = implode("\n\n", $parts);
        $record['reconstructed_text'] = SubmittedText::paragraphs($record['original_text']);
        $record['analysis_text'] = $record['reconstructed_text'];
        // With a specific supplied statement, the link is supporting evidence.
        // Do not turn every sentence on its page into another requested claim.
        if ($record['source_record'] !== null && $text !== '') {
            $record['analysis_text'] = SubmittedText::paragraphs(implode("\n\n", array_filter([$text, $notes])));
            $record['input_scope'] = 'supplied statement; submitted link retained as evidence';
        }
        if (mb_strlen($record['analysis_text']) > (int) config('fact_check.max_input_chars', 24000)) {
            $record['analysis_text'] = '';
            $record['status'] = 'input_too_long';
            $record['limitations'][] = 'The input exceeds the analysis limit. Select the specific claim or relevant passage; no silently truncated claim was assessed.';
        }
        if (str_contains($record['visible_text'], '[unclear]')) {
            $record['limitations'][] = 'Some visible characters could not be read reliably. Confirm the quoted wording before relying on this assessment.';
            $record['status'] = 'uncertain_ocr';
        }

        return $record;
    }
}
