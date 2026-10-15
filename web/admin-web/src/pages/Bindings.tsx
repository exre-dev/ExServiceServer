import { useCallback, useEffect, useState } from 'react';
import { Button, Card, Input, Popconfirm, Select, Space, Table, Tag, message } from 'antd';
import { ReloadOutlined } from '@ant-design/icons';
import http from '../api';

interface BindingRow {
  id: number;
  code: string;
  status: string;
  server_id: number;
  server: { name: string; slug: string; status: string } | null;
  plugin: { name: string; slug: string; status: string } | null;
  expires_time: string | null;
  last_heartbeat: string | null;
  last_heartbeat_ip: string;
  health: 'ok' | 'stale' | 'never' | 'inactive';
  created_time: string;
}

const HEALTH = {
  ok: { color: 'green', text: '心跳正常' },
  stale: { color: 'red', text: '心跳超时' },
  never: { color: 'default', text: '未激活' },
  inactive: { color: 'orange', text: '已失效' },
};

const STATUS_COLOR: Record<string, string> = {
  active: 'green',
  revoked: 'red',
  disabled: 'orange',
};

export default function Bindings() {
  const [list, setList] = useState<BindingRow[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [keyword, setKeyword] = useState('');
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await http.get('/admin/bindings', {
        params: { page, page_size: 10, status, keyword },
      });
      setList(data.list || []);
      setTotal(data.total || 0);
    } finally {
      setLoading(false);
    }
  }, [page, status, keyword]);

  useEffect(() => {
    load();
  }, [load]);

  const revoke = async (row: BindingRow) => {
    await http.post(`/admin/bindings/${row.id}/revoke`);
    load();
  };

  return (
    <Card
      title="绑定码监管"
      extra={
        <Space>
          <Input.Search
            placeholder="绑定码（支持前缀）"
            allowClear
            onSearch={(v) => {
              setPage(1);
              setKeyword(v);
            }}
            style={{ width: 220 }}
          />
          <Select
            placeholder="状态"
            allowClear
            style={{ width: 110 }}
            onChange={(v) => {
              setPage(1);
              setStatus(v || '');
            }}
            options={[
              { value: 'active', label: '生效' },
              { value: 'revoked', label: '已吊销' },
              { value: 'disabled', label: '已停用' },
            ]}
          />
          <Button icon={<ReloadOutlined />} onClick={load} />
        </Space>
      }
    >
      <Table<BindingRow>
        rowKey="id"
        loading={loading}
        dataSource={list}
        pagination={{ current: page, total, pageSize: 10, onChange: setPage }}
        columns={[
          { title: 'ID', dataIndex: 'id', width: 60 },
          {
            title: '绑定码',
            dataIndex: 'code',
            render: (v) => (
              <a
                onClick={() => {
                  navigator.clipboard?.writeText(v);
                  message.success('已复制');
                }}
              >
                <code>{v}</code>
              </a>
            ),
          },
          { title: '空间', render: (_, r) => r.server?.name || r.server_id },
          { title: '插件', render: (_, r) => r.plugin?.name || r.plugin_id },
          {
            title: '状态',
            dataIndex: 'status',
            width: 90,
            render: (v) => <Tag color={STATUS_COLOR[v]}>{v}</Tag>,
          },
          {
            title: '健康度',
            dataIndex: 'health',
            width: 100,
            render: (v) => <Tag color={HEALTH[v as keyof typeof HEALTH]?.color}>{HEALTH[v as keyof typeof HEALTH]?.text || v}</Tag>,
          },
          { title: '最近心跳', dataIndex: 'last_heartbeat', width: 160, render: (v) => v || '-' },
          { title: '到期时间', dataIndex: 'expires_time', width: 160, render: (v) => v || '长期' },
          {
            title: '操作',
            width: 90,
            render: (_, r) =>
              r.status === 'active' ? (
                <Popconfirm title="吊销后插件下次心跳立即失效" onConfirm={() => revoke(r)}>
                  <a style={{ color: '#ff4d4f' }}>吊销</a>
                </Popconfirm>
              ) : (
                '-'
              ),
          },
        ]}
      />
    </Card>
  );
}
