import { lazy, Suspense } from 'react'
import { Routes, Route, Outlet } from 'react-router-dom'
import RequireAuth from './routes/RequireAuth'
import { AppShell } from './components/layout/AppShell'

const Login = lazy(() => import('./routes/Login'))
const Home = lazy(() => import('./routes/Home'))
const Overview = lazy(() => import('./routes/Overview'))
const OverviewPlugins = lazy(() => import('./routes/OverviewPlugins'))
const OverviewThemes = lazy(() => import('./routes/OverviewThemes'))
const SitesList = lazy(() => import('./routes/SitesList'))
const SiteAdd = lazy(() => import('./routes/SiteAdd'))
const SiteDetail = lazy(() => import('./routes/SiteDetail'))
const SiteReport = lazy(() => import('./pages/SiteReport'))
const Activity = lazy(() => import('./routes/Activity'))
const Jobs = lazy(() => import('./routes/Jobs'))
const JobDetail = lazy(() => import('./routes/JobDetail'))
const Monitoring = lazy(() => import('./routes/Monitoring').then((m) => ({ default: m.Monitoring })))
const Security = lazy(() => import('./routes/Security').then((m) => ({ default: m.Security })))
const Insights = lazy(() => import('./routes/Insights').then((m) => ({ default: m.Insights })))
const Reports = lazy(() => import('./routes/Reports'))
const Settings = lazy(() => import('./routes/Settings').then((m) => ({ default: m.Settings })))

function RouteFallback() {
  return (
    <div className="p-6">
      <div className="h-24 animate-pulse rounded-xl bg-muted" />
    </div>
  )
}

export default function App() {
  return (
    <Suspense fallback={<RouteFallback />}>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route element={<RequireAuth />}>
          <Route element={<AppShell><Outlet /></AppShell>}>
            <Route path="/" element={<Home />} />
            <Route path="/overview" element={<Overview />} />
            <Route path="/overview/plugins" element={<OverviewPlugins />} />
            <Route path="/overview/themes" element={<OverviewThemes />} />
            <Route path="/sites" element={<SitesList />} />
            <Route path="/sites/add" element={<SiteAdd />} />
            <Route path="/sites/:id" element={<SiteDetail />} />
            <Route path="/sites/:id/report" element={<SiteReport />} />
            <Route path="/jobs" element={<Jobs />} />
            <Route path="/jobs/:id" element={<JobDetail />} />
            <Route path="/activity" element={<Activity />} />
            <Route path="/monitoring" element={<Monitoring />} />
            <Route path="/security" element={<Security />} />
            <Route path="/insights" element={<Insights />} />
            <Route path="/reports" element={<Reports />} />
            <Route path="/settings" element={<Settings />} />
          </Route>
        </Route>
      </Routes>
    </Suspense>
  )
}
