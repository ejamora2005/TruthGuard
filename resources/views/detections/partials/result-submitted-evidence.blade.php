@php
    $mediaPath = (string) $selectedDetection->media_path;
    $mediaUrl = $mediaPath !== '' ? $selectedDetection->media_url : null;
    $isDocument = $selectedDetection->media_type === 'document' || str_ends_with(strtolower($mediaPath), '.pdf');
    $isImage = $selectedDetection->media_type === 'image';
    $isVideo = $selectedDetection->media_type === 'video';
    $fileName = $mediaPath !== '' ? basename(str_replace('\\', '/', $mediaPath)) : null;
    $submittedUrl = trim((string) $selectedDetection->source_url);
    $safeSubmittedUrl = App\Services\Detections\ReportAnswer::safeUrl($submittedUrl);
    $evidenceType = $isDocument ? 'PDF document' : ($isImage ? 'Image' : ($isVideo ? 'Video' : ($submittedUrl !== '' ? 'Link' : 'Text')));
    $submittedText = trim((string) $selectedDetection->caption_text);
    $showSubmittedText = $submittedText !== '' && ! App\Services\Detections\SubmissionTask::isPresetInstruction($submittedText);
@endphp

<section class="tg-result-card tg-result-evidence" aria-labelledby="submitted-evidence" x-data="{ mediaFailed: false, mediaLoading: true }">
    <div class="tg-result-section-heading">
        <span class="tg-result-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1"/><path d="m3 17 6-6 4 4 3-3 5 5"/></svg></span>
        <h2 id="submitted-evidence">Submitted Evidence</h2><span class="tg-result-kind">{{ $evidenceType }}</span>
    </div>

    @if ($isImage && $mediaUrl)
        <div class="tg-result-media tg-result-image-media" x-show="!mediaFailed" :aria-busy="mediaLoading">
            <button type="button" class="tg-result-image-trigger" :disabled="mediaLoading" @click="$refs.imageDialog.showModal()" aria-label="Enlarge submitted image" aria-haspopup="dialog">
                <span class="tg-result-image-frame">
                    <img src="{{ $mediaUrl }}" alt="Original submitted image" fetchpriority="high"
                        x-on:load="mediaLoading = false" x-on:error="mediaFailed = true; mediaLoading = false"
                        x-init="if ($el.complete) { mediaFailed = !$el.naturalWidth; mediaLoading = false; }">
                    <span class="tg-result-zoom-cue" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5"/></svg></span>
                    <span class="tg-result-media-loading" x-show="mediaLoading" x-cloak role="status"><span class="tg-result-skeleton" aria-hidden="true"></span>Loading submitted image…</span>
                </span>
            </button>
        </div>
        <dialog x-ref="imageDialog" class="tg-result-lightbox" aria-labelledby="tg-result-lightbox-title" @click.self="$el.close()">
            <div class="tg-result-lightbox-panel">
                <header><h2 id="tg-result-lightbox-title">Original submitted image</h2><button type="button" autofocus aria-label="Close image preview" @click="$refs.imageDialog.close()">Close <span aria-hidden="true">&times;</span></button></header>
                <img src="{{ $mediaUrl }}" alt="Original submitted image, enlarged">
                <a href="{{ $mediaUrl }}" target="_blank" rel="noopener noreferrer">Open original file <span aria-hidden="true">&nearr;</span></a>
            </div>
        </dialog>
    @elseif ($isVideo && $mediaUrl)
        <div class="tg-result-media" x-show="!mediaFailed">
            <video controls preload="metadata" playsinline src="{{ $mediaUrl }}" aria-label="Submitted video" x-on:error="mediaFailed = true"></video>
            <a class="tg-result-file-link" href="{{ $mediaUrl }}" target="_blank" rel="noopener noreferrer">Open original video <span aria-hidden="true">&nearr;</span></a>
        </div>
    @elseif ($isDocument && $mediaUrl)
        <div class="tg-result-document">
            <span class="tg-result-document-icon" aria-hidden="true">PDF</span>
            <div><p>Submitted PDF document</p><a class="tg-result-file-link" href="{{ $mediaUrl }}" target="_blank" rel="noopener noreferrer">Open PDF <span aria-hidden="true">&nearr;</span></a></div>
        </div>
    @elseif ($isImage || $isVideo || $isDocument)
        <p class="tg-result-empty" role="status">The original {{ strtolower($evidenceType) }} is not available for preview.</p>
    @endif

    @if (($isImage || $isVideo) && $mediaUrl)
        <div class="tg-result-empty" x-show="mediaFailed" x-cloak role="status">
            <p>The original {{ strtolower($evidenceType) }} could not be loaded.</p>
            <a class="tg-result-file-link" href="{{ $mediaUrl }}" target="_blank" rel="noopener noreferrer">Try opening the original file <span aria-hidden="true">&nearr;</span></a>
        </div>
    @endif

    @if ($submittedUrl !== '' || $showSubmittedText)
        <div class="tg-result-submission-text">
            @if ($submittedUrl !== '')
                <p class="tg-result-eyebrow">Submitted link</p>
                @if ($safeSubmittedUrl)
                    <a class="tg-result-file-link" href="{{ $safeSubmittedUrl }}" target="_blank" rel="noopener noreferrer">{{ $submittedUrl }}</a>
                @else
                    <p>{{ $submittedUrl }}</p>
                @endif
            @endif
            @if ($showSubmittedText)
                <p class="tg-result-eyebrow">{{ $submittedText === ($result['request']['question'] ?? null) ? 'Your question' : 'Submitted text' }}</p>
                @include('detections.partials.result-prose', ['prose' => $selectedDetection->caption_text, 'proseId' => 'result-original-text', 'proseClass' => 'tg-result-original-text', 'expandLabel' => 'Read full submission'])
            @endif
        </div>
    @elseif (!$isImage && !$isVideo && !$isDocument)
        <p class="tg-result-empty">No original media or submission text is available.</p>
    @endif

    @if ($fileName)
        <p class="tg-result-filename"><strong>File</strong> <span title="{{ $fileName }}">{{ $fileName }}</span></p>
    @endif

    @if ($selectedDetection->created_at)
        <p class="tg-result-filename"><strong>Submitted</strong><time datetime="{{ $selectedDetection->created_at->toIso8601String() }}">{{ $selectedDetection->created_at->copy()->timezone('Asia/Shanghai')->format('M j, Y, g:i A') }} UTC+8</time></p>
    @endif
</section>
