import { useMemo, useState } from 'react';
import { PageHeader } from '../components/shared/PageHeader';
import { Panel } from '../components/shared/Panel';
import { StatCard } from '../components/shared/StatCard';
import { LoadingState } from '../components/shared/LoadingState';
import { EmptyState } from '../components/shared/EmptyState';
import { useAdminQuery } from '../hooks/useAdminQuery';
import { sendAdminJson } from '../lib/admin-api';

const initialData = { summaryCards: [], recentAnnouncements: [] };

const defaultForm = {
    audience: 'me',
    title: 'TruthGuard system update',
    message: '',
    update_at: '',
    action_url: '/notifications',
    action_label: 'Open',
};

function localDateTimeValue(daysFromNow = 1) {
    const date = new Date();
    date.setDate(date.getDate() + daysFromNow);
    date.setMinutes(0, 0, 0);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}T${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

export default function AnnouncementsPage() {
    const { data, loading, error, load } = useAdminQuery('/announcements', initialData);
    const [form, setForm] = useState(defaultForm);
    const [submitting, setSubmitting] = useState(false);
    const [submitError, setSubmitError] = useState('');
    const [result, setResult] = useState(null);

    const messageLength = useMemo(() => form.message.length, [form.message]);

    const updateField = (field, value) => {
        setForm((current) => ({ ...current, [field]: value }));
    };

    const useMaintenanceSample = () => {
        setForm({
            audience: 'me',
            title: 'TruthGuard maintenance advisory',
            message: 'Heads up: TruthGuard will have a short system update window. Some analysis and notification features may be briefly delayed while the update is applied.',
            update_at: localDateTimeValue(1),
            action_url: '/notifications',
            action_label: 'View notice',
        });
        setResult(null);
        setSubmitError('');
    };

    const submitAnnouncement = async (event) => {
        event.preventDefault();
        setSubmitting(true);
        setSubmitError('');
        setResult(null);

        try {
            const payload = await sendAdminJson('/announcements', {
                body: {
                    ...form,
                    message: form.message.trim(),
                    title: form.title.trim(),
                    action_label: form.action_label.trim(),
                    action_url: form.action_url.trim(),
                    update_at: form.update_at || null,
                },
            });
            setResult(payload.announcement);
            await load();
        } catch (error) {
            setSubmitError(error.message || 'Unable to send announcement right now.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="space-y-6">
            <PageHeader
                eyebrow="System advisories"
                title="Announcements"
                description="Send maintenance notices, release advisories, and push-notification tests through the same notification pipeline users receive."
                actions={
                    <>
                        <button type="button" onClick={useMaintenanceSample} className="truthguard-admin-action">
                            Use sample notice
                        </button>
                        <button type="button" onClick={load} className="truthguard-admin-action">
                            Refresh
                        </button>
                    </>
                }
            />

            {error ? <div className="rounded-2xl border border-error-200 bg-error-50 px-5 py-4 text-sm font-semibold text-error-600">{error}</div> : null}
            {loading && !data.summaryCards.length ? <LoadingState label="Loading announcement tools..." /> : null}

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                {data.summaryCards.map((card) => (
                    <StatCard key={card.label} label={card.label} value={card.value} note={card.note} />
                ))}
            </div>

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(22rem,0.65fr)]">
                <Panel
                    title="Compose advisory"
                    description="Start with Me only to test in-app and push behavior on your admin account. Choose all accounts when the wording is final."
                >
                    <form className="space-y-5" onSubmit={submitAnnouncement}>
                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <label className="truthguard-admin-field-label" htmlFor="announcement_audience">Audience</label>
                                <select
                                    id="announcement_audience"
                                    className="truthguard-admin-input min-h-12 px-4"
                                    value={form.audience}
                                    onChange={(event) => updateField('audience', event.target.value)}
                                >
                                    <option value="me">Me only</option>
                                    <option value="all">All accounts</option>
                                    <option value="users">Regular users</option>
                                    <option value="admins">Admins</option>
                                </select>
                            </div>

                            <div>
                                <label className="truthguard-admin-field-label" htmlFor="announcement_update_at">System update date</label>
                                <input
                                    id="announcement_update_at"
                                    type="datetime-local"
                                    className="truthguard-admin-input min-h-12 px-4"
                                    value={form.update_at}
                                    onChange={(event) => updateField('update_at', event.target.value)}
                                />
                            </div>
                        </div>

                        <div>
                            <label className="truthguard-admin-field-label" htmlFor="announcement_title">Title</label>
                            <input
                                id="announcement_title"
                                className="truthguard-admin-input min-h-12 px-4"
                                value={form.title}
                                maxLength={90}
                                onChange={(event) => updateField('title', event.target.value)}
                                placeholder="TruthGuard system update"
                                required
                            />
                        </div>

                        <div>
                            <div className="mb-2 flex items-center justify-between gap-3">
                                <label className="truthguard-admin-field-label mb-0" htmlFor="announcement_message">Message</label>
                                <span className="text-xs font-bold text-slate-400">{messageLength}/600</span>
                            </div>
                            <textarea
                                id="announcement_message"
                                className="truthguard-admin-input min-h-40 resize-y px-4 py-3 leading-6"
                                value={form.message}
                                maxLength={600}
                                onChange={(event) => updateField('message', event.target.value)}
                                placeholder="Example: TruthGuard will have a short update window tomorrow at 10:00 AM. Analysis may be briefly delayed."
                                required
                            />
                        </div>

                        <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_12rem]">
                            <div>
                                <label className="truthguard-admin-field-label" htmlFor="announcement_action_url">Action URL</label>
                                <input
                                    id="announcement_action_url"
                                    className="truthguard-admin-input min-h-12 px-4"
                                    value={form.action_url}
                                    onChange={(event) => updateField('action_url', event.target.value)}
                                    placeholder="/notifications"
                                />
                                <p className="mt-2 text-xs font-semibold leading-5 text-slate-400">Allowed app links include /notifications, /profile, /claim-reviews, /dashboard/fact-checks/&lt;id&gt;, and detection result links.</p>
                            </div>

                            <div>
                                <label className="truthguard-admin-field-label" htmlFor="announcement_action_label">Button label</label>
                                <input
                                    id="announcement_action_label"
                                    className="truthguard-admin-input min-h-12 px-4"
                                    value={form.action_label}
                                    maxLength={24}
                                    onChange={(event) => updateField('action_label', event.target.value)}
                                    placeholder="Open"
                                />
                            </div>
                        </div>

                        {submitError ? <div className="rounded-2xl border border-error-200 bg-error-50 px-4 py-3 text-sm font-semibold text-error-600">{submitError}</div> : null}

                        {result ? (
                            <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold leading-6 text-emerald-700">
                                Sent to {result.audience}: {result.notificationsCreated} in-app notice{result.notificationsCreated === 1 ? '' : 's'} created
                                {result.firebaseEnabled ? ` and ${result.pushJobsQueued} push job${result.pushJobsQueued === 1 ? '' : 's'} queued.` : '. Firebase is disabled, so no push jobs were queued.'}
                            </div>
                        ) : null}

                        <div className="flex flex-col gap-3 border-t border-slate-200/80 pt-5 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-sm font-semibold leading-6 text-slate-500">
                                To test push, enable browser notifications on this device in Settings, then send to Me only.
                            </p>
                            <button
                                type="submit"
                                disabled={submitting}
                                className="truthguard-admin-action is-primary inline-flex min-h-12 items-center justify-center px-5"
                            >
                                {submitting ? 'Sending...' : 'Send announcement'}
                            </button>
                        </div>
                    </form>
                </Panel>

                <Panel title="Recent admin notices" description="Latest announcement batches created from this admin tool.">
                    {data.recentAnnouncements.length ? (
                        <div className="space-y-3">
                            {data.recentAnnouncements.map((announcement) => (
                                <article key={announcement.id} className="truthguard-admin-update-card rounded-2xl p-4">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="truthguard-admin-status-pill is-active">{announcement.audience}</span>
                                        {announcement.updateAt ? <span className="truthguard-admin-status-pill is-neutral">{announcement.updateAt}</span> : null}
                                    </div>
                                    <h3 className="mt-3 text-sm font-black text-slate-950">{announcement.title}</h3>
                                    <p className="mt-2 text-sm font-medium leading-6 text-slate-500">{announcement.message}</p>
                                    <p className="mt-3 text-xs font-bold uppercase tracking-[0.14em] text-slate-400">
                                        {announcement.sentAt} by {announcement.sentBy}
                                    </p>
                                </article>
                            ))}
                        </div>
                    ) : (
                        <EmptyState label="No admin announcements sent yet." />
                    )}
                </Panel>
            </div>
        </div>
    );
}
