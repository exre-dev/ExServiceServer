import { useCallback, useEffect, useState } from 'react';
import {
  Button,
  Card,
  Form,
  Input,
  Modal,
  Popconfirm,
  Space,
  Table,
  Tabs,
  Tag,
  message,
} from 'antd';
import { ReloadOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import http from '../api';
import { useAuth } from '../store';

interface SessionRow {
  id: number;
  login_type: string;
  client_id: string | null;
  device_name: string;
  platform: string;
  ip: string;
  scope: string;
  status: string;
  current: boolean;
  created_time: string;
  last_used_time: string | null;
}

interface GrantRow {
  client_id: string;
  app_name: string;
  app_type: string | null;
  app_status: string | null;
  scopes: string[];
  status: string;
  granted_time: string | null;
  revoked_time: string | null;
}

const PLATFORM_TEXT: Record<string, string> = {
  web: '网页',
  fivem: 'FiveM 插件',
  third_party: '第三方平台',
};

function SessionsTab() {
  const [list, setList] = useState<SessionRow[]>([]);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await http.get('/account/sessions');
      // 只展示在线设备，已下线（revoked/expired）不显示
      setList((data.list || []).filter((s: SessionRow) => s.status === 'active'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const revoke = async (sid: number) => {
    await http.post(`/account/sessions/${sid}/revoke`);
    load();
  };

  const revokeAll = async () => {
    await http.post('/account/sessions/revoke-all');
    load();
  };

  return (
    <>
      <Space style={{ marginBottom: 12 }}>
        <Button icon={<ReloadOutlined />} onClick={load} />
        <Popconfirm title="下线除当前设备外的全部登录?" onConfirm={revokeAll}>
          <Button danger>全部下线</Button>
        </Popconfirm>
      </Space>
      <Table<SessionRow>
        rowKey="id"
        size="small"
        loading={loading}
        dataSource={list}
        pagination={false}
        columns={[
          { title: '设备', dataIndex: 'device_name' },
          {
            title: '平台',
            dataIndex: 'platform',
            width: 110,
            render: (v) => <Tag>{PLATFORM_TEXT[v] || v}</Tag>,
          },
          { title: 'IP', dataIndex: 'ip', width: 120 },
          {
            title: '',
            dataIndex: 'current',
            width: 70,
            render: (v) => (v ? <Tag color="blue">当前</Tag> : null),
          },
          { title: '登录时间', dataIndex: 'created_time', width: 150 },
          { title: '最近使用', dataIndex: 'last_used_time', width: 150, render: (v) => v || '-' },
          {
            title: '操作',
            width: 80,
            render: (_, r) =>
              r.current ? (
                '-'
              ) : (
                <Popconfirm title="下线该设备?" onConfirm={() => revoke(r.id)}>
                  <a style={{ color: '#ff4d4f' }}>下线</a>
                </Popconfirm>
              ),
          },
        ]}
      />
    </>
  );
}

function GrantsTab() {
  const [list, setList] = useState<GrantRow[]>([]);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await http.get('/account/grants');
      setList(data.list || []);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const revoke = async (clientId: string) => {
    await http.post(`/account/grants/${clientId}/revoke`);
    load();
  };

  return (
    <Table<GrantRow>
      rowKey="client_id"
      size="small"
      loading={loading}
      dataSource={list}
      pagination={false}
      columns={[
        { title: '应用', dataIndex: 'app_name' },
        {
          title: '类型',
          dataIndex: 'app_type',
          width: 100,
          render: (v) => (v === 'plugin' ? <Tag color="geekblue">插件</Tag> : <Tag color="cyan">第三方</Tag>),
        },
        {
          title: '权限范围',
          dataIndex: 'scopes',
          render: (v: string[]) => v?.map((s) => <Tag key={s}>{s}</Tag>),
        },
        {
          title: '状态',
          dataIndex: 'status',
          width: 90,
          render: (v, r) => (
            <span>
              <Tag color={v === 'active' ? 'green' : 'default'}>{v === 'active' ? '已授权' : '已撤销'}</Tag>
              {r.app_status === 'suspended' && <Tag color="red">应用停用</Tag>}
            </span>
          ),
        },
        { title: '授权时间', dataIndex: 'granted_time', width: 150 },
        {
          title: '操作',
          width: 80,
          render: (_, r) =>
            r.status === 'active' ? (
              <Popconfirm title="撤销后该应用立即无法获取你的信息" onConfirm={() => revoke(r.client_id)}>
                <a style={{ color: '#ff4d4f' }}>撤销</a>
              </Popconfirm>
            ) : (
              '-'
            ),
        },
      ]}
    />
  );
}

function PasswordTab() {
  const navigate = useNavigate();
  const logout = useAuth((s) => s.logout);
  const [form] = Form.useForm();

  const submit = async (values: { old_password: string; new_password: string; confirm: string }) => {
    if (values.new_password !== values.confirm) {
      message.error('两次输入的新密码不一致');
      return;
    }
    await http.post('/account/password', {
      old_password: values.old_password,
      new_password: values.new_password,
    });
    Modal.success({
      title: '密码已修改',
      content: '其他设备已全部下线，请使用新密码重新登录。',
      onOk: () => {
        logout();
        navigate('/login');
      },
    });
  };

  return (
    <Form form={form} layout="vertical" onFinish={submit} style={{ maxWidth: 380 }}>
      <Form.Item name="old_password" label="旧密码" rules={[{ required: true }]}>
        <Input.Password autoComplete="current-password" />
      </Form.Item>
      <Form.Item name="new_password" label="新密码" rules={[{ required: true }, { min: 6, message: '至少6位' }]}>
        <Input.Password autoComplete="new-password" />
      </Form.Item>
      <Form.Item name="confirm" label="确认新密码" rules={[{ required: true }]}>
        <Input.Password autoComplete="new-password" />
      </Form.Item>
      <Button type="primary" htmlType="submit" danger>
        修改密码
      </Button>
    </Form>
  );
}

export default function Security() {
  return (
    <Card title="账号安全">
      <Tabs
        items={[
          { key: 'sessions', label: '设备与会话', children: <SessionsTab /> },
          { key: 'grants', label: '第三方授权', children: <GrantsTab /> },
          { key: 'password', label: '修改密码', children: <PasswordTab /> },
        ]}
      />
    </Card>
  );
}
