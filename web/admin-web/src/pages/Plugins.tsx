import { useCallback, useEffect, useState } from 'react';
import {
  Button,
  Card,
  Drawer,
  Form,
  Input,
  Modal,
  Popconfirm,
  Select,
  Space,
  Switch,
  Table,
  Tag,
} from 'antd';
import { PlusOutlined, ReloadOutlined, SettingOutlined } from '@ant-design/icons';
import http from '../api';

interface PluginRow {
  id: number;
  slug: string;
  name: string;
  summary: string;
  status: string;
  latest_version: string;
  features_count: number;
  bindings_count: number;
  created_time: string;
}

const STATUS = {
  draft: { color: 'default', text: '草稿' },
  online: { color: 'green', text: '在线' },
  offline: { color: 'red', text: '已下架' },
};

export default function Plugins() {
  const [list, setList] = useState<PluginRow[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(false);
  const [editFor, setEditFor] = useState<PluginRow | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [featFor, setFeatFor] = useState<PluginRow | null>(null);
  const [featData, setFeatData] = useState<any>(null);
  const [form] = Form.useForm();
  const [createForm] = Form.useForm();
  const [featForm] = Form.useForm();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await http.get('/admin/plugins', { params: { page, page_size: 10, status } });
      setList(data.list || []);
      setTotal(data.total || 0);
    } finally {
      setLoading(false);
    }
  }, [page, status]);

  useEffect(() => {
    load();
  }, [load]);

  const changeStatus = async (row: PluginRow, next: string) => {
    await http.post(`/admin/plugins/${row.id}/status`, { status: next });
    load();
  };

  const saveEdit = async (values: any) => {
    await http.put(`/admin/plugins/${editFor!.id}`, values);
    setEditFor(null);
    load();
  };

  const createPlugin = async (values: any) => {
    await http.post('/admin/plugins', values);
    setCreateOpen(false);
    createForm.resetFields();
    load();
  };

  const openFeatures = async (row: PluginRow) => {
    setFeatFor(row);
    const data = await http.get(`/admin/plugins/${row.id}/features`);
    setFeatData(data);
  };

  const reloadFeatures = async () => {
    if (featFor) {
      const data = await http.get(`/admin/plugins/${featFor.id}/features`);
      setFeatData(data);
    }
  };

  const addFeature = async (values: any) => {
    await http.post(`/admin/plugins/${featFor!.id}/features`, values);
    featForm.resetFields();
    reloadFeatures();
    load();
  };

  return (
    <Card
      title="插件列表"
      extra={
        <Space>
          <Select
            placeholder="状态"
            allowClear
            style={{ width: 110 }}
            onChange={(v) => {
              setPage(1);
              setStatus(v || '');
            }}
            options={Object.entries(STATUS).map(([v, s]) => ({ value: v, label: s.text }))}
          />
          <Button icon={<ReloadOutlined />} onClick={load} />
          <Button type="primary" icon={<PlusOutlined />} onClick={() => setCreateOpen(true)}>
            新建插件
          </Button>
        </Space>
      }
    >
      <Table<PluginRow>
        rowKey="id"
        loading={loading}
        dataSource={list}
        pagination={{ current: page, total, pageSize: 10, onChange: setPage }}
        columns={[
          { title: 'ID', dataIndex: 'id', width: 60 },
          { title: 'Slug', dataIndex: 'slug' },
          { title: '名称', dataIndex: 'name' },
          { title: '简介', dataIndex: 'summary', ellipsis: true },
          {
            title: '状态',
            dataIndex: 'status',
            width: 90,
            render: (v) => <Tag color={STATUS[v as keyof typeof STATUS]?.color}>{STATUS[v as keyof typeof STATUS]?.text || v}</Tag>,
          },
          { title: '功能点', dataIndex: 'features_count', width: 80 },
          { title: '绑定码', dataIndex: 'bindings_count', width: 80 },
          { title: '最新版本', dataIndex: 'latest_version', width: 100 },
          {
            title: '操作',
            width: 240,
            render: (_, r) => (
              <Space>
                <a onClick={() => setEditFor(r)}>编辑</a>
                <a onClick={() => openFeatures(r)}>
                  <SettingOutlined /> 功能点
                </a>
                {r.status !== 'online' ? (
                  <Popconfirm title="上线后空间管理员可生成绑定码" onConfirm={() => changeStatus(r, 'online')}>
                    <a>上线</a>
                  </Popconfirm>
                ) : (
                  <Popconfirm title="下架后全部绑定码校验立即拒绝" onConfirm={() => changeStatus(r, 'offline')}>
                    <a style={{ color: '#faad14' }}>下架</a>
                  </Popconfirm>
                )}
              </Space>
            ),
          },
        ]}
      />

      <Modal title="新建插件" open={createOpen} onCancel={() => setCreateOpen(false)} onOk={() => createForm.submit()} destroyOnClose>
        <Form form={createForm} layout="vertical" onFinish={createPlugin}>
          <Form.Item
            name="slug"
            label="Slug（与插件端共用，如 ex-tuner）"
            rules={[{ required: true }, { pattern: /^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/, message: '3-32 位小写字母数字连字符' }]}
          >
            <Input placeholder="ex-tuner" />
          </Form.Item>
          <Form.Item name="name" label="名称" rules={[{ required: true }]}>
            <Input />
          </Form.Item>
          <Form.Item name="summary" label="一句话简介">
            <Input />
          </Form.Item>
          <Form.Item name="latest_version" label="最新版本号">
            <Input placeholder="1.0.0" />
          </Form.Item>
        </Form>
      </Modal>

      <Modal
        title={editFor ? `编辑：${editFor.name}` : ''}
        open={!!editFor}
        onCancel={() => setEditFor(null)}
        onOk={() => form.submit()}
        destroyOnClose
      >
        <Form form={form} layout="vertical" onFinish={saveEdit} initialValues={editFor || {}}>
          <Form.Item name="name" label="名称" rules={[{ required: true }]}>
            <Input />
          </Form.Item>
          <Form.Item name="summary" label="一句话简介">
            <Input />
          </Form.Item>
          <Form.Item name="latest_version" label="最新版本号">
            <Input />
          </Form.Item>
          <Form.Item name="description_md" label="详情（Markdown）">
            <Input.TextArea rows={4} />
          </Form.Item>
        </Form>
      </Modal>

      <Drawer
        title={featFor ? `功能点：${featFor.name}` : ''}
        width={640}
        open={!!featFor}
        onClose={() => setFeatFor(null)}
      >
        {featData?.has_bindings && (
          <Tag color="orange" style={{ marginBottom: 12 }}>
            已有绑定码使用：feature_key 不可改名、不可删除，只能改名称/开关
          </Tag>
        )}
        <Table
          rowKey="id"
          size="small"
          dataSource={featData?.list || []}
          pagination={false}
          columns={[
            { title: 'Key', dataIndex: 'feature_key', render: (v) => <code>{v}</code> },
            { title: '名称', dataIndex: 'name' },
            {
              title: '默认开启',
              dataIndex: 'default_enabled',
              width: 90,
              render: (v, r: any) => (
                <Switch
                  checked={v}
                  onChange={async (checked) => {
                    await http.put(`/admin/plugins/${featFor!.id}/features/${r.id}`, {
                      default_enabled: checked,
                    });
                    reloadFeatures();
                  }}
                />
              ),
            },
            {
              title: '操作',
              width: 70,
              render: (_, r: any) => (
                <Popconfirm
                  title="删除功能点?"
                  disabled={featData?.has_bindings}
                  onConfirm={async () => {
                    await http.delete(`/admin/plugins/${featFor!.id}/features/${r.id}`);
                    reloadFeatures();
                    load();
                  }}
                >
                  <a style={{ color: featData?.has_bindings ? '#ccc' : '#ff4d4f' }}>删除</a>
                </Popconfirm>
              ),
            },
          ]}
        />
        <Card size="small" title="新增功能点" style={{ marginTop: 16 }}>
          <Form form={featForm} layout="inline" onFinish={addFeature}>
            <Form.Item
              name="feature_key"
              rules={[{ required: true }, { pattern: /^[a-z0-9_]{2,32}$/, message: '2-32 位小写字母数字下划线' }]}
            >
              <Input placeholder="feature_key" style={{ width: 160 }} />
            </Form.Item>
            <Form.Item name="name" rules={[{ required: true }]}>
              <Input placeholder="功能名称" style={{ width: 140 }} />
            </Form.Item>
            <Form.Item valuePropName="checked" initialValue={true} name="default_enabled">
              <Switch checkedChildren="默认开" unCheckedChildren="默认关" />
            </Form.Item>
            <Form.Item>
              <Button type="primary" htmlType="submit">
                添加
              </Button>
            </Form.Item>
          </Form>
        </Card>
      </Drawer>
    </Card>
  );
}
