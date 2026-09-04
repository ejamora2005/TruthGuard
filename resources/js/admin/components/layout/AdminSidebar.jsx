import { NavLink } from 'react-router-dom';
import { getAdminConfig } from '../../lib/admin-config';
import { AIUsageIcon, DashboardIcon, DetectionsIcon, ProfileIcon, ProjectTrackerIcon, SourcesIcon, SubscriptionIcon, UsersIcon } from '../shared/Icons';

export const navItems = [
    { to: '/dashboard', label: 'Dashboard', icon: DashboardIcon },
    { to: '/project-tracker', label: 'Project Tracker', icon: ProjectTrackerIcon },
    { to: '/users', label: 'Users', icon: UsersIcon },
    { to: '/subscriptions', label: 'Subscriptions', icon: SubscriptionIcon },
    { to: '/detections', label: 'Detection Queue', icon: DetectionsIcon },
    { to: '/fact-check-sources', label: 'Fact Check Sources', icon: SourcesIcon },
    { to: '/ai-usage', label: 'AI Usage', icon: AIUsageIcon },
    { to: '/profile', label: 'Profile', icon: ProfileIcon },
];

export function AdminSidebar({ mobileOpen, setMobileOpen, isExpanded, isHovered, setIsHovered }) {
    const config = getAdminConfig();
    const brandLabel = config.brandName.replace(/\s+Admin$/i, '');
    const showFull = isExpanded || isHovered || mobileOpen;

    return (
        <>
            {mobileOpen ? <div className="fixed inset-0 z-[99998] bg-gray-900/50 lg:hidden" onClick={() => setMobileOpen(false)} /> : null}

            <aside
                className={[
                    'truthguard-admin-sidebar fixed left-0 top-0 z-[99999] flex h-screen flex-col border-r px-4 text-gray-900 transition-all duration-300 ease-in-out sm:px-5',
                    showFull ? 'lg:w-[290px]' : 'lg:w-[90px]',
                    mobileOpen ? 'w-[min(290px,calc(100vw-1rem))] translate-x-0 rounded-r-[28px]' : '-translate-x-full lg:translate-x-0',
                ].join(' ')}
                onMouseEnter={() => {
                    if (!isExpanded) {
                        setIsHovered(true);
                    }
                }}
                onMouseLeave={() => setIsHovered(false)}
            >
                <div className={`flex py-6 sm:py-7 ${showFull ? 'justify-start' : 'lg:justify-center'}`}>
                    <a href={config.dashboardPath} className="flex items-center gap-3 overflow-hidden">
                        <span className={`truthguard-admin-brand-mark shrink-0 ${showFull ? 'h-12 w-12' : 'h-11 w-11'}`}>
                            <img src={config.logoIconUrl} alt="TruthGuard" className="h-8 w-8 object-contain" />
                        </span>
                        {showFull ? (
                            <div className="min-w-0">
                                <p className="truncate text-xl font-black tracking-tight text-slate-950">{brandLabel}</p>
                                <p className="text-xs font-bold uppercase tracking-[0.16em] text-blue-500">Admin workspace</p>
                            </div>
                        ) : null}
                    </a>
                </div>

                <div className="no-scrollbar flex flex-1 flex-col overflow-y-auto pb-6">
                    <nav className="mb-6">
                        <div className="flex flex-col gap-4">
                            <div>
                                <h2 className={`mb-3 flex text-xs font-bold uppercase leading-[20px] tracking-[0.18em] text-slate-400 ${showFull ? 'justify-start px-2' : 'lg:justify-center'}`}>
                                    {showFull ? (
                                        <span>Admin</span>
                                    ) : (
                                        <span className="inline-flex items-center justify-center">
                                            <svg width="24" height="6" viewBox="0 0 24 6" fill="none">
                                                <circle cx="12" cy="3" r="2" fill="currentColor" />
                                                <circle cx="20" cy="3" r="2" fill="currentColor" />
                                                <circle cx="4" cy="3" r="2" fill="currentColor" />
                                            </svg>
                                        </span>
                                    )}
                                </h2>

                                <ul className="flex flex-col gap-2">
                                    {navItems.map(({ to, label, icon: Icon }) => (
                                        <li key={to}>
                                            <NavLink
                                                to={to}
                                                onClick={() => setMobileOpen(false)}
                                                className={({ isActive }) =>
                                                    `truthguard-admin-nav-item group ${isActive ? 'is-active' : ''} ${showFull ? 'lg:justify-start' : 'lg:justify-center'}`
                                                }
                                            >
                                                {() => (
                                                    <>
                                                        <span className="truthguard-admin-nav-icon">
                                                            <Icon className="h-5 w-5" />
                                                        </span>
                                                        {showFull ? <span className="truncate">{label}</span> : null}
                                                    </>
                                                )}
                                            </NavLink>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    </nav>

                    {showFull ? (
                        <div className="truthguard-admin-ops-card mt-auto rounded-[22px] px-4 py-4">
                            <p className="text-sm font-black text-slate-900">Admin Mode</p>
                            <p className="mt-1 text-sm font-medium leading-6 text-slate-500">Manage reports, users, and system activity.</p>
                        </div>
                    ) : null}
                </div>
            </aside>
        </>
    );
}
