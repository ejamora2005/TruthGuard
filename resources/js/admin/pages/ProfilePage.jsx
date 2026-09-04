import { getAdminConfig } from '../lib/admin-config';
import { Panel } from '../components/shared/Panel';
import { PageHeader } from '../components/shared/PageHeader';

export default function ProfilePage() {
    const config = getAdminConfig();

    return (
        <div className="space-y-6">
            <PageHeader
                eyebrow="TruthGuard Admin"
                title="Profile"
                description="Current administrator details and runtime configuration for the React admin workspace."
            />

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-[360px_minmax(0,1fr)]">
                <Panel>
                    <div className="flex flex-col items-center text-center">
                        <span className="flex h-20 w-20 items-center justify-center rounded-full bg-brand-500 text-2xl font-semibold text-white">
                            {config.user.initial}
                        </span>
                        <h2 className="mt-4 text-xl font-semibold text-gray-900">{config.user.name}</h2>
                        <p className="mt-1 text-sm text-gray-500">{config.user.email}</p>
                        <span className="mt-4 rounded-full bg-brand-50 px-3 py-1 text-xs font-medium text-brand-500">Platform administrator</span>
                    </div>
                </Panel>

                <Panel title="Admin Runtime" description="Current configuration being used by the React admin app.">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
                            <p className="text-xs uppercase tracking-[0.18em] text-gray-500">Brand</p>
                            <p className="mt-2 text-base font-semibold text-gray-900">{config.brandName}</p>
                        </div>
                        <div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
                            <p className="text-xs uppercase tracking-[0.18em] text-gray-500">Admin API</p>
                            <p className="mt-2 break-all text-sm font-medium text-gray-900">{config.apiBaseUrl}</p>
                        </div>
                        <div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
                            <p className="text-xs uppercase tracking-[0.18em] text-gray-500">Dashboard Path</p>
                            <p className="mt-2 text-base font-semibold text-gray-900">{config.dashboardPath}</p>
                        </div>
                        <div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
                            <p className="text-xs uppercase tracking-[0.18em] text-gray-500">Website</p>
                            <p className="mt-2 break-all text-sm font-medium text-gray-900">{config.websiteUrl}</p>
                        </div>
                    </div>
                </Panel>
            </div>
        </div>
    );
}
