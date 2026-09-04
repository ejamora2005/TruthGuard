import { Panel } from '../components/shared/Panel';

export default function NotFoundPage() {
    return (
        <Panel title="Page not found" description="The admin page you requested could not be found.">
            <div className="rounded-2xl border border-gray-200 bg-gray-50 px-5 py-10 text-center">
                <p className="text-lg font-semibold text-gray-900">TruthGuard Admin could not find that page.</p>
                <p className="mt-2 text-sm text-gray-500">Try going back to the dashboard.</p>
                <a href="/admin/dashboard" className="mt-5 inline-flex items-center rounded-xl bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">
                    Back to Dashboard
                </a>
            </div>
        </Panel>
    );
}
