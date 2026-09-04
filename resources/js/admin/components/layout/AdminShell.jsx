import { useEffect, useMemo, useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { AdminHeader } from './AdminHeader';
import { AdminSidebar, navItems } from './AdminSidebar';

const titleMap = {
    '/dashboard': 'Dashboard',
    '/project-tracker': 'Project Tracker',
    '/users': 'Users',
    '/subscriptions': 'Subscriptions',
    '/detections': 'Detection Queue',
    '/fact-check-sources': 'Fact Check Sources',
    '/ai-usage': 'AI Usage',
    '/profile': 'Profile',
};

const mobileNavItems = ['/dashboard', '/project-tracker', '/detections', '/users', '/fact-check-sources'];
const mobileLabels = {
    '/dashboard': 'Home',
    '/project-tracker': 'Track',
    '/users': 'Users',
    '/detections': 'Queue',
    '/fact-check-sources': 'Sources',
};

function formatTruthGuardTitle(title) {
    const label = String(title || 'Admin')
        .replace(/^truthguard(?:\s*\|\s*|\s+)/i, '')
        .replace(/\s+/g, ' ')
        .trim() || 'Admin';

    return `TRUTHGUARD | ${label}`;
}

function AdminMobileTabBar() {
    const location = useLocation();
    const activeIndex = Math.max(mobileNavItems.findIndex((path) => location.pathname === path), 0);

    return (
        <nav className="truthguard-admin-mobile-tabbar" style={{ '--active-index': activeIndex }} aria-label="Admin mobile navigation">
            {mobileNavItems
                .map((path) => navItems.find((item) => item.to === path))
                .filter(Boolean)
                .map(({ to, label, icon: Icon }) => (
                    <NavLink
                        key={to}
                        to={to}
                        className={({ isActive }) => `truthguard-admin-mobile-tab ${to === '/detections' ? 'truthguard-admin-mobile-tab-primary' : ''} ${isActive ? 'is-active' : ''}`}
                    >
                        <span className="truthguard-admin-mobile-tab-icon">
                            <Icon className="h-5 w-5" />
                        </span>
                        <span>{mobileLabels[to] ?? label}</span>
                    </NavLink>
                ))}
        </nav>
    );
}

export function AdminShell() {
    const location = useLocation();
    const [mobileOpen, setMobileOpen] = useState(false);
    const [sidebarExpanded, setSidebarExpanded] = useState(true);
    const [sidebarHovered, setSidebarHovered] = useState(false);

    const pageTitle = useMemo(() => formatTruthGuardTitle(titleMap[location.pathname] ?? 'Admin'), [location.pathname]);
    const showFullSidebar = sidebarExpanded || sidebarHovered || mobileOpen;

    useEffect(() => {
        document.title = pageTitle;
    }, [pageTitle]);

    const handleToggleSidebar = () => {
        if (window.innerWidth >= 1024) {
            setSidebarExpanded((value) => !value);
            return;
        }

        setMobileOpen((value) => !value);
    };

    return (
        <div className="truthguard-admin-shell min-h-screen">
            <AdminSidebar
                mobileOpen={mobileOpen}
                setMobileOpen={setMobileOpen}
                isExpanded={sidebarExpanded}
                isHovered={sidebarHovered}
                setIsHovered={setSidebarHovered}
            />

            <div className={`min-w-0 flex-1 transition-all duration-300 ease-in-out ${showFullSidebar ? 'lg:ml-[290px]' : 'lg:ml-[90px]'}`}>
                <AdminHeader title={pageTitle} onToggleSidebar={handleToggleSidebar} mobileOpen={mobileOpen} />
                <main className="mx-auto max-w-[1600px] px-3 pb-5 pt-4 sm:px-4 md:px-5 lg:px-6">
                    <Outlet />
                </main>
                <AdminMobileTabBar />
            </div>
        </div>
    );
}
