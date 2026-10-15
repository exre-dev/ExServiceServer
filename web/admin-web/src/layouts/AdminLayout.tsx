import { useEffect } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import { ProLayout } from '@ant-design/pro-components';
import { Dropdown, Tag } from 'antd';
import {
  DashboardOutlined,
  CloudServerOutlined,
  TeamOutlined,
  AppstoreOutlined,
  KeyOutlined,
  ApiOutlined,
  FileSearchOutlined,
  FileTextOutlined,
  LogoutOutlined,
  SafetyOutlined,
  UserOutlined,
} from '@ant-design/icons';
import http from '../api';
import { useAuth } from '../store';

export default function AdminLayout() {
  const location = useLocation();
  const navigate = useNavigate();
  const { user, servers, serversLoaded, loadServers, logout } = useAuth();

  useEffect(() => {
    loadServers();
  }, [loadServers]);

  const doLogout = async () => {
    try {
      await http.post('/account/logout');
    } catch {
      // 后端撤销失败也照常清理本地态
    }
    logout();
    navigate('/login');
  };

  const isPlatform = !!user?.platform_role;

  const menuRoutes: any = {
    path: '/',
    routes: [
      ...(isPlatform
        ? [
            { path: '/', name: '概览', icon: <DashboardOutlined /> },
            {
              path: '/servers',
              name: '空间管理',
              icon: <CloudServerOutlined />,
              routes: [{ path: '/servers', name: '空间列表' }],
            },
            {
              path: '/users',
              name: '用户管理',
              icon: <TeamOutlined />,
              routes: [{ path: '/users', name: '用户列表' }],
            },
            {
              path: '/plugins',
              name: '插件目录',
              icon: <AppstoreOutlined />,
              routes: [{ path: '/plugins', name: '插件列表' }],
            },
            {
              path: '/bindings',
              name: '绑定码监管',
              icon: <KeyOutlined />,
              routes: [{ path: '/bindings', name: '绑定码列表' }],
            },
            {
              path: '/clients',
              name: '第三方应用',
              icon: <ApiOutlined />,
              routes: [{ path: '/clients', name: '应用列表' }],
            },
            {
              path: '/docs',
              name: '文档管理',
              icon: <FileTextOutlined />,
              routes: [{ path: '/docs', name: '平台文档' }],
            },
            {
              path: '/logs',
              name: '审计日志',
              icon: <FileSearchOutlined />,
              routes: [{ path: '/logs', name: '日志查询' }],
            },
          ]
        : []),
      ...(servers.length
        ? [
            {
              path: `/server/${servers[0].id}`,
              name: '我的空间',
              icon: <CloudServerOutlined />,
              routes: servers.map((s) => ({
                path: `/server/${s.id}`,
                name: s.name,
                icon: <CloudServerOutlined />,
              })),
            },
          ]
        : []),
    ],
  };

  return (
    <ProLayout
      title="EXRE 控制台"
      layout="mix"
      fixSiderbar
      location={{ pathname: location.pathname }}
      route={menuRoutes}
      menu={{ locale: false }}
      menuItemRender={(item, dom) => (
        <div onClick={() => item.path && navigate(item.path)}>{dom}</div>
      )}
      avatarProps={{
        icon: <UserOutlined />,
        size: 'small',
        title: user?.username || user?.email,
        render: (_, dom) => (
          <Dropdown
            menu={{
              items: [
                {
                  key: 'security',
                  icon: <SafetyOutlined />,
                  label: '账号安全',
                  onClick: () => navigate('/security'),
                },
                {
                  key: 'logout',
                  icon: <LogoutOutlined />,
                  label: '退出登录',
                  onClick: doLogout,
                },
              ],
            }}
          >
            {dom}
          </Dropdown>
        ),
      }}
    >
      {serversLoaded ? <Outlet /> : <div style={{ padding: 24 }}>加载中…</div>}
    </ProLayout>
  );
}
