import { useCallback, useEffect, useState } from 'react';
import { Button, Card, Popconfirm, Select, Space, Table, Tag, Typography } from 'antd';
import { ReloadOutlined } from '@ant-design/icons';
import http from '../api';

interface SsoRequestRow {
  device_code: string;
  client_id: string;
  client_name: string;
  user_code: string;
  sso_request_id: string;
  status: 'pending' | 'authorized';
  user_id: number | null;
  username: string;
  ttl: number;
  created_ago: number;
}

/** 设备/SSO 授权请求（10 分钟短命, 实时列表） */
export default function SsoRequests() {
  const [list, setList] = useState<SsoRequestRow[]>([]);
  const [statusFilter, setStatusFilter] = useState('');
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await http.get('/admin/sso-requests');
      setList(data.list || []);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
    const timer = window.setInterval(load, 10000);
    return () => window.clearInterval(timer);
  }, [load]);

  const revoke = async (row: SsoRequestRow) => {
    await http.post(`/admin/sso-requests/${row.device_code}/revoke`);
    load();
  };

  const filtered = statusFilter ? list.filter((r) => r.status === statusFilter) : list;

  return (
    <Card
      title="设备 / SSO 授权请求（实时）"
      extra={
        <Space>
          <Select
            placeholder="状态"
            allowClear
            style={{ width: 120 }}
            onChange={(v) => setStatusFilter(v || '')}
            options={[
              { value: 'pending', label: '待确认' },
              { value: 'authorized', label: '已确认' },
            ]}
          />
          <Button icon={<ReloadOutlined />} onClick={load} />
        </Space>
      }
    >
      <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>
        设备码与 SSO 登录请求为 10 分钟短生命周期，页面每 10 秒自动刷新；撤销后发起方轮询立即失效。
      </Typography.Paragraph>
      <Table<SsoRequestRow>
        rowKey="device_code"
        loading={loading}
        dataSource={filtered}
        pagination={false}
        columns={[
          { title: '应用', dataIndex: 'client_name', render: (v, r) => <span>{v} <Typography.Text type="secondary" code style={{ fontSize: 11 }}>{r.client_id}</Typography.Text></span> },
          { title: '用户码', dataIndex: 'user_code', width: 100, render: (v) => <Typography.Text code>{v}</Typography.Text> },
          { title: '确认用户', width: 150, render: (_, r) => (r.user_id ? <span>{r.username || `#${r.user_id}`}</span> : <Typography.Text type="secondary">未确认</Typography.Text>) },
          { title: '状态', dataIndex: 'status', width: 100, render: (v) => (v === 'authorized' ? <Tag color="green">已确认</Tag> : <Tag color="orange">待确认</Tag>) },
          { title: '剩余有效期', dataIndex: 'ttl', width: 110, render: (v) => `${Math.floor(v / 60)}:${String(v % 60).padStart(2, '0')}` },
          {
            title: '操作',
            width: 90,
            render: (_, r) => (
              <Popconfirm title="撤销后发起方立即失效，确定？" onConfirm={() => revoke(r)}>
                <Button size="small" danger>
                  撤销
                </Button>
              </Popconfirm>
            ),
          },
        ]}
      />
    </Card>
  );
}
