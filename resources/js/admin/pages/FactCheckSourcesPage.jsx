import { useEffect, useMemo, useState } from 'react';
import { fetchAdminJson, sendAdminJson } from '../lib/admin-api';
import { EmptyState } from '../components/shared/EmptyState';
import { LoadingState } from '../components/shared/LoadingState';
import { PageHeader } from '../components/shared/PageHeader';
import { Panel } from '../components/shared/Panel';
import { StatCard } from '../components/shared/StatCard';

const blankForm = {
    id: null,
    key: '',
    name: '',
    domain: '',
    category: 'fact_check',
    url_template: '',
    ready_selectors: '',
    article_selectors: '',
    exclude_selectors: '',
    is_enabled: true,
    notes: '',
};

const initialData = {
    summaryCards: [],
    categories: [],
    customSources: [],
    builtInSources: [],
};

function sourceToForm(source) {
    return {
        id: source.id,
        key: source.key ?? '',
        name: source.name ?? '',
        domain: source.domain ?? '',
        category: source.category ?? 'fact_check',
        url_template: source.urlTemplate ?? '',
        ready_selectors: source.readySelectorsText ?? '',
        article_selectors: source.articleSelectorsText ?? '',
        exclude_selectors: source.excludeSelectorsText ?? '',
        is_enabled: Boolean(source.isEnabled),
        notes: source.notes ?? '',
    };
}

function SourceBadge({ enabled, type }) {
    const statusClass = enabled ? 'is-added' : 'is-neutral';

    return (
        <span className={`truthguard-admin-status-pill ${statusClass}`}>
            {type === 'built_in' ? 'Built-in' : enabled ? 'Enabled' : 'Disabled'}
        </span>
    );
}

export default function FactCheckSourcesPage() {
    const [data, setData] = useState(initialData);
    const [form, setForm] = useState(blankForm);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');

    const isEditing = form.id !== null;
    const allSources = useMemo(() => [...data.customSources, ...data.builtInSources], [data.customSources, data.builtInSources]);

    const load = async () => {
        setLoading(true);
        setError('');

        try {
            setData(await fetchAdminJson('/fact-check-sources'));
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Unable to load sources right now.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
    }, []);

    const updateField = (field, value) => {
        setForm((current) => ({ ...current, [field]: value }));
    };

    const resetForm = () => {
        setForm(blankForm);
        setNotice('');
        setError('');
    };

    const saveSource = async (event) => {
        event.preventDefault();
        setSaving(true);
        setError('');
        setNotice('');

        try {
            const endpoint = isEditing ? `/fact-check-sources/${form.id}` : '/fact-check-sources';
            const method = isEditing ? 'PATCH' : 'POST';
            setData(await sendAdminJson(endpoint, { method, body: form }));
            setForm(blankForm);
            setNotice(isEditing ? 'Source updated.' : 'Source added.');
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Unable to save this source right now.');
        } finally {
            setSaving(false);
        }
    };

    const toggleSource = async (source) => {
        setSaving(true);
        setError('');
        setNotice('');

        try {
            const payload = {
                ...sourceToForm(source),
                is_enabled: !source.isEnabled,
            };

            setData(await sendAdminJson(`/fact-check-sources/${source.id}`, { method: 'PATCH', body: payload }));
            setNotice(payload.is_enabled ? 'Source enabled.' : 'Source disabled.');
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Unable to update this source right now.');
        } finally {
            setSaving(false);
        }
    };

    const deleteSource = async (source) => {
        if (!window.confirm(`Remove ${source.name} from custom sources?`)) {
            return;
        }

        setSaving(true);
        setError('');
        setNotice('');

        try {
            setData(await sendAdminJson(`/fact-check-sources/${source.id}`, { method: 'DELETE' }));
            if (form.id === source.id) {
                setForm(blankForm);
            }
            setNotice('Source removed.');
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Unable to remove this source right now.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="space-y-6">
            <PageHeader
                eyebrow="TruthGuard Evidence"
                title="Fact Check Sources"
                description="Add and manage the publishers, newsrooms, and official references TruthGuard can use during source checks."
                actions={
                    <button onClick={load} className="truthguard-admin-action">
                        Refresh
                    </button>
                }
            />

            {error ? <div className="truthguard-admin-alert rounded-[18px] px-5 py-4 text-sm font-semibold">{error}</div> : null}
            {notice ? <div className="rounded-[18px] border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-700">{notice}</div> : null}
            {loading && !allSources.length ? <LoadingState label="Loading fact-check sources..." /> : null}

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                {data.summaryCards.map((card) => (
                    <StatCard key={card.label} label={card.label} value={card.value} note={card.note} />
                ))}
            </div>

            <div className="grid gap-6 xl:grid-cols-[420px_minmax(0,1fr)]">
                <Panel title={isEditing ? 'Edit Source' : 'Add Source'} description="Custom sources are saved for future verification runs.">
                    <form onSubmit={saveSource} className="space-y-4">
                        <div>
                            <label className="truthguard-admin-field-label" htmlFor="source-name">Source Name</label>
                            <input
                                id="source-name"
                                value={form.name}
                                onChange={(event) => updateField('name', event.target.value)}
                                className="truthguard-admin-input"
                                placeholder="Rappler Fact Check"
                                required
                            />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className="truthguard-admin-field-label" htmlFor="source-category">Category</label>
                                <select
                                    id="source-category"
                                    value={form.category}
                                    onChange={(event) => updateField('category', event.target.value)}
                                    className="truthguard-admin-input"
                                >
                                    {data.categories.map((category) => (
                                        <option key={category.value} value={category.value}>{category.label}</option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="truthguard-admin-field-label" htmlFor="source-domain">Domain</label>
                                <input
                                    id="source-domain"
                                    value={form.domain}
                                    onChange={(event) => updateField('domain', event.target.value)}
                                    className="truthguard-admin-input"
                                    placeholder="rappler.com"
                                />
                            </div>
                        </div>

                        <div>
                            <label className="truthguard-admin-field-label" htmlFor="source-url-template">Search URL</label>
                            <input
                                id="source-url-template"
                                value={form.url_template}
                                onChange={(event) => updateField('url_template', event.target.value)}
                                className="truthguard-admin-input"
                                placeholder="https://example.com/search?q={query}"
                                required
                            />
                        </div>

                        <div>
                            <label className="truthguard-admin-field-label" htmlFor="source-key">Source Key</label>
                            <input
                                id="source-key"
                                value={form.key}
                                onChange={(event) => updateField('key', event.target.value)}
                                className="truthguard-admin-input"
                                placeholder="rappler-fact-check"
                            />
                        </div>

                        <div className="truthguard-admin-soft-card rounded-[18px] p-4">
                            <label className="flex items-center justify-between gap-4">
                                <span>
                                    <span className="block text-sm font-black text-slate-900">Enabled</span>
                                    <span className="mt-1 block text-xs font-medium text-slate-500">Available to verification runs</span>
                                </span>
                                <input
                                    type="checkbox"
                                    checked={form.is_enabled}
                                    onChange={(event) => updateField('is_enabled', event.target.checked)}
                                    className="h-5 w-5 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                />
                            </label>
                        </div>

                        <div>
                            <label className="truthguard-admin-field-label" htmlFor="source-article-selectors">Article Selectors</label>
                            <input
                                id="source-article-selectors"
                                value={form.article_selectors}
                                onChange={(event) => updateField('article_selectors', event.target.value)}
                                className="truthguard-admin-input"
                                placeholder="main article, .post-card"
                            />
                        </div>

                        <div>
                            <label className="truthguard-admin-field-label" htmlFor="source-ready-selectors">Ready Selectors</label>
                            <input
                                id="source-ready-selectors"
                                value={form.ready_selectors}
                                onChange={(event) => updateField('ready_selectors', event.target.value)}
                                className="truthguard-admin-input"
                                placeholder="main article"
                            />
                        </div>

                        <div>
                            <label className="truthguard-admin-field-label" htmlFor="source-exclude-selectors">Exclude Selectors</label>
                            <input
                                id="source-exclude-selectors"
                                value={form.exclude_selectors}
                                onChange={(event) => updateField('exclude_selectors', event.target.value)}
                                className="truthguard-admin-input"
                                placeholder="header, footer, .ads"
                            />
                        </div>

                        <div>
                            <label className="truthguard-admin-field-label" htmlFor="source-notes">Notes</label>
                            <textarea
                                id="source-notes"
                                value={form.notes}
                                onChange={(event) => updateField('notes', event.target.value)}
                                className="truthguard-admin-input min-h-24 resize-y py-3"
                                placeholder="Fact-check publisher for Philippines misinformation claims."
                            />
                        </div>

                        <div className="flex flex-wrap gap-3">
                            <button type="submit" className="truthguard-admin-action is-primary" disabled={saving}>
                                {saving ? 'Saving...' : isEditing ? 'Save Source' : 'Add Source'}
                            </button>
                            {isEditing ? (
                                <button type="button" onClick={resetForm} className="truthguard-admin-action">
                                    Cancel
                                </button>
                            ) : null}
                        </div>
                    </form>
                </Panel>

                <div className="space-y-6">
                    <Panel title="Custom Sources" description="Admin-added sources can be edited, disabled, or removed.">
                        {data.customSources.length ? (
                            <div className="grid gap-4 lg:grid-cols-2">
                                {data.customSources.map((source) => (
                                    <article key={source.id} className="truthguard-admin-source-card rounded-[20px] p-5">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="text-xs font-black uppercase tracking-[0.16em] text-blue-600">{source.categoryLabel}</p>
                                                <h3 className="mt-2 truncate text-base font-black text-slate-950">{source.name}</h3>
                                                <p className="mt-1 truncate text-sm font-medium text-slate-500">{source.domain || 'No domain'}</p>
                                            </div>
                                            <SourceBadge enabled={source.isEnabled} type={source.sourceType} />
                                        </div>

                                        <p className="mt-4 break-all text-xs font-medium leading-6 text-slate-500">{source.urlTemplate}</p>
                                        {source.notes ? <p className="mt-3 text-sm font-medium leading-6 text-slate-600">{source.notes}</p> : null}

                                        <div className="mt-4 flex flex-wrap gap-2">
                                            <button type="button" onClick={() => setForm(sourceToForm(source))} className="truthguard-admin-action">
                                                Edit
                                            </button>
                                            <button type="button" onClick={() => toggleSource(source)} className="truthguard-admin-action">
                                                {source.isEnabled ? 'Disable' : 'Enable'}
                                            </button>
                                            <button type="button" onClick={() => deleteSource(source)} className="truthguard-admin-action is-danger">
                                                Remove
                                            </button>
                                        </div>
                                    </article>
                                ))}
                            </div>
                        ) : (
                            <EmptyState label="No custom sources added yet." />
                        )}
                    </Panel>

                    <Panel title="Built-In Sources" description="System defaults stay active unless changed in configuration.">
                        {data.builtInSources.length ? (
                            <div className="grid gap-4 lg:grid-cols-2">
                                {data.builtInSources.map((source) => (
                                    <article key={source.key} className="truthguard-admin-source-card rounded-[20px] p-5">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="text-xs font-black uppercase tracking-[0.16em] text-blue-600">{source.categoryLabel}</p>
                                                <h3 className="mt-2 truncate text-base font-black text-slate-950">{source.name}</h3>
                                                <p className="mt-1 truncate text-sm font-medium text-slate-500">{source.domain || 'Configured source'}</p>
                                            </div>
                                            <SourceBadge enabled={source.isEnabled} type={source.sourceType} />
                                        </div>
                                        <p className="mt-4 break-all text-xs font-medium leading-6 text-slate-500">{source.urlTemplate}</p>
                                    </article>
                                ))}
                            </div>
                        ) : (
                            <EmptyState label="No built-in sources found." />
                        )}
                    </Panel>
                </div>
            </div>
        </div>
    );
}
