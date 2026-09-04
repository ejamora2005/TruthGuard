export function PageHeader({ eyebrow = 'TruthGuard Admin', title, description, actions = null }) {
    return (
        <div className="truthguard-admin-panel truthguard-admin-page-header rounded-[24px] px-5 py-5 sm:px-6">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p className="text-xs font-black uppercase tracking-[0.18em] text-blue-600">{eyebrow}</p>
                    <h2 className="mt-2 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">{title}</h2>
                    {description ? <p className="mt-2 max-w-3xl text-sm font-medium leading-6 text-slate-500">{description}</p> : null}
                </div>
                {actions ? <div className="flex flex-wrap items-center gap-3">{actions}</div> : null}
            </div>
        </div>
    );
}
