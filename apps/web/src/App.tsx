import { Routes, Route } from 'react-router-dom';
import Login from './routes/Login';
import Home from './routes/Home';
import RequireAuth from './routes/RequireAuth';
import SitesList from './routes/SitesList';
import SiteAdd from './routes/SiteAdd';
import SiteDetail from './routes/SiteDetail';

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route element={<RequireAuth />}>
        <Route path="/" element={<Home />} />
        <Route path="/sites" element={<SitesList />} />
        <Route path="/sites/add" element={<SiteAdd />} />
        <Route path="/sites/:id" element={<SiteDetail />} />
      </Route>
    </Routes>
  );
}
