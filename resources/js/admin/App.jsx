import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AdminShell } from './components/layout/AdminShell';
import DashboardPage from './pages/DashboardPage';
import ProjectTrackerPage from './pages/ProjectTrackerPage';
import UsersPage from './pages/UsersPage';
import SubscriptionsPage from './pages/SubscriptionsPage';
import DetectionsPage from './pages/DetectionsPage';
import FactCheckSourcesPage from './pages/FactCheckSourcesPage';
import AIUsagePage from './pages/AIUsagePage';
import ProfilePage from './pages/ProfilePage';
import NotFoundPage from './pages/NotFoundPage';

export default function App() {
    return (
        <BrowserRouter basename="/admin">
            <Routes>
                <Route element={<AdminShell />}>
                    <Route index element={<Navigate to="/dashboard" replace />} />
                    <Route path="dashboard" element={<DashboardPage />} />
                    <Route path="project-tracker" element={<ProjectTrackerPage />} />
                    <Route path="users" element={<UsersPage />} />
                    <Route path="subscriptions" element={<SubscriptionsPage />} />
                    <Route path="detections" element={<DetectionsPage />} />
                    <Route path="fact-check-sources" element={<FactCheckSourcesPage />} />
                    <Route path="ai-usage" element={<AIUsagePage />} />
                    <Route path="profile" element={<ProfilePage />} />
                    <Route path="*" element={<NotFoundPage />} />
                </Route>
            </Routes>
        </BrowserRouter>
    );
}
