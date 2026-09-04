export function EmptyState({ label = 'Nothing to show yet.' }) {
    return (
        <div className="truthguard-admin-soft-card rounded-[22px] border-dashed px-5 py-10 text-center text-sm font-medium text-slate-500">
            {label}
        </div>
    );
}
