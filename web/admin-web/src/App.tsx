import { Routes, Route, Navigate, Outlet } from 'react-router-dom';
import { Card } from 'antd';
import { useAuth } from './store';
import AdminLayout from './layouts/AdminLayout';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import Servers from './pages/Servers';
import Users from './pages/Users';
import Plugins from './pages/Plugins';
import Bindings from './pages/Bindings';
import Clients from './pages/Clients';
import Logs from './pages/Logs';
import ServerLayout from './pages/server/ServerLayout';
import ServerOverview from './pages/server/Overview';
import ServerMembers from './pages/server/Members';
import ServerIdents from './pages/server/Idents';
import ServerBindings from './pages/server/Bindings';
import ServerPerms from './pages/server/Perms';
import ServerClients from './pages/server/Clients';
import Security from './pages/Security';
import Docs from './pages/Docs';
import ServerDocs from './pages/server/Docs';

/** 登录保护 */
function RequireAuth() {
  const token = useAuth((s) => s.token);
  if (!token) return <Navigate to="/login" replace />;
  return <Outlet />;
}

/** 首页分流: 平台管理员看平台概览, 空间管理员进第一个空间 */
function Landing() {
  const { user, servers, serversLoaded } = useAuth();
  if (user?.platform_role) return <Dashboard />;
  if (!serversLoaded) return null;
  if (servers.length) return <Navigate to={`/server/${servers[0].id}`} replace />;
  return (
    <Card style={{ margin: 24 }}>
      当前账号没有可管理的空间。如需开通，请联系平台管理员。
    </Card>
  );
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route element={<RequireAuth />}>
        <Route element={<AdminLayout />}>
          <Route path="/" element={<Landing />} />
          <Route path="/servers" element={<Servers />} />
          <Route path="/users" element={<Users />} />
          <Route path="/plugins" element={<Plugins />} />
          <Route path="/bindings" element={<Bindings />} />
          <Route path="/clients" element={<Clients />} />
          <Route path="/logs" element={<Logs />} />
          <Route path="/security" element={<Security />} />
          <Route path="/docs" element={<Docs />} />
          <Route path="/server/:sid" element={<ServerLayout />}>
            <Route index element={<ServerOverview />} />
            <Route path="members" element={<ServerMembers />} />
            <Route path="idents" element={<ServerIdents />} />
            <Route path="bindings" element={<ServerBindings />} />
            <Route path="perms" element={<ServerPerms />} />
            <Route path="docs" element={<ServerDocs />} />
            <Route path="clients" element={<ServerClients />} />
          </Route>
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
