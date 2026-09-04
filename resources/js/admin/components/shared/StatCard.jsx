export function StatCard({ label, value, note, badge, badgeClass = 'bg-gray-100 text-gray-700', icon = null, tone = 'blue' }) {
    return (
        <div className={`truthguard-admin-stat truthguard-admin-stat-${tone} rounded-[22px] p-5`}>
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    {icon ? <span className="truthguard-admin-stat-icon mb-4">{icon}</span> : null}
                    <p className="text-xs font-black uppercase tracking-[0.16em] text-slate-400">{label}</p>
                    <h3 className="mt-3 text-2xl font-black tracking-tight text-slate-950">{value}</h3>
                    {note ? <p className="mt-2 text-sm font-medium leading-6 text-slate-500">{note}</p> : null}
                </div>
                {badge ? (
                    <span className={`rounded-full px-2.5 py-1 text-xs font-bold ${badgeClass}`}>{badge}</span>
                ) : null}
            </div>
            <div className="truthguard-admin-stat-meter mt-5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                <span className="block h-full w-2/3 rounded-full" />
            </div>
        </div>
    );
}
