export const fallbackAdminConfig = {
    brandName: 'TruthGuard Admin',
    logoUrl: '/images/truthguard-logo-transparent.png',
    logoDarkUrl: '/images/truthguard-logo-transparent.png',
    logoIconUrl: '/images/truthguard-logo-transparent.png',
    apiBaseUrl: '/admin/api',
    logoutUrl: '/logout',
    loginUrl: '/login',
    dashboardPath: '/admin/dashboard',
    websiteUrl: '/',
    user: {
        name: 'Administrator',
        email: 'admin@truthguard.local',
        avatarUrl: null,
        initial: 'A',
    },
};

export function getAdminConfig() {
    const runtimeConfig = window.TruthGuardAdmin ?? {};

    return {
        ...fallbackAdminConfig,
        ...runtimeConfig,
        user: {
            ...fallbackAdminConfig.user,
            ...(runtimeConfig.user ?? {}),
        },
    };
}
