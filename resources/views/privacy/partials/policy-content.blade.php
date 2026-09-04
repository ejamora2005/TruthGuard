@php
    $policyVersion = $policyVersion ?? (string) config('app.privacy_policy_version', '2026-07-28');
@endphp

<div class="space-y-6 text-sm leading-7 text-slate-600">
    <section>
        <h2 class="text-base font-black text-slate-950">Privacy Notice</h2>
        <p class="mt-2">
            TruthGuard processes your account information and submitted content to provide AI-assisted fact-checking,
            security, notifications, and account services. This notice explains what we collect, why we use it, and
            the choices available to you.
        </p>
    </section>

    <section class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-2xl border border-slate-200 bg-white/80 p-4">
            <h3 class="font-bold text-slate-950">Data We Collect</h3>
            <ul class="mt-2 space-y-1.5">
                <li>Name, email address, login credentials, and profile details.</li>
                <li>Google account ID, avatar, and verified email status when you use Google login.</li>
                <li>Uploaded evidence, source links, captions, notes, and fact-check history.</li>
                <li>Notifications, email preferences, device/session activity, and login timestamps.</li>
            </ul>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white/80 p-4">
            <h3 class="font-bold text-slate-950">Why We Use It</h3>
            <ul class="mt-2 space-y-1.5">
                <li>Create and secure your account.</li>
                <li>Analyze submitted claims and generate AI-assisted reports.</li>
                <li>Send welcome emails, result updates, and security alerts.</li>
                <li>Prevent abuse, manage inactivity timeouts, and improve TruthGuard.</li>
            </ul>
        </div>
    </section>

    <section>
        <h2 class="text-base font-black text-slate-950">AI and Verification Services</h2>
        <p class="mt-2">
            TruthGuard may send claim text, source context, or limited evidence details to OpenAI to generate
            AI-assisted explanations. TruthGuard may also use Google login, Google Fact Check sources, public claim
            review feeds, news sources, weather references, and email delivery services where needed to operate the
            platform. AI can make mistakes, so important details should be verified before sharing.
        </p>
    </section>

    <section class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-2xl border border-blue-100 bg-blue-50/70 p-4">
            <h3 class="font-bold text-blue-950">Retention</h3>
            <p class="mt-2">
                Fact-check records are intended to be kept for the last 7 days unless a longer period is required for
                security, legal, operational, or account-support reasons. Account data remains while your account is
                active or as required to provide the service.
            </p>
        </div>

        <div class="rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4">
            <h3 class="font-bold text-emerald-950">Security</h3>
            <p class="mt-2">
                TruthGuard uses session protections, CSRF protection, role-based access controls, password protection,
                inactivity timeout, and secure storage practices to help protect your account and submitted content.
            </p>
        </div>
    </section>

    <section>
        <h2 class="text-base font-black text-slate-950">Your Choices and Rights</h2>
        <p class="mt-2">
            You may request access to your data, correct profile information, delete your account, export data where
            supported, withdraw optional consent where applicable, and ask questions about privacy or security.
            Requests can be sent through the contact channel provided by TruthGuard.
        </p>
    </section>

    <section class="rounded-2xl border border-amber-200 bg-amber-50/80 p-4 text-amber-900">
        <h2 class="font-black">Important Note</h2>
        <p class="mt-2">
            This policy is written for product transparency and should be reviewed by a qualified legal professional
            before public deployment. Policy version: {{ $policyVersion }}.
        </p>
    </section>
</div>
