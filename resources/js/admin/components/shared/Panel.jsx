export function Panel({ title, description, action, className = '', children }) {
    return (
        <section className={`truthguard-admin-panel rounded-[24px] p-5 sm:p-6 ${className}`.trim()}>
            {(title || description || action) && (
                <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        {title ? <h2 className="text-lg font-black text-slate-950">{title}</h2> : null}
                        {description ? <p className="mt-1 max-w-3xl text-sm font-medium leading-6 text-slate-500">{description}</p> : null}
                    </div>
                    {action ? <div>{action}</div> : null}
                </div>
            )}
            {children}
        </section>
    );
}
