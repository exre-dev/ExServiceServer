import { useCallback, useEffect, useState } from 'react';
import {
  Button,
  Card,
  Checkbox,
  Drawer,
  Empty,
  Form,
  Input,
  Modal,
  Popconfirm,
  Select,
  Space,
  Table,
  Tag,
  Typography,
} from 'antd';
import Markdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { PlusOutlined, ReloadOutlined } from '@ant-design/icons';
import http from '../api';

interface DocRow {
  id: number;
  title: string;
  slug: string;
  category_id: number | null;
  visibility: string;
  visible_roles: string[] | null;
  visible_servers: number[] | null;
  visible_tags: number[] | null;
  status: string;
  version: number;
  updated_time: string;
  content_md?: string;
}

interface Category {
  id: number;
  name: string;
  sort: number;
}

const VIS_TAG: Record<string, { color: string; text: string }> = {
  public: { color: 'green', text: '公开' },
  login: { color: 'cyan', text: '仅登录用户' },
  members: { color: 'blue', text: '仅成员' },
  roles: { color: 'orange', text: '指定角色' },
  servers: { color: 'geekblue', text: '指定空间成员' },
  tags: { color: 'purple', text: '指定标签' },
};

interface Props {
  scope: 'platform' | 'server';
  sid?: number;
}

export default function DocManager({ scope, sid }: Props) {
  const base = scope === 'platform' ? '/admin' : `/server/${sid}`;
  const isServer = scope === 'server';

  const [list, setList] = useState<DocRow[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [categories, setCategories] = useState<Category[]>([]);
  const [categoryFilter, setCategoryFilter] = useState<number | undefined>();
  const [keyword, setKeyword] = useState('');
  const [loading, setLoading] = useState(false);
  const [editFor, setEditFor] = useState<DocRow | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [preview, setPreview] = useState<DocRow | null>(null);
  const [catName, setCatName] = useState('');
  const [form] = Form.useForm();
  const [createForm] = Form.useForm();
  const [editMode, setEditMode] = useState<'edit' | 'preview'>('edit');
  const [serverOptions, setServerOptions] = useState<{ value: number; label: string }[]>([]);
  const [tagOptions, setTagOptions] = useState<{ value: number; label: string }[]>([]);

  // 平台文档的 servers/tags 可见性需要选项数据
  useEffect(() => {
    if (scope !== 'platform') return;
    http.get('/admin/server-options').then((d) => {
      setServerOptions((d.list || []).map((s: any) => ({ value: s.id, label: `${s.name}（${s.slug}）` })));
    }).catch(() => {});
    http.get('/admin/tags').then((d) => {
      setTagOptions((d.list || []).map((t: any) => ({ value: t.id, label: t.name })));
    }).catch(() => {});
  }, [scope]);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await http.get(`${base}/docs`, {
        params: { page, page_size: 10, keyword, category_id: categoryFilter ?? '' },
      });
      setList(data.list || []);
      setTotal(data.total || 0);
    } finally {
      setLoading(false);
    }
  }, [base, page, keyword, categoryFilter]);

  const loadCategories = useCallback(async () => {
    const data = await http.get(`${base}/doc-categories`);
    setCategories(data.list || []);
  }, [base]);

  useEffect(() => {
    load();
  }, [load]);
  useEffect(() => {
    loadCategories();
  }, [loadCategories]);

  const create = async (values: any) => {
    await http.post(`${base}/docs`, values);
    setCreateOpen(false);
    createForm.resetFields();
    load();
  };

  const save = async (values: any) => {
    await http.put(`${base}/docs/${editFor!.id}`, values);
    setEditFor(null);
    load();
  };

  const setStatus = async (row: DocRow, status: string) => {
    await http.post(`${base}/docs/${row.id}/status`, { status });
    load();
  };

  const del = async (row: DocRow) => {
    await http.delete(`${base}/docs/${row.id}`);
    load();
  };

  const openPreview = async (row: DocRow) => {
    // 管理端预览: 取编辑数据(列表不含正文)
    const detail = await http.get(`/docs/${row.slug}`).catch(() => null);
    setPreview({ ...row, content_md: detail?.content_md ?? '' });
  };

  const addCategory = async () => {
    if (!catName.trim()) return;
    await http.post(`${base}/doc-categories`, { name: catName.trim() });
    setCatName('');
    loadCategories();
  };

  const categoryOptions = [
    { value: 0, label: '未分类' },
    ...categories.map((c) => ({ value: c.id, label: c.name })),
  ];

  const visibilityField = (
    <Form.Item name="visibility" label="可见性" initialValue="public">
      <Select
        options={
          isServer
            ? [
                { value: 'public', label: '公开（任何人可见）' },
                { value: 'members', label: '仅本空间成员' },
                { value: 'roles', label: '指定角色' },
              ]
            : [
                { value: 'public', label: '公开（任何人可见）' },
                { value: 'login', label: '仅登录用户' },
                { value: 'servers', label: '指定空间成员（用户组）' },
                { value: 'tags', label: '指定用户标签' },
              ]
        }
      />
    </Form.Item>
  );

  /** 平台文档: servers/tags 可见性的条件字段 */
  const serverTagFields = !isServer ? (
    <Form.Item noStyle shouldUpdate={(a, b) => a.visibility !== b.visibility}>
      {({ getFieldValue }) => {
        const vis = getFieldValue('visibility');
        if (vis === 'servers') {
          return (
            <Form.Item name="visible_servers" label="可见的空间（其成员可見）" rules={[{ required: true, message: '至少选择一个空间' }]}>
              <Select mode="multiple" options={serverOptions} placeholder="选择空间" />
            </Form.Item>
          );
        }
        if (vis === 'tags') {
          return (
            <Form.Item name="visible_tags" label="可见的用户标签" rules={[{ required: true, message: '至少选择一个标签' }]}>
              <Select mode="multiple" options={tagOptions} placeholder="选择标签（在用户管理中打标）" />
            </Form.Item>
          );
        }
        return null;
      }}
    </Form.Item>
  ) : null;

  const rolesField = isServer ? (
    <Form.Item noStyle shouldUpdate={(a, b) => a.visibility !== b.visibility}>
      {({ getFieldValue }) =>
        getFieldValue('visibility') === 'roles' ? (
          <Form.Item name="visible_roles" label="可见角色（Owner/管理员恒可见）" rules={[{ required: true, message: '至少选择一个角色' }]}>
            <Checkbox.Group
              options={[
                { value: 'member', label: '成员' },
                { value: 'admin', label: '管理员' },
              ]}
            />
          </Form.Item>
        ) : null
      }
    </Form.Item>
  ) : null;

  return (
    <Card
      title="文档管理"
      extra={
        <Space>
          <Select
            placeholder="分类"
            allowClear
            style={{ width: 140 }}
            value={categoryFilter}
            onChange={(v) => {
              setPage(1);
              setCategoryFilter(v);
            }}
            options={categoryOptions}
          />
          <Input.Search
            placeholder="标题 / slug"
            allowClear
            onSearch={(v) => {
              setPage(1);
              setKeyword(v);
            }}
            style={{ width: 180 }}
          />
          <Button icon={<ReloadOutlined />} onClick={load} />
          <Button type="primary" icon={<PlusOutlined />} onClick={() => setCreateOpen(true)}>
            新建文档
          </Button>
        </Space>
      }
    >
      <Space style={{ marginBottom: 12 }}>
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
          分类管理：
        </Typography.Text>
        {categories.map((c) => (
          <Tag
            key={c.id}
            closable
            onClose={(e) => {
              e.preventDefault();
              Modal.confirm({
                title: `删除分类「${c.name}」？其下文档将移至未分类`,
                onOk: async () => {
                  await http.delete(`${base}/doc-categories/${c.id}`);
                  loadCategories();
                  load();
                },
              });
            }}
          >
            {c.name}
          </Tag>
        ))}
        <Input
          size="small"
          style={{ width: 120 }}
          placeholder="新分类名"
          value={catName}
          onChange={(e) => setCatName(e.target.value)}
          onPressEnter={addCategory}
        />
        <Button size="small" onClick={addCategory}>
          添加
        </Button>
      </Space>

      <Table<DocRow>
        rowKey="id"
        size="small"
        loading={loading}
        dataSource={list}
        pagination={{ current: page, total, pageSize: 10, onChange: setPage }}
        columns={[
          { title: 'ID', dataIndex: 'id', width: 55 },
          { title: '标题', dataIndex: 'title' },
          { title: 'Slug', dataIndex: 'slug', render: (v) => <Typography.Text code style={{ fontSize: 12 }}>{v}</Typography.Text> },
          {
            title: '分类',
            dataIndex: 'category_id',
            width: 100,
            render: (v) => categories.find((c) => c.id === v)?.name || '未分类',
          },
          {
            title: '可见性',
            dataIndex: 'visibility',
            width: 95,
            render: (v) => <Tag color={VIS_TAG[v]?.color}>{VIS_TAG[v]?.text || v}</Tag>,
          },
          {
            title: '状态',
            dataIndex: 'status',
            width: 80,
            render: (v) => (v === 'published' ? <Tag color="green">已发布</Tag> : <Tag>草稿</Tag>),
          },
          { title: '版本', dataIndex: 'version', width: 60 },
          { title: '更新时间', dataIndex: 'updated_time', width: 150 },
          {
            title: '操作',
            width: 230,
            render: (_, r) => (
              <Space>
                <a onClick={() => openPreview(r)}>预览</a>
                <a
                  onClick={() => {
                    setEditFor(r);
                    setEditMode('edit');
                  }}
                >
                  编辑
                </a>
                {r.status === 'published' ? (
                  <Popconfirm title="转为草稿后外部不可见" onConfirm={() => setStatus(r, 'draft')}>
                    <a style={{ color: '#faad14' }}>转草稿</a>
                  </Popconfirm>
                ) : (
                  <Popconfirm title="发布后按可见性对外可见" onConfirm={() => setStatus(r, 'published')}>
                    <a>发布</a>
                  </Popconfirm>
                )}
                <Popconfirm title="删除文档?" onConfirm={() => del(r)}>
                  <a style={{ color: '#ff4d4f' }}>删除</a>
                </Popconfirm>
              </Space>
            ),
          },
        ]}
      />

      <Modal
        title="新建文档"
        open={createOpen}
        onCancel={() => setCreateOpen(false)}
        onOk={() => createForm.submit()}
        width={760}
        destroyOnClose
      >
        <Form form={createForm} layout="vertical" initialValues={{ visibility: 'public', status: 'draft' }}>
          <Form.Item name="title" label="标题" rules={[{ required: true }]}>
            <Input />
          </Form.Item>
          <Space style={{ display: 'flex' }} align="start">
            <Form.Item name="slug" label="Slug（留空自动生成）" style={{ width: 260 }}>
              <Input placeholder="my-doc" />
            </Form.Item>
            <Form.Item name="category_id" label="分类" initialValue={0} style={{ width: 200 }}>
              <Select options={categoryOptions} />
            </Form.Item>
            <Form.Item name="status" label="状态" initialValue="draft">
              <Select
                style={{ width: 130 }}
                options={[
                  { value: 'draft', label: '草稿' },
                  { value: 'published', label: '直接发布' },
                ]}
              />
            </Form.Item>
          </Space>
          {visibilityField}
          {rolesField}
          {serverTagFields}
          <Form.Item name="content_md" label="正文（Markdown）">
            <Input.TextArea rows={12} placeholder="# 标题" />
          </Form.Item>
        </Form>
      </Modal>

      <Modal
        title={`编辑：${editFor?.title || ''}（v${editFor?.version}）`}
        open={!!editFor}
        onCancel={() => setEditFor(null)}
        onOk={() => form.submit()}
        width={760}
        destroyOnClose
        footer={[
          <Button key="mode" onClick={() => setEditMode(editMode === 'edit' ? 'preview' : 'edit')}>
            {editMode === 'edit' ? '切到预览' : '切到编辑'}
          </Button>,
          <Button key="cancel" onClick={() => setEditFor(null)}>
            取消
          </Button>,
          <Button key="ok" type="primary" onClick={() => form.submit()}>
            保存（版本 +1）
          </Button>,
        ]}
      >
        <Form form={form} layout="vertical" onFinish={save} initialValues={editFor || {}}>
          <Form.Item name="title" label="标题" rules={[{ required: true }]}>
            <Input />
          </Form.Item>
          <Space style={{ display: 'flex' }} align="start">
            <Form.Item name="slug" label="Slug" style={{ width: 260 }}>
              <Input />
            </Form.Item>
            <Form.Item name="category_id" label="分类" style={{ width: 200 }}>
              <Select options={categoryOptions} />
            </Form.Item>
          </Space>
          {visibilityField}
          {rolesField}
          {serverTagFields}
          {editMode === 'edit' ? (
            <Form.Item name="content_md" label="正文（Markdown）">
              <Input.TextArea rows={14} />
            </Form.Item>
          ) : (
            <Form.Item noStyle shouldUpdate>
              {() => (
                <Card size="small" style={{ maxHeight: 420, overflowY: 'auto' }}>
                  <div className="markdown-body">
                    <Markdown remarkPlugins={[remarkGfm]}>
                      {form.getFieldValue('content_md') || '（空）'}
                    </Markdown>
                  </div>
                </Card>
              )}
            </Form.Item>
          )}
        </Form>
      </Modal>

      <Drawer
        title={preview?.title}
        width={680}
        open={!!preview}
        onClose={() => setPreview(null)}
      >
        {preview ? (
          preview.content_md ? (
            <div className="markdown-body">
              <Markdown remarkPlugins={[remarkGfm]}>{preview.content_md}</Markdown>
            </div>
          ) : (
            <Empty description="正文为空或草稿不可见" />
          )
        ) : null}
      </Drawer>
    </Card>
  );
}
