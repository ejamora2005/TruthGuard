@php
    $policyVersion = $policyVersion ?? (string) config('app.privacy_policy_version', '2026-11-04');
    $privacyContact = (string) config('app.privacy_contact_email', 'truthguard2026@gmail.com');
@endphp

<div class="tg-policy-sections">
    <section id="privacy-overview" class="tg-policy-section">
        <div class="tg-policy-section-heading"><span aria-hidden="true">01</span><h2>What we collect and why</h2></div>
        <p>TruthGuard processes account information and submitted evidence to provide media analysis, fact-checking, saved reports, and account security.</p>
        <ul>
            <li><strong>Account details:</strong> name, username, email, protected password credentials, profile information, and privacy acknowledgement.</li>
            <li><strong>Social sign-in:</strong> the account identifier, name, email, verification status where provided, and avatar returned by Google or Facebook. TruthGuard does not receive your Google or Facebook password.</li>
            <li><strong>Submitted evidence:</strong> images, videos, documents, links, captions, questions, extracted text, source references, and analysis results.</li>
            <li><strong>Service activity:</strong> session and login records, notification settings, push subscriptions if enabled, and operational logs used for delivery, troubleshooting, security, and usage monitoring.</li>
        </ul>
        <p>We use this information to run your account, review submissions, display reports and history, send enabled notifications, and investigate service errors or misuse.</p>
    </section>

    <section id="ai-processing" class="tg-policy-section">
        <div class="tg-policy-section-heading"><span aria-hidden="true">02</span><h2>AI processing and service providers</h2></div>
        <p>Depending on the requested check and enabled features, submitted text, images, document content, or sampled video frames may be sent to OpenAI for extraction, verification, or explanations. Local models may also assess media.</p>
        <p>Search and verification services may receive search terms derived from your submission. TruthGuard also uses public fact-check and news sources, social sign-in providers, hosting, email, and browser push services as needed. These providers may process information outside your country under their own terms.</p>
        <p>For a matching submission, TruthGuard may reuse a saved result or a documented reviewed answer. Exact file fingerprints can identify previously reviewed media. A reused answer is not a new independent detector assessment.</p>
        <div class="tg-policy-callout"><strong>Check important conclusions.</strong> AI and automated matching can make mistakes. Media origin does not establish whether an accompanying news claim is true. Read the cited evidence before sharing or acting on a report.</div>
    </section>

    <section id="privacy-storage" class="tg-policy-section">
        <div class="tg-policy-section-heading"><span aria-hidden="true">03</span><h2>Storage, access, and retention</h2></div>
        <p>Your account, submissions, reports, and related service records are stored to support the workspace. Active history normally covers the latest seven days. Older records are archived rather than automatically deleted; archiving does not remove uploaded files.</p>
        <p>Uploaded media is currently served through file URLs used to display evidence. Anyone who obtains a file URL may be able to access that file. Do not upload passwords, identity documents, confidential records, or other sensitive material that you are not comfortable sharing through this service.</p>
        <p>Authorized administrators may access records for support and platform operations. Contact us to request deletion or ask about stored information. Removal from the workspace does not necessarily remove copies held in backups, security logs, or by external providers; those copies require separate handling.</p>
    </section>

    <section id="privacy-security" class="tg-policy-section">
        <div class="tg-policy-section-heading"><span aria-hidden="true">04</span><h2>Account and device security</h2></div>
        <p>TruthGuard uses password hashing, session controls, inactivity timeouts, request protections, and role-based access checks. These safeguards reduce risk but do not guarantee that every upload or account is protected from all threats.</p>
        <p>Cookies and browser storage support sign-in, remembered-device choices, appearance settings, and PWA operation. Use remembered sign-in only on your own device. Sign out when using a shared device.</p>
    </section>

    <section id="privacy-choices" class="tg-policy-section">
        <div class="tg-policy-section-heading"><span aria-hidden="true">05</span><h2>Your choices and privacy requests</h2></div>
        <ul>
            <li>Update available account details and notification preferences in Settings.</li>
            <li>Allow or deny optional microphone, location, and browser notification permissions. You can revoke browser permissions later.</li>
            <li>Submit only content you are authorized to share. Remove unnecessary personal details before uploading.</li>
            <li>Contact us to request access, correction, deletion, or a copy of your information, or to raise an objection to processing. We may need to verify your identity and explain any limits that apply.</li>
        </ul>
        <p>Acknowledging this notice does not enable optional permissions or subscribe you to optional notifications. If you do not want to continue, you can sign out instead of submitting new content.</p>
        <p>For information about privacy rights and complaints in the Philippines, visit the <a href="https://privacy.gov.ph/data-subject-rights/" target="_blank" rel="noopener noreferrer">National Privacy Commission</a>.</p>
    </section>

    <section id="privacy-contact" class="tg-policy-section tg-policy-contact">
        <div class="tg-policy-section-heading"><span aria-hidden="true">06</span><h2>Contact and policy updates</h2></div>
        <p>For privacy questions or requests, contact the TruthGuard project team at <a href="mailto:{{ $privacyContact }}">{{ $privacyContact }}</a>. Describe your request and the relevant account or report; do not send passwords or unnecessary sensitive information.</p>
        <p>We will display an updated notice when the policy version changes and ask you to acknowledge the current version before continuing to the workspace.</p>
        <p class="tg-policy-version">Policy version: {{ $policyVersion }}.</p>
    </section>
</div>
