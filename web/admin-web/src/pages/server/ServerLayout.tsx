import { useEffect, useState } from 'react';
import { Outlet, useLocation, useNavigate, useParams } from 'react-router-dom';
import { Alert, Spin, Tabs, Tag, Typography } from 'antd';
import http from '../../api';

const ROLE_TAG: Record<string, { color: string; text: string }> = {
  owner: { color: 'purple', text: 'Owner' },
  admin: { color: 'blue', text: '管理员' },
  member: { color: 'default', text: '成员' },
};

const TABS = [
  { key: '', label: '概览' },
  { key: 'members', label: '成员管理' },
  { key: 'idents', label: '游戏绑定' },
  { key: 'bindings', label: '插件与绑定码' },
  { key: 'perms', label: '成员权限' },
  { key: 'docs', label: '空间文档' },
  { key: 'clients', label: '应用授权' },
];

export default function ServerLayout() {
  const { sid } = useParams();
  const location = useLocation();
  const navigate = useNavigate();
  const [info, setInfo] = useState<any>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    setInfo(null);
    setError('');
    http
      .get(`/server/${sid}`)
      .then(setInfo)
      .catch(() => setError('空间信息加载失败：可能不存在、已被暂停，或当前账号不是该空间成员。'));
  }, [sid]);

  if (error) {
    return <Alert type="error" showIcon message={error} style={{ margin: 24 }} />;
  }
  if (!info) return <Spin style={{ display: 'block', margin: '80px auto' }} />;

  const suffix = location.pathname.replace(/^\/server\/\d+\/?/, '');
  const activeKey = TABS.some((t) => t.key === suffix) ? suffix : '';

  return (
    <div>
      <div style={{ marginBottom: 8, display: 'flex', alignItems: 'center', gap: 8 }}>
        <Typography.Title level={4} style={{ margin: 0 }}>
          {info.server.name}
        </Typography.Title>
        <Tag color={ROLE_TAG[info.my_role]?.color}>{ROLE_TAG[info.my_role]?.text || info.my_role}</Tag>
        <Typography.Text type="secondary">/{info.server.slug}</Typography.Text>
      </div>
      {info.server.status !== 'active' && (
        <Alert
          type="warning"
          showIcon
          message="空间当前非 active 状态：全部绑定码授权已冻结，无法生成新绑定码。"
          style={{ marginBottom: 12 }}
        />
      )}
      <Tabs
        activeKey={activeKey}
        onChange={(k) => navigate(`/server/${sid}${k ? '/' + k : ''}`)}
        items={TABS.map((t) => ({ key: t.key, label: t.label }))}
      />
      <Outlet />
    </div>
  );
}
