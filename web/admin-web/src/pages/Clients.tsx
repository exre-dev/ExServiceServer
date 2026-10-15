import { useCallback, useEffect, useState } from 'react';
import {
  Button,
  Card,
  Form,
  Input,
  InputNumber,
  Modal,
  Popconfirm,
  Radio,
  Select,
  Space,
  Table,
  Tag,
  Typography,
} from 'antd';
import { PlusOutlined, ReloadOutlined } from '@ant-design/icons';
import http from '../api';

interface ClientRow {
  id: number;
  client_id: string;
  name: string;
  type: string;
  server: { name: string; slug: string } | null;
  redirect_uris: string[];
  scopes: string[];
  status: string;
  note: string;
  created_time: string;
}

export default function Clients() {
  const [list, setList] = useState<ClientRow[]>([]);
  const [total, setTotal] = useState(0);
  const [scopeDict, setScopeDict] = useState<string[]>([]);
  const [page, setPage] = useState(1);
  const [typeFilter, setTypeFilter] = useState('');
  const [loading, setLoading] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const [secretShown, setSecretShown] = useState<{ client_id: string; client_secret: string } | null>(null);
  const [form] = Form.useForm();
  const typeWatch = Form.useWatch('type', form);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await http.get('/admin/clients', { params: { page, page_size: 10, type: typeFilter } });
      setList(data.list || []);
      setTotal(data.total || 0);
      setScopeDict(data.scope_dict || []);
    } finally {
      setLoading(false);
    }
  }, [page, typeFilter]);

  useEffect(() => {
    load();
  }, [load]);

  const create = async (values: any) => {
    const data = await http.post('/admin/clients', values);
    setCreateOpen(false);
    form.resetFields();
    setSecretShown(data);
    load();
  };

  const resetSecret = async (row: ClientRow) => {
    const data = await http.post(`/admin/clients/${row.id}/secret-reset`);
    setSecretShown(data);
  };

  const setStatus = async (row: ClientRow, status: string) => {
    await http.post(`/admin/clients/${row.id}/status`, { status });
    load();
  };

  return (
    <Card
      title="第三方应用（SSO Client）"
      extra={
        <Space>
          <Select
            placeholder="类型"
            allowClear
            style={{ width: 130 }}
            onChange={(v) => {
              setPage(1);
              setTypeFilter(v || '');
            }}
            options={[
              { value: 'third_party', label: '第三方平台' },
              { value: 'plugin', label: '插件' },
            ]}
          />
          <Button icon={<ReloadOutlined />} onClick={load} />
          <Button type="primary" icon={<PlusOutlined />} onClick={() => setCreateOpen(true)}>
            注册应用
          </Button>
        </Space>
      }
    >
      <Table<ClientRow>
        rowKey="id"
        loading={loading}
        dataSource={list}
        pagination={{ current: page, total, pageSize: 10, onChange: setPage }}
        columns={[
          { title: 'ID', dataIndex: 'id', width: 60 },
          { title: '名称', dataIndex: 'name' },
          {
            title: 'client_id',
            dataIndex: 'client_id',
            render: (v) => (
              <Typography.Text code copyable style={{ fontSize: 12 }}>
                {v}
              </Typography.Text>
            ),
          },
          {
            title: '类型',
            dataIndex: 'type',
            width: 100,
            render: (v, r) => (
              <span>
                <Tag color={v === 'plugin' ? 'geekblue' : 'cyan'}>{v === 'plugin' ? '插件' : '第三方'}</Tag>
                {r.server && <Tag>{r.server.name}</Tag>}
              </span>
            ),
          },
          {
            title: 'Scopes',
            dataIndex: 'scopes',
            render: (v: string[]) => v?.map((s) => <Tag key={s}>{s}</Tag>),
          },
          {
            title: '状态',
            dataIndex: 'status',
            width: 90,
            render: (v) => (v === 'active' ? <Tag color="green">生效</Tag> : <Tag color="red">停用</Tag>),
          },
          { title: '注册时间', dataIndex: 'created_time', width: 160 },
          {
            title: '操作',
            width: 180,
            render: (_, r) => (
              <Space>
                <Popconfirm title="重置后旧密钥立即失效" onConfirm={() => resetSecret(r)}>
                  <a>重置密钥</a>
                </Popconfirm>
                {r.status === 'active' ? (
                  <Popconfirm title="停用后该应用全部 token 立即失效" onConfirm={() => setStatus(r, 'suspended')}>
                    <a style={{ color: '#faad14' }}>停用</a>
                  </Popconfirm>
                ) : (
                  <a onClick={() => setStatus(r, 'active')}>启用</a>
                )}
              </Space>
            ),
          },
        ]}
      />

      <Modal
        title="注册第三方应用"
        open={createOpen}
        onCancel={() => setCreateOpen(false)}
        onOk={() => form.submit()}
        destroyOnClose
      >
        <Form form={form} layout="vertical" onFinish={create} initialValues={{ type: 'third_party' }}>
          <Form.Item name="name" label="应用名称" rules={[{ required: true }]}>
            <Input />
          </Form.Item>
          <Form.Item name="type" label="类型">
            <Radio.Group
              options={[
                { value: 'third_party', label: '第三方平台（授权码 + PKCE）' },
                { value: 'plugin', label: '插件（设备码流）' },
              ]}
            />
          </Form.Item>
          {typeWatch === 'plugin' && (
            <Form.Item name="server_id" label="归属空间 ID（插件类必填）" rules={[{ required: true }]}>
              <InputNumber style={{ width: '100%' }} min={1} placeholder="server_id" />
            </Form.Item>
          )}
          {typeWatch === 'third_party' && (
            <Form.Item name="redirect_uris" label="回调地址白名单">
              <Select mode="tags" placeholder="输入完整 URL 后回车" open={false} />
            </Form.Item>
          )}
          <Form.Item name="scopes" label="Scopes（user.basic 必选）">
            <Select mode="multiple" options={scopeDict.map((s) => ({ value: s, label: s }))} />
          </Form.Item>
          <Form.Item name="note" label="对接备注">
            <Input.TextArea rows={2} />
          </Form.Item>
        </Form>
      </Modal>

      <Modal
        title="密钥（仅本次显示，请立即保存）"
        open={!!secretShown}
        onCancel={() => setSecretShown(null)}
        footer={[<Button key="ok" type="primary" onClick={() => setSecretShown(null)}>我已保存</Button>]}
      >
        {secretShown && (
          <>
            <p>client_id：</p>
            <Typography.Paragraph copyable code>
              {secretShown.client_id}
            </Typography.Paragraph>
            <p>client_secret：</p>
            <Typography.Paragraph copyable code>
              {secretShown.client_secret}
            </Typography.Paragraph>
          </>
        )}
      </Modal>
    </Card>
  );
}
