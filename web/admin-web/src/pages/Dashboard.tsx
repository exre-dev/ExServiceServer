import { useEffect, useState } from 'react';
import { Card, Col, Row, Statistic, Table, Tag } from 'antd';
import {
  UserOutlined,
  StopOutlined,
  CloudServerOutlined,
  AppstoreOutlined,
  KeyOutlined,
  DisconnectOutlined,
} from '@ant-design/icons';
import http from '../api';

interface DashboardData {
  users: { total: number; banned: number };
  servers: { active: number; suspended: number; closed: number };
  plugins: { online: number; total: number };
  bindings: {
    active: number;
    revoked: number;
    disabled: number;
    stale_heartbeat: number;
  };
  recent_logs: Array<{
    id: number;
    actor_user_id: number | null;
    actor_type: string;
    action: string;
    target_type: string;
    target_id: string;
    ip: string;
    created_time: string;
  }>;
}

export default function Dashboard() {
  const [data, setData] = useState<DashboardData | null>(null);

  useEffect(() => {
    http.get('/admin/dashboard').then(setData);
  }, []);

  if (!data) return null;

  return (
    <div>
      <Row gutter={[16, 16]}>
        <Col xs={12} md={6}>
          <Card>
            <Statistic
              title="用户总数"
              value={data.users.total}
              prefix={<UserOutlined />}
              suffix={<span style={{ fontSize: 13 }}>封禁 {data.users.banned}</span>}
            />
          </Card>
        </Col>
        <Col xs={12} md={6}>
          <Card>
            <Statistic
              title="空间（活跃/暂停）"
              value={data.servers.active}
              prefix={<CloudServerOutlined />}
              suffix={
                <span style={{ fontSize: 13 }}>
                  / {data.servers.suspended}
                  {data.servers.suspended > 0 && (
                    <StopOutlined style={{ color: '#ff4d4f', marginLeft: 4 }} />
                  )}
                </span>
              }
            />
          </Card>
        </Col>
        <Col xs={12} md={6}>
          <Card>
            <Statistic
              title="插件（在线）"
              value={data.plugins.online}
              prefix={<AppstoreOutlined />}
              suffix={<span style={{ fontSize: 13 }}>共 {data.plugins.total}</span>}
            />
          </Card>
        </Col>
        <Col xs={12} md={6}>
          <Card>
            <Statistic
              title="绑定码（生效）"
              value={data.bindings.active}
              prefix={<KeyOutlined />}
              suffix={
                <span style={{ fontSize: 13, color: data.bindings.stale_heartbeat > 0 ? '#faad14' : undefined }}>
                  失联 {data.bindings.stale_heartbeat}
                </span>
              }
            />
          </Card>
        </Col>
      </Row>

      <Card title="最近操作" style={{ marginTop: 16 }}>
        <Table<DashboardData['recent_logs'][number]>
          rowKey="id"
          size="small"
          dataSource={data.recent_logs}
          pagination={false}
          columns={[
            { title: 'ID', dataIndex: 'id', width: 70 },
            { title: '操作者', dataIndex: 'actor_user_id', width: 90 },
            {
              title: '类型',
              dataIndex: 'actor_type',
              width: 90,
              render: (v) => <Tag>{v}</Tag>,
            },
            { title: '动作', dataIndex: 'action' },
            { title: '目标', width: 160, render: (_, r) => `${r.target_type}:${r.target_id}` },
            { title: 'IP', dataIndex: 'ip', width: 130 },
            { title: '时间', dataIndex: 'created_time', width: 160 },
          ]}
        />
      </Card>

      {data.bindings.stale_heartbeat > 0 && (
        <Card style={{ marginTop: 16 }} sizes="small">
          <DisconnectOutlined style={{ color: '#faad14' }} />{' '}
          有 {data.bindings.stale_heartbeat} 个绑定码心跳超时（超过 30 分钟未上报），可在「绑定码监管」查看详情。
        </Card>
      )}
    </div>
  );
}
