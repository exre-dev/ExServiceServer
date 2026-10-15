import { useCallback, useEffect, useState } from 'react';
import { Button, Card, Input, InputNumber, Space, Table, Tag } from 'antd';
import { ReloadOutlined } from '@ant-design/icons';
import http from '../api';

interface LogRow {
  id: number;
  actor_user_id: number | null;
  actor_type: string;
  server_id: number | null;
  client_id: string | null;
  action: string;
  target_type: string;
  target_id: string;
  detail: any;
  ip: string;
  created_time: string;
}

export default function Logs() {
  const [list, setList] = useState<LogRow[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [action, setAction] = useState('');
  const [actorId, setActorId] = useState<number | undefined>(undefined);
  const [serverId, setServerId] = useState<number | undefined>(undefined);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await http.get('/admin/logs', {
        params: { page, page_size: 20, action, actor_user_id: actorId ?? '', server_id: serverId ?? '' },
      });
      setList(data.list || []);
      setTotal(data.total || 0);
    } finally {
      setLoading(false);
    }
  }, [page, action, actorId, serverId]);

  useEffect(() => {
    load();
  }, [load]);

  return (
    <Card
      title="审计日志"
      extra={
        <Space>
          <Input.Search
            placeholder="动作前缀（如 server. / user.）"
            allowClear
            onSearch={(v) => {
              setPage(1);
              setAction(v);
            }}
            style={{ width: 220 }}
          />
          <InputNumber
            placeholder="操作者 ID"
            min={1}
            onChange={(v) => {
              setPage(1);
              setActorId(v ?? undefined);
            }}
          />
          <InputNumber
            placeholder="空间 ID"
            min={1}
            onChange={(v) => {
              setPage(1);
              setServerId(v ?? undefined);
            }}
          />
          <Button icon={<ReloadOutlined />} onClick={load} />
        </Space>
      }
    >
      <Table<LogRow>
        rowKey="id"
        loading={loading}
        dataSource={list}
        pagination={{ current: page, total, pageSize: 20, onChange: setPage, showSizeChanger: false }}
        columns={[
          { title: 'ID', dataIndex: 'id', width: 70 },
          { title: '操作者', dataIndex: 'actor_user_id', width: 80, render: (v) => v ?? '-' },
          {
            title: '类型',
            dataIndex: 'actor_type',
            width: 80,
            render: (v) => (
              <Tag color={v === 'platform' ? 'purple' : v === 'server' ? 'blue' : 'default'}>{v}</Tag>
            ),
          },
          { title: '动作', dataIndex: 'action', width: 170 },
          { title: '空间', dataIndex: 'server_id', width: 70, render: (v) => v ?? '-' },
          { title: '目标', width: 150, render: (_, r) => `${r.target_type}:${r.target_id}` },
          {
            title: '详情',
            dataIndex: 'detail',
            ellipsis: true,
            render: (v) => (v ? <code style={{ fontSize: 12 }}>{JSON.stringify(v)}</code> : '-'),
          },
          { title: 'IP', dataIndex: 'ip', width: 120 },
          { title: '时间', dataIndex: 'created_time', width: 150 },
        ]}
      />
    </Card>
  );
}
