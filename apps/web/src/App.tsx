import { Routes, Route } from 'react-router-dom';
import Login from './routes/Login';
import Home from './routes/Home';
import RequireAuth from './routes/RequireAuth';
import Overview from './routes/Overview';
import OverviewPlugins from './routes/OverviewPlugins';
import OverviewThemes from './routes/OverviewThemes';
import SitesList from './routes/SitesList';
import SiteAdd from './routes/SiteAdd';
import SiteDetail from './routes/SiteDetail';
import Activity from './routes/Activity';
import Jobs from './routes/Jobs';
import JobDetail from './routes/JobDetail';
import { Monitoring } from './routes/Monitoring';
import { Settings } from './routes/Settings';

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route element={<RequireAuth />}>
        <Route path="/" element={<Home />} />
        <Route path="/overview" element={<Overview />} />
        <Route path="/overview/plugins" element={<OverviewPlugins />} />
        <Route path="/overview/themes" element={<OverviewThemes />} />
        <Route path="/sites" element={<SitesList />} />
        <Route path="/sites/add" element={<SiteAdd />} />
        <Route path="/sites/:id" element={<SiteDetail />} />
        <Route path="/jobs" element={<Jobs />} />
        <Route path="/jobs/:id" element={<JobDetail />} />
        <Route path="/activity" element={<Activity />} />
        <Route path="/monitoring" element={<Monitoring />} />
        <Route path="/settings" element={<Settings />} />
      </Route>
    </Routes>
  );
}
