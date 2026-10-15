import { useCallback, useEffect, useState } from 'react';
import {
  Button,
  Card,
  Drawer,
  Descriptions,
  Form,
  Input,
  Modal,
  Popconfirm,
  Select,
  Space,
  Table,
  Tag,
  message,
} from 'antd';
import { PlusOutlined, ReloadOutlined } from '@ant-design/icons';
import http from '../api';

interface ServerRow {
  id: number;
  name: string;
  slug: string;
  description: string;
  contact_qq: string;
  status: string;
  note: string;
  owner_user_id: number;
  owner: { id: number; email: string; username: string } | null;
  members_count: number;
  bindings_count: number;
  created_time: string;
}

const STATUS_TAG: Record<string, { color: string; text: string }> = {
  active: { color: 'green', text: '正常' },
  suspended: { color: 'orange', text: '已暂停' },
  closed: { color: 'default', text: '已注销' },
};

export default function Servers() {
  const [list, setList] = useState<ServerRow[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [keyword, setKeyword] = useState('');
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const [detail, setDetail] = useState<any>(null);
  const [form] = Form.useForm();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await http.get('/admin/servers', {
        params: { page, page_size: 10, keyword, status },
      });
      setList(data.list || []);
      setTotal(data.total || 0);
    } finally {
      setLoading(false);
    }
  }, [page, keyword, status]);

  useEffect(() => {
    load();
  }, [load]);

  const create = async (values: any) => {
    await http.post('/admin/servers', values);
    setCreateOpen(false);
    form.resetFields();
    load();
  };

  const changeStatus = async (row: ServerRow, next: string) => {
    await http.post(`/admin/servers/${row.id}/status`, { status: next });
    load();
  };

  const openDetail = async (row: ServerRow) => {
    const data = await http.get(`/admin/servers/${row.id}`);
    setDetail(data);
  };

  return (
    <Card
      title="空间列表"
      extra={
        <Space>
          <Input.Search
            placeholder="名称 / slug"
            allowClear
            onSearch={(v) => {
              setPage(1);
              setKeyword(v);
            }}
            style={{ width: 200 }}
          />
          <Select
            placeholder="状态"
            allowClear
            style={{ width: 120 }}
            onChange={(v) => {
              setPage(1);
              setStatus(v || '');
            }}
            options={[
              { value: 'active', label: '正常' },
              { value: 'suspended', label: '已暂停' },
              { value: 'closed', label: '已注销' },
            ]}
          />
          <Button icon={<ReloadOutlined />} onClick={load} />
          <Button type="primary" icon={<PlusOutlined />} onClick={() => setCreateOpen(true)}>
            新建空间
          </Button>
        </Space>
      }
    >
      <Table<ServerRow>
        rowKey="id"
        loading={loading}
        dataSource={list}
        pagination={{ current: page, total, pageSize: 10, onChange: setPage }}
        columns={[
          { title: 'ID', dataIndex: 'id', width: 60 },
          { title: '名称', dataIndex: 'name' },
          { title: 'Slug', dataIndex: 'slug' },
          { title: 'Owner', render: (_, r) => r.owner?.email || '-' },
          {
            title: '状态',
            dataIndex: 'status',
            width: 90,
            render: (v) => <Tag color={STATUS_TAG[v]?.color}>{STATUS_TAG[v]?.text || v}</Tag>,
          },
          { title: '成员', dataIndex: 'members_count', width: 70 },
          { title: '绑定码', dataIndex: 'bindings_count', width: 70 },
          { title: '创建时间', dataIndex: 'created_time', width: 160 },
          {
            title: '操作',
            width: 220,
            render: (_, r) => (
              <Space>
                <a onClick={() => openDetail(r)}>详情</a>
                {r.status === 'active' ? (
                  <Popconfirm title="暂停后其全部绑定码授权立即失效" onConfirm={() => changeStatus(r, 'suspended')}>
                    <a style={{ color: '#faad14' }}>暂停</a>
                  </Popconfirm>
                ) : r.status === 'suspended' ? (
                  <a onClick={() => changeStatus(r, 'active')}>恢复</a>
                ) : null}
                {r.status !== 'closed' && (
                  <Popconfirm title="注销不可恢复, 确认?" onConfirm={() => changeStatus(r, 'closed')}>
                    <a style={{ color: '#ff4d4f' }}>注销</a>
                  </Popconfirm>
                )}
              </Space>
            ),
          },
        ]}
      />

      <Modal
        title="新建空间"
        open={createOpen}
        onCancel={() => setCreateOpen(false)}
        onOk={() => form.submit()}
        destroyOnClose
      >
        <Form form={form} layout="vertical" onFinish={create}>
          <Form.Item name="name" label="空间名称" rules={[{ required: true }]}>
            <Input placeholder="Demo Server" />
          </Form.Item>
          <Form.Item
            name="slug"
            label="Slug（3-32 位小写字母数字连字符）"
            rules={[{ required: true }, { pattern: /^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/, message: '格式不正确' }]}
          >
            <Input placeholder="demo-server" />
          </Form.Item>
          <Form.Item name="owner_email" label="Owner 邮箱（须为已注册用户）" rules={[{ required: true }, { type: 'email' }]}>
            <Input placeholder="owner@example.com" />
          </Form.Item>
          <Form.Item name="contact_qq" label="联系 QQ">
            <Input />
          </Form.Item>
          <Form.Item name="description" label="介绍">
            <Input.TextArea rows={2} />
          </Form.Item>
        </Form>
      </Modal>

      <Drawer
        title={detail ? `空间：${detail.server.name}` : ''}
        width={720}
        open={!!detail}
        onClose={() => setDetail(null)}
      >
        {detail && (
          <>
            <Descriptions size="small" column={2} bordered>
              <Descriptions.Item label="ID">{detail.server.id}</Descriptions.Item>
              <Descriptions.Item label="Slug">{detail.server.slug}</Descriptions.Item>
              <Descriptions.Item label="Owner">{detail.server.owner?.email}</Descriptions.Item>
              <Descriptions.Item label="状态">
                <Tag color={STATUS_TAG[detail.server.status]?.color}>
                  {STATUS_TAG[detail.server.status]?.text}
                </Tag>
              </Descriptions.Item>
              <Descriptions.Item label="联系 QQ">{detail.server.contact_qq || '-'}</Descriptions.Item>
              <Descriptions.Item label="创建时间">{detail.server.created_time}</Descriptions.Item>
              <Descriptions.Item label="介绍" span={2}>
                {detail.server.description || '-'}
              </Descriptions.Item>
              <Descriptions.Item label="平台备注" span={2}>
                {detail.server.note || '-'}
              </Descriptions.Item>
            </Descriptions>

            <Card title="成员" size="small" style={{ marginTop: 16 }}>
              <Table
                rowKey="id"
                size="small"
                dataSource={detail.members}
                pagination={false}
                columns={[
                  { title: '用户', render: (_, r: any) => `${r.user?.username}（${r.user?.email}）` },
                  { title: '角色', dataIndex: 'role_key', width: 90, render: (v) => <Tag>{v}</Tag> },
                  { title: '状态', dataIndex: 'status', width: 90 },
                  { title: '来源', dataIndex: 'source', width: 90 },
                  { title: '加入时间', dataIndex: 'joined_time', width: 160 },
                ]}
              />
            </Card>

            <Card title="绑定码" size="small" style={{ marginTop: 16 }}>
              <Table
                rowKey="id"
                size="small"
                dataSource={detail.bindings}
                pagination={false}
                columns={[
                  { title: '插件', render: (_, r: any) => r.plugin?.name || r.plugin_id },
                  { title: '绑定码', dataIndex: 'code', render: (v) => <code>{v}</code> },
                  { title: '状态', dataIndex: 'status', width: 90, render: (v) => <Tag>{v}</Tag> },
                  { title: '最近心跳', dataIndex: 'last_heartbeat', width: 160, render: (v) => v || '从未' },
                ]}
              />
            </Card>
          </>
        )}
      </Drawer>
    </Card>
  );
}
