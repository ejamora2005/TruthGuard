@once
<style>
    /* Result-only tokens. These classes never change the saved analysis or API colors. */
    .tg-result-report {
        --result-tone: #475569;
        --result-tint: #f1f5f9;
        --result-border: #cbd5e1;
        display: grid;
        gap: 18px;
        width: 100%;
        max-width: 1180px;
        min-width: 0;
        margin: 0 auto;
        color: #1e293b;
        font-size: 14px;
        line-height: 1.7;
        overflow-wrap: anywhere;
    }
    .tg-result-report.tg-status-verified { --result-tone: #047857; --result-tint: #ecfdf5; --result-border: #a7d9c7; }
    .tg-result-report.tg-status-adverse { --result-tone: #b42335; --result-tint: #fff1f2; --result-border: #f2bdc5; }
    .tg-result-report.tg-status-context { --result-tone: #925b08; --result-tint: #fffbeb; --result-border: #ebd391; }
    .tg-result-report.tg-status-uncertain { --result-tone: #475569; --result-tint: #f1f5f9; --result-border: #cbd5e1; }
    .tg-result-report.tg-status-outdated { --result-tone: #1d4ed8; --result-tint: #eff6ff; --result-border: #bfd3f6; }
    .tg-result-report *, .tg-result-lightbox * { box-sizing: border-box; min-width: 0; }
    .tg-result-all-details { grid-column: 1 / -1; }
    .tg-result-all-details > summary { padding: 16px 20px; cursor: pointer; border: 1px solid #dce3ed; border-radius: 12px; background: #fff; color: #344b85; font-weight: 650; }
    .tg-result-all-details > summary span { margin-left: 12px; color: #64748b; font-weight: 400; font-size: 12px; }
    .tg-result-expanded-details { display: grid; gap: 18px; padding-top: 18px; }
    .tg-result-answer-sources { list-style: none; padding: 0; margin: 18px 0 0; display: grid; gap: 10px; }
    .tg-result-answer-sources li { display: grid; padding-top: 10px; border-top: 1px solid #e5eaf1; }
    .tg-result-answer-sources a { color: #2855b5; text-decoration: underline; text-underline-offset: 3px; font-weight: 550; }
    .tg-result-answer-sources li > span { color: #64748b; font-size: 11px; }
    .tg-result-report :is(h1, h2, h3, p), .tg-result-lightbox h2 { margin: 0; }
    .tg-result-report svg, .tg-result-lightbox svg { width: 20px; height: 20px; flex-shrink: 0; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
    /* Scoped important typography overrides the shell's global small-screen font rules. */
    .tg-result-report .tg-result-report-header { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 1px 0 5px; }
    .tg-result-report .tg-result-report-header h1 { color: #18243b; font-size: 28px !important; font-weight: 750; line-height: 1.3 !important; letter-spacing: -.035em; }
    .tg-result-report .tg-result-eyebrow { color: #64748b; font-size: 10px !important; font-weight: 650; line-height: 1.5 !important; letter-spacing: .09em; text-transform: uppercase; }
    .tg-result-report .tg-result-brand-label { margin-bottom: 7px; color: #526094; }
    .tg-result-report .tg-result-brand-label span { margin: 0 7px; color: #b6c0d1; }
    .tg-result-report .tg-result-report-subtitle { margin-top: 6px; color: #5f6d80; font-size: 12px !important; line-height: 1.7 !important; }
    .tg-result-report .tg-result-report-id { display: grid; gap: 2px; flex-shrink: 0; padding-left: 20px; border-left: 1px solid #dce3ed; }
    .tg-result-report .tg-result-report-id span { color: #5f6d80; font-size: 10px !important; }
    .tg-result-report .tg-result-report-id strong { color: #334155; font-size: 20px; font-weight: 650; }
    .tg-result-report .tg-result-card { padding: 22px; border: 1px solid #dce3ed; border-radius: 16px; background: #fff; box-shadow: 0 4px 20px #24355307, 0 1px 2px #24355303; scroll-margin-top: 96px; }
    .tg-result-report .tg-result-card h2 { color: #243047; font-size: 16px !important; font-weight: 700; line-height: 1.45 !important; letter-spacing: -.02em; }
    .tg-result-report .tg-result-component { grid-column: 1 / -1; }
    .tg-result-report .tg-result-component > :is(p, details, h3) + * { margin-top: 12px; }
    .tg-result-report .tg-result-section-heading { display: flex; align-items: center; gap: 9px; margin-bottom: 16px; }
    .tg-result-report .tg-result-section-icon { display: grid; place-items: center; width: 32px; height: 32px; flex: 0 0 auto; border: 1px solid #e1e6f2; border-radius: 9px; color: #5264a3; background: #f6f8fc; }
    .tg-result-report .tg-result-section-icon svg { width: 18px; height: 18px; }
    .tg-result-report .tg-result-status-icon { border-color: var(--result-border); color: var(--result-tone); background: var(--result-tint); }
    .tg-result-report .tg-result-kind, .tg-result-report .tg-result-count { flex-shrink: 0; padding: 3px 8px; border: 1px solid #e0e6ef; border-radius: 6px; color: #64748b; background: #fafbfd; font-size: 10px !important; font-weight: 550; line-height: 1.5 !important; }
    .tg-result-report .tg-result-kind { margin-left: auto; }
    .tg-result-report .tg-result-count { margin-left: auto; }
    .tg-result-report .tg-result-evidence { display: flex; flex-direction: column; }
    .tg-result-report .tg-result-media { padding: 12px 12px 0; border: 1px solid #e2e7ee; border-radius: 12px; background: #f5f7fa; }
    .tg-result-report .tg-result-image-media { padding-bottom: 12px; }
    .tg-result-report .tg-result-image-trigger { display: block; width: 100%; padding: 0; border: 0; border-radius: 6px; color: #405a96; background: transparent; cursor: zoom-in; }
    .tg-result-report .tg-result-image-trigger:disabled { cursor: progress; }
    .tg-result-report .tg-result-image-frame { position: relative; display: block; }
    .tg-result-report .tg-result-image-trigger img, .tg-result-report .tg-result-media video { display: block; width: 100%; height: 160px; margin: auto; border-radius: 5px; object-fit: contain; }
    .tg-result-report .tg-result-zoom-cue { position: absolute; right: 8px; bottom: 8px; display: grid; place-items: center; width: 30px; height: 30px; border: 1px solid #d8e1ef; border-radius: 8px; color: #405a96; background: rgb(255 255 255 / 92%); box-shadow: 0 2px 8px rgb(15 23 42 / 10%); pointer-events: none; }
    .tg-result-report .tg-result-zoom-cue svg { width: 16px; height: 16px; }
    .tg-result-report .tg-result-image-trigger:hover .tg-result-zoom-cue, .tg-result-report .tg-result-image-trigger:focus-visible .tg-result-zoom-cue { border-color: #a9bce1; color: #2458ad; background: #fff; }
    .tg-result-report .tg-result-media-loading { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; color: #64748b; background: #f5f7fa; font-size: 11px !important; }
    .tg-result-report .tg-result-skeleton { display: block; width: 58%; height: 72px; border: 1px solid #e4e9f1; border-radius: 8px; background: linear-gradient(110deg, #e8edf4, #f6f8fb, #e8edf4); }
    .tg-result-report .tg-result-filename { display: flex; align-items: baseline; gap: 8px; margin-top: 12px; color: #5f6d80; font-size: 10px !important; line-height: 1.7 !important; }
    .tg-result-report .tg-result-filename strong { flex: 0 0 auto; color: #516176; font-weight: 600; }
    .tg-result-report .tg-result-filename span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: inherit !important; }
    .tg-result-report .tg-result-document { display: flex; align-items: center; gap: 14px; padding: 22px; border: 1px solid #e2e7ee; border-radius: 12px; background: #f8fafc; }
    .tg-result-report .tg-result-document p { font-size: 14px !important; font-weight: 600; }
    .tg-result-report .tg-result-document-icon { display: grid; place-items: center; width: 48px; height: 60px; flex-shrink: 0; border: 1px solid #cbd5e1; border-radius: 8px; color: #475569; background: #fff; font-size: 11px !important; font-weight: 650; }
    .tg-result-report .tg-result-file-link { display: inline-block; color: #2855b5; font-size: 12px !important; font-weight: 550; line-height: 1.8 !important; text-decoration: underline; text-decoration-color: #c2cee7; text-underline-offset: 4px; }
    .tg-result-report .tg-result-media > .tg-result-file-link { margin: 10px 0; }
    .tg-result-report .tg-result-empty { padding: 20px; border: 1px dashed #d4dce7; border-radius: 10px; color: #64748b; background: #fafbfc; font-size: 13px !important; line-height: 1.8 !important; }
    .tg-result-report .tg-result-submission-text { padding: 14px; border: 1px solid #e5eaf1; border-radius: 10px; background: #f9fafc; }
    .tg-result-report .tg-result-submission-text .tg-result-eyebrow { margin-bottom: 6px; }
    .tg-result-report .tg-result-submission-text .tg-result-eyebrow:not(:first-child) { margin-top: 14px; }
    .tg-result-report .tg-result-media ~ .tg-result-submission-text, .tg-result-report .tg-result-document ~ .tg-result-submission-text, .tg-result-report .tg-result-empty ~ .tg-result-submission-text { margin-top: 12px; }
    .tg-result-report .tg-result-original-text { font-size: 13px !important; line-height: 1.8 !important; white-space: pre-line; }
    .tg-result-report .tg-result-record { margin-top: auto; padding-top: 20px; }
    .tg-result-report .tg-result-record h3 { padding-top: 16px; border-top: 1px solid #e7ebf1; color: #475569; font-size: 12px !important; font-weight: 650; line-height: 1.5 !important; }
    .tg-result-report .tg-result-record ol { display: grid; margin: 14px 0 0; padding: 0; list-style: none; }
    .tg-result-report .tg-result-record li { position: relative; display: flex; align-items: flex-start; gap: 11px; padding: 0 0 13px; }
    .tg-result-report .tg-result-record li:last-child { padding-bottom: 0; }
    .tg-result-report .tg-result-record li:not(:last-child)::before { position: absolute; top: 11px; bottom: 0; left: 4px; width: 1px; background: #d9e1ed; content: ''; }
    .tg-result-report .tg-result-record-marker { position: relative; width: 9px; height: 9px; flex: 0 0 auto; margin-top: 5px; border: 2px solid #7083ac; border-radius: 50%; background: #fff; }
    .tg-result-report .tg-result-record li div { display: grid; gap: 2px; }
    .tg-result-report .tg-result-record strong { color: #4c5b72; font-size: 11px; font-weight: 600; line-height: 1.6; }
    .tg-result-report .tg-result-record :is(time, div > span) { color: #5f6d80; font-size: 10px !important; line-height: 1.7 !important; }
    .tg-result-report .tg-result-assessment { display: flex; flex-direction: column; border-color: var(--result-border); border-top: 3px solid var(--result-tone); }
    .tg-result-report .tg-result-claim { padding: 12px 14px; border: 1px solid #e7ebf1; border-radius: 10px; background: #f8fafc; }
    .tg-result-report .tg-result-claim .tg-result-eyebrow { margin-bottom: 5px; }
    .tg-result-report .tg-result-claim-text { color: #334155; font-size: 14px !important; font-weight: 550; line-height: 1.8 !important; white-space: pre-line; }
    .tg-result-report .tg-result-verdict-heading { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
    .tg-result-report .tg-result-verdict-heading .tg-result-eyebrow { margin-bottom: 6px; }
    .tg-result-report .tg-result-verdict-badge { display: inline-flex; align-items: center; gap: 9px; max-width: 100%; padding: 9px 12px; border: 1px solid var(--result-border); border-radius: 9px; color: var(--result-tone); background: var(--result-tint); }
    .tg-result-report .tg-result-verdict-badge svg { width: 23px; height: 23px; }
    .tg-result-report .tg-result-verdict-help { position: relative; display: inline-flex; flex: 0 0 auto; }
    .tg-result-report .tg-result-verdict-help button { display: grid; place-items: center; width: 36px; height: 36px; padding: 0; border: 0; border-radius: 50%; color: inherit; background: transparent; cursor: help; }
    .tg-result-report .tg-result-verdict-help button:hover, .tg-result-report .tg-result-verdict-help button:focus-visible { background: rgb(255 255 255 / 65%); }
    .tg-result-report .tg-result-verdict-tooltip { position: absolute; z-index: 10; top: calc(100% + 9px); left: -8px; width: min(280px, calc(100vw - 64px)); padding: 11px 13px; border: 1px solid #dce3ed; border-radius: 10px; color: #334155; background: #fff; box-shadow: 0 10px 26px rgb(15 23 42 / 15%); font-size: 12px !important; font-weight: 500; line-height: 1.55 !important; text-align: left; opacity: 0; visibility: hidden; pointer-events: none; transform: translateY(-3px); transition: opacity 150ms ease, transform 150ms ease, visibility 150ms; }
    .tg-result-report .tg-result-verdict-help:hover .tg-result-verdict-tooltip, .tg-result-report .tg-result-verdict-help:focus-within .tg-result-verdict-tooltip { opacity: 1; visibility: visible; transform: translateY(0); }
    .tg-result-report .tg-result-verdict-badge h3 { font-size: 20px !important; font-weight: 750; line-height: 1.3 !important; letter-spacing: -.025em; }
    .tg-result-report .tg-result-confidence-score { display: grid; gap: 0; text-align: right; }
    .tg-result-report .tg-result-confidence-score > span { color: #64748b; font-size: 10px !important; line-height: 1.5 !important; }
    .tg-result-report .tg-result-confidence-score strong { color: var(--result-tone); font-size: 27px; font-weight: 700; line-height: 1.4; }
    .tg-result-report .tg-result-confidence-score strong span { margin-left: 2px; color: #5f6d80; font-size: 12px !important; font-weight: 500; }
    .tg-result-report .tg-result-confidence { margin-bottom: 18px; }
    .tg-result-report .tg-result-strength-label { font-size: 16px !important; }
    .tg-result-source-relationship { margin-top: 12px; padding: 10px 12px; border-left: 3px solid #64748b; background: #f8fafc; font-size: 12px; }
    .tg-result-source-relationship a { font-weight: 650; text-decoration: underline; }
    .tg-result-source-relationship blockquote { margin-top: 8px; line-height: 1.75; white-space: pre-line; }
    .tg-evidence-supporting { border-color: #047857; }
    .tg-evidence-contradicting { border-color: #b42335; }
    .tg-result-report .tg-result-meter { height: 5px; margin-top: 14px; overflow: hidden; border-radius: 8px; background: #e9edf3; }
    .tg-result-report .tg-result-meter span { display: block; height: 100%; border-radius: inherit; background: var(--result-tone); }
    .tg-result-report .tg-result-confidence-note { margin-top: 7px; color: #5f6d80; font-size: 10px !important; line-height: 1.7 !important; }
    .tg-result-report .tg-result-explanation { margin-top: 18px; }
    .tg-result-report .tg-result-explanation h3 { margin-bottom: 7px; color: #334155; font-size: 13px !important; font-weight: 650; line-height: 1.5 !important; }
    .tg-result-report .tg-result-summary { font-size: 14px !important; line-height: 1.8 !important; white-space: pre-line; }
    .tg-result-report .tg-result-prose-collapsed { max-height: 12.6em; overflow: hidden; }
    .tg-result-report .tg-result-text-button { display: inline-flex; align-items: center; gap: 9px; min-height: 36px; margin-top: 5px; padding: 0; border: 0; color: #2855b5; background: transparent; font-size: 12px !important; font-weight: 600; line-height: 1.6 !important; cursor: pointer; }
    .tg-result-report .tg-result-text-button span { font-size: inherit !important; }
    .tg-result-report .tg-result-assessment-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-top: auto; padding-top: 18px; }
    .tg-result-report .tg-result-button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 9px 13px; border: 1px solid #d7dfeb; border-radius: 9px; color: #405477; background: #fff; font-size: 11px !important; font-weight: 600; line-height: 1.6 !important; text-decoration: none; cursor: pointer; }
    .tg-result-report .tg-result-button span { font-size: inherit !important; }
    .tg-result-report .tg-result-button:hover { border-color: #a6b9df; background: #f6f8fd; }
    .tg-result-report .tg-result-button svg { width: 17px; height: 17px; }
    .tg-result-report .tg-result-button-primary { border-color: #3458b6; color: #fff; background: #3458b6; }
    .tg-result-report .tg-result-button-primary:hover { color: #fff; background: #294995; }
    .tg-result-report .tg-result-report-links { display: flex; flex-wrap: wrap; gap: 12px; }
    .tg-result-report .tg-result-report-links a { display: inline-flex; align-items: center; gap: 5px; min-height: 44px; color: #526590; font-size: 10px !important; font-weight: 550; line-height: 1.5 !important; text-decoration: underline; text-decoration-color: #d0d8e9; text-underline-offset: 4px; }
    .tg-result-report .tg-result-notices { display: grid; gap: 12px; }
    .tg-result-report .tg-result-notice { display: flex; align-items: flex-start; gap: 10px; padding: 16px; border: 1px solid var(--result-border); border-left: 3px solid var(--result-tone); border-radius: 12px; color: var(--result-tone); background: var(--result-tint); }
    .tg-result-report .tg-result-notice-info { border-color: #ccdaef; border-left-color: #5578af; color: #355a90; background: #f4f8fe; }
    .tg-result-report .tg-result-notice > svg { margin-top: 1px; width: 18px; height: 18px; }
    .tg-result-report .tg-result-notice h3 { font-size: 12px !important; font-weight: 650; line-height: 1.6 !important; }
    .tg-result-report .tg-result-notice p { margin-top: 4px; font-size: 12px !important; line-height: 1.8 !important; white-space: pre-line; }
    .tg-result-report .tg-result-details { display: grid; gap: 18px; align-items: stretch; }
    .tg-result-report .tg-result-details h2 { scroll-margin-top: 96px; }
    .tg-result-report .tg-result-details .tg-result-section-heading { margin-bottom: 6px; }
    .tg-result-report .tg-result-section-description { margin-bottom: 16px; color: #5f6d80; font-size: 11px !important; line-height: 1.7 !important; }
    .tg-result-report .tg-result-scroll-region { max-height: 380px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; overscroll-behavior: contain; }
    .tg-result-report .tg-result-findings { display: grid; gap: 0; margin: 0; padding: 0; list-style: none; }
    .tg-result-report .tg-result-findings li { display: flex; align-items: flex-start; gap: 12px; padding: 15px 0; border-top: 1px solid #e8edf3; }
    .tg-result-report .tg-result-findings li:last-child { padding-bottom: 2px; }
    .tg-result-report .tg-result-finding-number { display: grid; place-items: center; width: 27px; height: 27px; flex-shrink: 0; border: 1px solid var(--result-border); border-radius: 8px; color: var(--result-tone); background: var(--result-tint); font-size: 10px !important; font-weight: 600; }
    .tg-result-report .tg-result-findings p { padding-top: 2px; font-size: 13px !important; line-height: 1.8 !important; white-space: pre-line; }
    .tg-result-report .tg-result-sources { display: grid; gap: 12px; }
    .tg-result-report .tg-result-source { padding: 15px; border: 1px solid #e2e8f0; border-radius: 11px; background: #fcfdfe; }
    .tg-result-report .tg-result-source-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; color: #64748b; font-size: 11px; font-weight: 550; }
    .tg-result-report .tg-result-source-meta > span { font-size: 10px !important; line-height: 1.6 !important; }
    .tg-result-report .tg-result-source-type { padding: 2px 7px; border: 1px solid #e0e5ed; border-radius: 5px; color: #55657e; background: #f2f5f9; }
    .tg-result-report .tg-result-source-link { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-top: 9px; color: #2855a4; font-size: 14px !important; font-weight: 650; line-height: 1.7 !important; text-decoration: underline; text-decoration-color: #c2d1e9; text-underline-offset: 4px; }
    .tg-result-report .tg-result-source-link span { font-size: inherit !important; line-height: inherit !important; }
    .tg-result-report .tg-result-source-link svg { width: 14px; height: 14px; margin-top: 5px; }
    .tg-result-report .tg-result-source-domain { margin-top: 3px; color: #5f6d80; font-size: 10px !important; line-height: 1.6 !important; }
    .tg-result-report .tg-result-source-purpose { margin-top: 12px; color: #526178; font-size: 12px !important; line-height: 1.8 !important; white-space: pre-line; }
    .tg-result-report .tg-result-source-purpose strong { font-weight: 650; }
    .tg-result-report .tg-result-source-date { margin-top: 10px; color: #5f6d80; font-size: 10px !important; line-height: 1.6 !important; }
    .tg-result-report .tg-result-limitations { background: #fafbfd; }
    .tg-result-report .tg-result-limitations .tg-result-section-heading { margin-bottom: 10px; }
    .tg-result-report .tg-result-limitations ul { display: grid; gap: 8px; margin: 0; padding-left: 18px; list-style: disc; }
    .tg-result-report .tg-result-limitations li { color: #5c6b81; font-size: 12px !important; line-height: 1.8 !important; white-space: pre-line; }
    .tg-result-report .tg-result-cutoff { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; padding: 3px 2px; }
    .tg-result-report .tg-result-cutoff p { color: #64748b; font-size: 11px !important; line-height: 1.8 !important; }
    .tg-result-report .tg-result-cutoff-highlight { padding: 16px 18px; border: 1px solid #ccd9ef; border-radius: 12px; background: #f2f6fc; }
    .tg-result-report .tg-result-muted { color: #5f6d80; font-size: 12px !important; line-height: 1.8 !important; }
    .tg-result-report :is(a, button, [tabindex]):focus-visible, .tg-result-lightbox :is(a, button):focus-visible { outline: 3px solid #818cf8; outline-offset: 3px; }
    .tg-result-lightbox { width: 1120px; max-width: calc(100% - 32px); max-height: calc(100dvh - 32px); margin: auto; padding: 0; border: 1px solid #dbe4f5; border-radius: 16px; color: #1e293b; background: #fff; box-shadow: 0 24px 80px #0f172a55; }
    .tg-result-lightbox::backdrop { background: #0f172acc; backdrop-filter: blur(3px); }
    .tg-result-lightbox-panel { padding: 18px; }
    .tg-result-lightbox header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
    .tg-result-lightbox h2 { font-size: 15px !important; font-weight: 650; line-height: 1.5 !important; }
    .tg-result-lightbox button { display: inline-flex; align-items: center; gap: 12px; min-height: 44px; padding: 8px 14px; border: 1px solid #dbe4f5; border-radius: 9px; color: #334155; background: #f8fafc; font-size: 13px !important; cursor: pointer; }
    .tg-result-lightbox button span { font-size: 22px !important; }
    .tg-result-lightbox img { display: block; width: 100%; height: auto; max-height: 72dvh; border-radius: 8px; background: #f1f5f9; object-fit: contain; }
    .tg-result-lightbox a { display: inline-block; min-height: 44px; margin-top: 12px; padding-top: 10px; color: #2855b5; font-size: 13px !important; text-decoration: underline; text-underline-offset: 4px; }
    @media (min-width: 1200px) {
        .tg-result-report { grid-template-columns: minmax(290px, .86fr) minmax(0, 1.4fr); align-items: stretch; }
        .tg-result-report > :not(.tg-result-evidence):not(.tg-result-assessment) { grid-column: 1 / -1; }
        .tg-result-report .tg-result-details, .tg-result-report .tg-result-notices { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .tg-result-report .tg-result-notice:last-child:nth-child(odd) { grid-column: 1 / -1; }
    }
    @media (max-width: 1199px) {
        .tg-result-report .tg-result-record { margin-top: 0; }
        .tg-result-report .tg-result-scroll-region { max-height: none; overflow: visible; }
    }
    @media (max-width: 640px) {
        .tg-result-report { gap: 14px; }
        .tg-result-report .tg-result-report-header { align-items: flex-start; gap: 10px; }
        .tg-result-report .tg-result-report-header h1 { font-size: 23px !important; }
        .tg-result-report .tg-result-brand-label { font-size: 9px !important; }
        .tg-result-report .tg-result-brand-label span { margin: 0 3px; }
        .tg-result-report .tg-result-report-id { padding-left: 10px; }
        .tg-result-report .tg-result-report-id span { max-width: 54px; }
        .tg-result-report .tg-result-report-id strong { font-size: 16px; }
        .tg-result-report .tg-result-card { padding: 18px; border-radius: 14px; }
        .tg-result-report .tg-result-section-heading { gap: 8px; margin-bottom: 14px; }
        .tg-result-report .tg-result-card h2 { font-size: 14px !important; }
        .tg-result-report .tg-result-section-icon { width: 28px; height: 28px; border-radius: 8px; }
        .tg-result-report .tg-result-section-icon svg { width: 16px; height: 16px; }
        .tg-result-report .tg-result-kind { padding: 3px 6px; font-size: 9px !important; }
        .tg-result-report .tg-result-media { padding: 9px 9px 0; }
        .tg-result-report .tg-result-image-media { padding-bottom: 9px; }
        .tg-result-report .tg-result-image-trigger img, .tg-result-report .tg-result-media video { height: 144px; }
        .tg-result-report .tg-result-claim { padding: 11px 12px; }
        .tg-result-report .tg-result-verdict-badge h3 { font-size: 18px !important; }
        .tg-result-report .tg-result-verdict-badge { padding: 8px 10px; }
        .tg-result-report .tg-result-confidence-score { text-align: left; }
        .tg-result-report .tg-result-confidence-score strong { font-size: 24px; }
        .tg-result-report .tg-result-text-button { min-height: 44px; }
        .tg-result-report .tg-result-notice { padding: 14px; }
        .tg-result-report .tg-result-details { gap: 14px; }
        .tg-result-report .tg-result-source { padding: 13px; }
        .tg-result-report .tg-result-source-link { min-height: 44px; align-items: center; }
        .tg-result-report .tg-result-source-link svg { margin-top: 0; }
        .tg-result-report .tg-result-cutoff .tg-result-button { width: 100%; }
        .tg-result-lightbox-panel { padding: 12px; }
        .tg-result-lightbox img { max-height: 65dvh; }
    }
    .tg-result-report .tg-result-verdict-heading { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
    .tg-result-report .tg-result-score { display: grid; gap: 4px; padding: 10px 13px; border: 1px solid var(--result-border); border-radius: 10px; background: var(--result-tint); color: var(--result-tone); }
    .tg-result-report .tg-result-score strong { font-size: 14px !important; line-height: 1.5 !important; }
    .tg-result-report .tg-result-score span { font-size: 11px !important; line-height: 1.5 !important; }
    .tg-result-report .tg-result-answer-copy { white-space: normal; }
    .tg-result-report .tg-result-answer-copy p { margin: 0 0 12px; font-size: 14px !important; line-height: 1.8 !important; overflow-wrap: anywhere; white-space: normal; }
    .tg-result-report .tg-result-citation { display: inline; color: #2458ad; font-weight: 650; text-decoration: underline; text-underline-offset: 3px; }
    .tg-result-report .tg-result-secondary { padding: 18px 0; border-top: 1px solid #e2e8f0; }
    .tg-result-report .tg-result-all-details { margin-top: 0; }
    .tg-result-report .tg-result-expanded-details { padding: 12px 0; }
    .tg-result-report .tg-result-expanded-details .tg-result-details { grid-template-columns: minmax(0, 1fr); }
    .tg-result-report .tg-result-verify-sources { margin-top: 0; border: 1px solid #e2e8f0; }
    .tg-result-report .tg-result-source-cards { display: grid; gap: 12px; margin-top: 12px; min-width: 0; }
    .tg-result-report .tg-result-source-cards h3 { margin-top: 8px; font-size: 14px !important; line-height: 1.6 !important; }
    .tg-result-report .tg-result-source-cards .tg-result-button { margin-top: 12px; }
    .tg-result-report .tg-result-more-sources { margin-top: 12px; }
    .tg-result-report .tg-result-more-sources summary { min-height: 44px; padding: 12px 0; color: #2458ad; cursor: pointer; }
    .tg-result-report .tg-result-source[id] { scroll-margin-top: 100px; }
    @media (min-width: 1200px) {
        .tg-result-report .tg-result-verify-sources > .tg-result-source-cards { grid-template-columns: repeat(3, minmax(0, 1fr)); align-items: stretch; }
    }
    @media (max-width: 640px) { .tg-result-report .tg-result-score { width: 100%; } }
    @media (prefers-reduced-motion: reduce) { .tg-result-report .tg-result-verdict-tooltip { transition: none; } }
</style>
@endonce
