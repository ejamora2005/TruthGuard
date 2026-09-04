import { useEffect, useMemo, useRef, useState } from 'react';
import { getAdminConfig } from '../../lib/admin-config';
import { postLogout } from '../../lib/admin-api';

function MenuGlyph({ open }) {
    if (open) {
        return (
            <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M6 6l12 12M18 6 6 18" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" />
            </svg>
        );
    }

    return (
        <svg width="16" height="12" viewBox="0 0 16 12" fill="none" aria-hidden="true">
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M0.583252 1C0.583252 0.585788 0.919038 0.25 1.33325 0.25H14.6666C15.0808 0.25 15.4166 0.585786 15.4166 1C15.4166 1.41421 15.0808 1.75 14.6666 1.75L1.33325 1.75C0.919038 1.75 0.583252 1.41422 0.583252 1ZM0.583252 11C0.583252 10.5858 0.919038 10.25 1.33325 10.25L14.6666 10.25C15.0808 10.25 15.4166 10.5858 15.4166 11C15.4166 11.4142 15.0808 11.75 14.6666 11.75L1.33325 11.75C0.919038 11.75 0.583252 11.4142 0.583252 11ZM1.33325 5.25C0.919038 5.25 0.583252 5.58579 0.583252 6C0.583252 6.41421 0.919038 6.75 1.33325 6.75L7.99992 6.75C8.41413 6.75 8.74992 6.41421 8.74992 6C8.74992 5.58579 8.41413 5.25 7.99992 5.25L1.33325 5.25Z"
                fill="currentColor"
            />
        </svg>
    );
}

function CalendarIcon() {
    return (
        <svg className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M8 7V4m8 3V4M4.5 10.25h15M6 6h12a1.8 1.8 0 0 1 1.8 1.8v10.4A1.8 1.8 0 0 1 18 20H6a1.8 1.8 0 0 1-1.8-1.8V7.8A1.8 1.8 0 0 1 6 6Z" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" />
            <path d="M12 13v2.8l1.8 1.1" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function GearIcon() {
    return (
        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M12 15.4a3.4 3.4 0 1 0 0-6.8 3.4 3.4 0 0 0 0 6.8Z" stroke="currentColor" strokeWidth="1.9" />
            <path d="M18.1 13.6c.08-.52.08-1.04 0-1.6l1.55-1.2-1.5-2.6-1.86.75a6.7 6.7 0 0 0-1.36-.78L14.65 6h-3.3l-.28 2.17c-.48.2-.93.46-1.36.78L7.85 8.2l-1.5 2.6L7.9 12c-.08.54-.08 1.08 0 1.6l-1.55 1.2 1.5 2.6 1.86-.75c.42.32.88.58 1.36.78l.28 2.17h3.3l.28-2.17c.48-.2.94-.46 1.36-.78l1.86.75 1.5-2.6-1.55-1.2Z" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function BellIcon() {
    return (
        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M14.8 18.2a2.9 2.9 0 0 1-5.6 0" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" />
            <path d="M18.3 15.7H5.7l1.25-1.9a3 3 0 0 0 .5-1.66v-2.1a4.55 4.55 0 1 1 9.1 0v2.1c0 .59.17 1.16.5 1.66l1.25 1.9Z" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function LogoutIcon() {
    return (
        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M15 17l5-5-5-5M20 12H9M11 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function CloseIcon() {
    return (
        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M6 18 18 6M6 6l12 12" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" />
        </svg>
    );
}

function SpinnerIcon() {
    return (
        <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle className="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-90" fill="currentColor" d="M3 12a9 9 0 0 1 9-9v4a5 5 0 0 0-5 5H3Z" />
        </svg>
    );
}

function PhilippinesFlag() {
    return (
        <svg className="h-6 w-6" viewBox="0 0 36 24" fill="none" aria-hidden="true">
            <clipPath id="truthguard-admin-ph-flag-clip">
                <rect width="36" height="24" rx="6" />
            </clipPath>
            <g clipPath="url(#truthguard-admin-ph-flag-clip)">
                <rect width="36" height="12" fill="#0038A8" />
                <rect y="12" width="36" height="12" fill="#CE1126" />
                <path d="M0 0 17 12 0 24V0Z" fill="#fff" />
                <circle cx="6.2" cy="12" r="2.1" fill="#FCD116" />
                <path d="M6.2 6.2 6.8 7.55l1.47.12-1.12.96.34 1.43-1.29-.75-1.29.75.34-1.43-1.12-.96 1.47-.12.6-1.35Z" fill="#FCD116" transform="scale(.56) translate(5.1 4.5)" />
                <path d="M6.2 6.2 6.8 7.55l1.47.12-1.12.96.34 1.43-1.29-.75-1.29.75.34-1.43-1.12-.96 1.47-.12.6-1.35Z" fill="#FCD116" transform="scale(.56) translate(5.1 23.8)" />
                <path d="M6.2 6.2 6.8 7.55l1.47.12-1.12.96.34 1.43-1.29-.75-1.29.75.34-1.43-1.12-.96 1.47-.12.6-1.35Z" fill="#FCD116" transform="scale(.56) translate(23.4 14.1)" />
            </g>
            <rect x=".5" y=".5" width="35" height="23" rx="5.5" stroke="rgba(15,23,42,.12)" />
        </svg>
    );
}

export function AdminHeader({ title, onToggleSidebar, mobileOpen }) {
    const config = getAdminConfig();
    const [now, setNow] = useState(() => new Date());
    const [profileOpen, setProfileOpen] = useState(false);
    const [logoutConfirmOpen, setLogoutConfirmOpen] = useState(false);
    const [logoutSubmitting, setLogoutSubmitting] = useState(false);
    const [logoutError, setLogoutError] = useState('');
    const profileRef = useRef(null);

    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 30000);
        return () => window.clearInterval(timer);
    }, []);

    useEffect(() => {
        const handlePointerDown = (event) => {
            if (profileRef.current && !profileRef.current.contains(event.target)) {
                setProfileOpen(false);
            }
        };

        const handleKeyDown = (event) => {
            if (event.key === 'Escape' && logoutConfirmOpen && !logoutSubmitting) {
                setLogoutConfirmOpen(false);
                return;
            }

            if (event.key === 'Escape') {
                setProfileOpen(false);
            }
        };

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [logoutConfirmOpen, logoutSubmitting]);

    const dateParts = useMemo(() => ({
        day: new Intl.DateTimeFormat(undefined, { weekday: 'short', month: 'short', day: 'numeric' }).format(now).toUpperCase(),
        time: new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(now),
    }), [now]);

    const requestLogout = () => {
        setProfileOpen(false);
        setLogoutError('');
        setLogoutSubmitting(false);
        setLogoutConfirmOpen(true);
    };

    const confirmLogout = async () => {
        if (logoutSubmitting) {
            return;
        }

        setLogoutSubmitting(true);
        setLogoutError('');

        try {
            await postLogout();
        } catch (error) {
            setLogoutSubmitting(false);
            setLogoutError('Unable to log out right now. Please try again.');
        }
    };

    return (
        <header className="truthguard-admin-topbar sticky top-0 z-[99997] w-full border-b">
            <div className="flex w-full items-center gap-2.5 px-3 py-2.5 sm:gap-4 sm:px-4 sm:py-3 lg:px-6 lg:py-4">
                <a href={config.dashboardPath} className="truthguard-admin-mobile-logo shrink-0 lg:hidden" aria-label="TruthGuard admin home">
                    {config.logoIconUrl ? (
                        <img src={config.logoIconUrl} alt="TruthGuard" />
                    ) : (
                        <span>TG</span>
                    )}
                </a>

                <button
                    type="button"
                    onClick={onToggleSidebar}
                    className={`truthguard-admin-shell-toggle hidden shrink-0 lg:inline-flex ${mobileOpen ? 'truthguard-admin-toggle-active' : ''}`}
                    aria-label="Toggle sidebar"
                    title="Toggle sidebar"
                >
                    <MenuGlyph open={mobileOpen} />
                </button>

                <div className="min-w-0 flex-1">
                    <h1 className="sr-only">{title}</h1>
                </div>

                <div className="hidden items-center lg:flex">
                    <div className="truthguard-admin-utility-strip flex items-center gap-1 rounded-full p-1">
                        <div className="truthguard-admin-clock-pill hidden items-center gap-2 rounded-full px-2.5 py-1 xl:flex">
                            <span className="truthguard-admin-clock-icon relative z-10 inline-flex h-7 w-7 items-center justify-center rounded-full text-blue-600">
                                <CalendarIcon />
                            </span>
                            <span className="relative z-10 min-w-[6.2rem] leading-none">
                                <span className="block text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">{dateParts.day}</span>
                                <span className="mt-0.5 block text-[13px] font-black text-slate-900">{dateParts.time}</span>
                            </span>
                        </div>

                        <a href="/admin/profile" className="truthguard-admin-top-icon" aria-label="Open settings" title="Settings">
                            <GearIcon />
                        </a>

                        <a href="/notifications" className="truthguard-admin-top-icon" aria-label="Open notifications" title="Notifications">
                            <BellIcon />
                        </a>

                        <a href={config.websiteUrl} className="truthguard-admin-top-icon truthguard-admin-country-flag" aria-label="Open website" title="Open website">
                            <PhilippinesFlag />
                        </a>

                    </div>
                </div>

                <div ref={profileRef} className="relative shrink-0">
                    <button
                        type="button"
                        onClick={() => setProfileOpen((open) => !open)}
                        className={`truthguard-admin-profile-button flex items-center justify-center rounded-full p-1.5 ${profileOpen ? 'truthguard-admin-profile-button-active' : ''}`}
                        aria-label="Open profile menu"
                        aria-expanded={profileOpen}
                    >
                        {config.user.avatarUrl ? (
                            <img src={config.user.avatarUrl} alt="Profile" className="h-9 w-9 rounded-full object-cover ring-2 ring-white" />
                        ) : (
                            <span className="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-blue-600 to-cyan-500 text-sm font-semibold text-white ring-2 ring-white">
                                {config.user.initial}
                            </span>
                        )}
                    </button>

                    {profileOpen ? (
                        <div className="truthguard-admin-profile-popover absolute right-0 z-50 mt-4 w-[min(18rem,calc(100vw-1rem))] rounded-[22px] border border-blue-100/80 p-1.5 backdrop-blur-xl sm:w-[17rem]">
                            <div className="truthguard-admin-profile-capsule rounded-[19px] border border-white/10 px-3.5 py-3">
                                <div className="relative z-10 flex items-center gap-3">
                                    <span className="truthguard-admin-profile-menu-avatar relative inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl">
                                        {config.user.avatarUrl ? (
                                            <img src={config.user.avatarUrl} alt="Profile" className="h-11 w-11 rounded-2xl object-cover ring-2 ring-white/70" />
                                        ) : (
                                            <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-600 to-cyan-500 text-sm font-semibold text-white ring-2 ring-white/70">
                                                {config.user.initial}
                                            </span>
                                        )}
                                        <span className="truthguard-admin-profile-menu-status-dot" aria-label="Active account" />
                                    </span>
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-black text-white">{config.user.name}</p>
                                        <p className="mt-1 truncate text-xs font-semibold text-blue-100/80">{config.user.email}</p>
                                    </div>
                                </div>
                            </div>

                            <div className="mt-1.5 grid gap-1.5">
                                <a
                                    href="/admin/profile"
                                    onClick={() => setProfileOpen(false)}
                                    className="truthguard-admin-profile-action group flex items-center gap-3 rounded-2xl border border-slate-100 px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:border-blue-100 hover:bg-blue-50/80 hover:text-blue-700"
                                >
                                    <span className="truthguard-admin-profile-action-icon shrink-0 text-blue-600">
                                        <GearIcon />
                                    </span>
                                    <span className="relative z-10 flex-1">Account settings</span>
                                    <span className="relative z-10 text-slate-300 transition group-hover:text-blue-400">
                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2" aria-hidden="true">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="m9 6 6 6-6 6" />
                                        </svg>
                                    </span>
                                </a>

                                <button
                                    type="button"
                                    onClick={requestLogout}
                                    className="truthguard-admin-profile-action truthguard-admin-profile-action-danger group flex w-full items-center gap-3 rounded-2xl border border-slate-100 px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:border-rose-100 hover:bg-rose-50 hover:text-rose-600"
                                >
                                    <span className="truthguard-admin-profile-action-icon shrink-0 text-rose-600">
                                        <LogoutIcon />
                                    </span>
                                    <span className="relative z-10 flex-1 text-left">Sign out</span>
                                    <span className="relative z-10 text-slate-300 transition group-hover:text-rose-400">
                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2" aria-hidden="true">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="m9 6 6 6-6 6" />
                                        </svg>
                                    </span>
                                </button>
                            </div>
                        </div>
                    ) : null}
                </div>
            </div>

            {logoutConfirmOpen ? (
                <div
                    className="truthguard-admin-logout-modal fixed inset-0"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="truthguard-admin-logout-title"
                >
                    <button
                        type="button"
                        className="truthguard-admin-logout-backdrop absolute inset-0"
                        onClick={() => {
                            if (!logoutSubmitting) {
                                setLogoutConfirmOpen(false);
                            }
                        }}
                        aria-label="Close logout confirmation"
                    />

                    <div className="truthguard-admin-logout-card fixed overflow-hidden rounded-[24px] border border-blue-100 bg-white">
                        <div className="truthguard-admin-logout-card-accent" />
                        <div className="relative space-y-4 p-5 sm:p-6">
                            <div className="flex items-start justify-between gap-4">
                                <div className="flex min-w-0 items-center gap-3">
                                    <span className="truthguard-admin-logout-brand inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl">
                                        {config.logoUrl ? (
                                            <img src={config.logoUrl} alt="TruthGuard logo" className="h-10 w-10 object-contain" />
                                        ) : (
                                            <span className="text-sm font-black text-blue-700">TG</span>
                                        )}
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block text-xs font-black uppercase tracking-[0.18em] text-blue-600">TruthGuard</span>
                                        <span className="block text-sm font-semibold text-slate-500">Admin session</span>
                                    </span>
                                </div>

                                <button
                                    type="button"
                                    className="truthguard-admin-logout-close"
                                    onClick={() => setLogoutConfirmOpen(false)}
                                    disabled={logoutSubmitting}
                                    aria-label="Close logout confirmation"
                                >
                                    <CloseIcon />
                                </button>
                            </div>

                            <div>
                                <h2 id="truthguard-admin-logout-title" className="text-2xl font-black tracking-tight text-slate-950">Log out?</h2>
                                <p className="mt-2 text-sm font-medium leading-6 text-slate-600">Your admin session will end.</p>
                            </div>

                            <div className="truthguard-admin-logout-account rounded-2xl p-3">
                                <div className="flex min-w-0 items-center gap-3">
                                    {config.user.avatarUrl ? (
                                        <img src={config.user.avatarUrl} alt="Signed-in profile" className="h-10 w-10 rounded-full object-cover ring-2 ring-blue-50" />
                                    ) : (
                                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-blue-600 to-cyan-500 text-sm font-black text-white ring-2 ring-blue-50">
                                            {config.user.initial}
                                        </span>
                                    )}
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-black text-slate-950">{config.user.name}</p>
                                        <p className="mt-0.5 truncate text-xs font-semibold text-slate-500">{config.user.email}</p>
                                    </div>
                                </div>
                            </div>

                            {logoutError ? (
                                <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{logoutError}</div>
                            ) : null}

                            <div className="flex flex-col-reverse gap-3 border-t border-slate-200/80 pt-4 sm:flex-row sm:items-center sm:justify-end">
                                <button
                                    type="button"
                                    className="truthguard-admin-action"
                                    onClick={() => setLogoutConfirmOpen(false)}
                                    disabled={logoutSubmitting}
                                >
                                    Stay signed in
                                </button>

                                <button
                                    type="button"
                                    className="truthguard-admin-logout-confirm"
                                    onClick={confirmLogout}
                                    disabled={logoutSubmitting}
                                >
                                    {logoutSubmitting ? <SpinnerIcon /> : <LogoutIcon />}
                                    <span>{logoutSubmitting ? 'Signing out...' : 'Log out'}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            ) : null}
        </header>
    );
}
