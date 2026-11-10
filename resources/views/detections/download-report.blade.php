<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>TruthGuard fact-check report {{ $selectedDetection->id }}</title>
<style>
@page { margin: 42pt 42pt 48pt; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; line-height: 1.55; color: #334155; word-wrap: break-word; }
h1 { margin: 4pt 0 12pt; font-size: 22pt; color: #1d4ed8; }
h2 { margin: 20pt 0 8pt; font-size: 13pt; color: #0f172a; page-break-after: avoid; }
h3 { font-size: 11pt; color: #0f172a; page-break-after: avoid; }
p { margin: 5pt 0 9pt; } a { color: #1d4ed8; text-decoration: none; overflow-wrap: break-word; word-wrap: break-word; }
.kicker { color: #64748b; font-size: 9pt; letter-spacing: .5pt; }
.summary { padding: 12pt 14pt; border: 1pt solid #bfdbfe; background: #eff6ff; border-radius: 8pt; }
.summary strong { color: #1d4ed8; } .meta { color: #64748b; font-size: 9pt; }
.summary.risk-green { border-color:#86efac; background:#f0fdf4; } .summary.risk-green > p:first-child { color:#166534; }
.summary.risk-teal { border-color:#5eead4; background:#f0fdfa; } .summary.risk-teal > p:first-child { color:#115e59; }
.summary.risk-amber { border-color:#fcd34d; background:#fffbeb; } .summary.risk-amber > p:first-child { color:#92400e; }
.summary.risk-orange { border-color:#fdba74; background:#fff7ed; } .summary.risk-orange > p:first-child { color:#9a3412; }
.summary.risk-red { border-color:#fca5a5; background:#fef2f2; } .summary.risk-red > p:first-child { color:#991b1b; }
.summary.risk-neutral { border-color:#cbd5e1; background:#f1f5f9; } .summary.risk-neutral > p:first-child { color:#475569; }
.summary > p:first-child strong { color:inherit; }
.source { padding: 10pt 0; border-top: 1pt solid #cbd5e1; }
blockquote { margin: 8pt 0; padding: 5pt 10pt; border-left: 3pt solid #93c5fd; background: #f8fafc; }
.url { font-size: 8pt; word-wrap: break-word; } ul { padding-left: 18pt; }
.footer { margin-top: 22pt; padding-top: 8pt; border-top: 1pt solid #cbd5e1; font-size: 8pt; color: #64748b; }
</style></head><body>
<p class="kicker">TRUTHGUARD / FACT-CHECK REPORT {{ $selectedDetection->id }}</p>
<h1>{{ $transparentReport['verdict_label'] }}</h1>
<div class="summary risk-{{ $transparentReport['risk_percentage']['color'] ?? 'neutral' }}">
<p><strong>Risk assessment: {{ $transparentReport['risk_percentage']['display'] }}</strong> - {{ $transparentReport['risk_percentage']['caption'] }}</p>
@if(isset($transparentReport['risk_percentage']['basis']))<p>{{ $transparentReport['risk_percentage']['basis'] }}</p>@endif
<p>{{ $transparentReport['risk_percentage']['explanation'] }}</p>
<p><strong>Model confidence:</strong> {{ $transparentReport['confidence']['value'] }}<br>{{ $transparentReport['confidence']['explanation'] }}</p>
@if($transparentReport['model_score']['value'] !== 'Unavailable')<p><strong>Model output:</strong> {{ $transparentReport['model_score']['value'] }}<br>{{ $transparentReport['model_score']['explanation'] }}</p>@endif
</div>
<p class="meta">Assessed: {{ $transparentReport['result']['assessed_at'] ?? 'Not recorded' }}<br>Evidence cutoff: {{ $transparentReport['result']['evidence_cutoff'] ?? 'Not recorded' }}</p>
<h2>Submitted evidence</h2>
<p>{{ $selectedDetection->caption_text ?: $selectedDetection->notes ?: 'No accompanying text was recorded.' }}</p>
@if($selectedDetection->media_path)<p>Original file reference: {{ basename($selectedDetection->media_path) }} ({{ $selectedDetection->media_type }})</p>@endif
@if($sourceUrl = \App\Services\Detections\ReportAnswer::safeUrl($selectedDetection->source_url))<p class="url"><a href="{{ $sourceUrl }}" rel="noopener noreferrer">{{ $sourceUrl }}</a></p>@endif
<h2>What the evidence shows</h2>
@foreach($transparentReport['explanation'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
@foreach($transparentReport['reading_links'] as $link)<p><a href="{{ $link['url'] }}" rel="noopener noreferrer">{{ $link['label'] }}</a></p>@endforeach
<h2>Sources and evidence</h2>
@forelse($transparentReport['sources'] as $source)
<div class="source"><h3>{{ $source['name'] }} - {{ $source['title'] }}</h3>
<p class="meta">{{ $source['credibility'] }} | {{ $source['published_at'] ? substr($source['published_at'], 0, 10) : 'Publication date not recorded' }}</p>
<p class="url"><a href="{{ $source['url'] }}" rel="noopener noreferrer">{{ $source['url'] }}</a></p>
@php($passages = collect($source['relationships'])->reject(fn ($row) => $row['superseded'])->groupBy('excerpt'))
@forelse($passages as $excerpt => $rows)
<blockquote>{{ $excerpt }}</blockquote>
@foreach($rows->pluck('explanation')->unique() as $explanation)<p>{{ $explanation }}</p>@endforeach
@empty<p>{{ $source['used_for'] }}</p>@endforelse
</div>
@empty<p>No reviewed source was recorded. This does not establish that the claim is false.</p>@endforelse
<h2>Recommended action</h2><p>{{ $transparentReport['recommended_action'] }}</p>
@if($transparentReport['limitations'])<h2>Limitations</h2><ul>@foreach($transparentReport['limitations'] as $limitation)<li>{{ $limitation }}</li>@endforeach</ul>@endif
<p class="footer">This report reflects saved evidence at its recorded cutoff. Screening risk is separate from confidence and factual verification. Unavailable evidence, dates and percentages have not been estimated.</p>
</body></html>
