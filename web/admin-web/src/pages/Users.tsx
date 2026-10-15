import { useCallback, useEffect, useState } from 'react';
import {
  Button,
  Card,
  Drawer,
  Descriptions,
  Input,
  Modal,
  Popconfirm,
  Select,
  Space,
  Table,
  Tag,
  Form,
} from 'antd';
import { ReloadOutlined, TagsOutlined } from '@ant-design/icons';
import http from '../api';

interface UserRow {
  id: number;
  email: string;
  username: string;
  qq: string | null;
  platform_role: string;
  ban: number;
  last_login_time: string | null;
  created_time: string;
}

export default function Users() {
  const [list, setList] = useState<UserRow[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [keyword, setKeyword] = useState('');
  const [banFilter, setBanFilter] = useState<string | undefined>(undefined);
  const [loading, setLoading] = useState(false);
  const [detail, setDetail] = useState<any>(null);
  const [roleFor, setRoleFor] = useState<UserRow | null>(null);
  const [roleForm] = Form.useForm();
  const [tagsOpen, setTagsOpen] = useState(false);
  const [tagList, setTagList] = useState<any[]>([]);
  const [newTagName, setNewTagName] = useState('');
  const [userTagIds, setUserTagIds] = useState<number[]>([]);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await http.get('/admin/users', {
        params: { page, page_size: 10, keyword, ban: banFilter ?? '' },
      });
      setList(data.list || []);
      setTotal(data.total || 0);
    } finally {
      setLoading(false);
    }
  }, [page, keyword, banFilter]);

  useEffect(() => {
    load();
  }, [load]);

  const toggleBan = async (row: UserRow) => {
    await http.post(`/admin/users/${row.id}/ban`, { ban: row.ban === 1 ? 0 : 1 });
    load();
    if (detail?.user?.id === row.id) setDetail(null);
  };

  const openDetail = async (row: UserRow) => {
    const data = await http.get(`/admin/users/${row.id}`);
    setDetail(data);
    setUserTagIds((data.tags || []).map((t: any) => t.id));
  };

  const loadTags = useCallback(async () => {
    const data = await http.get('/admin/tags');
    setTagList(data.list || []);
  }, []);

  const addTag = async () => {
    if (!newTagName.trim()) return;
    await http.post('/admin/tags', { name: newTagName.trim() });
    setNewTagName('');
    loadTags();
  };

  const saveUserTags = async () => {
    await http.post(`/admin/users/${detail.user.id}/tags`, { tag_ids: userTagIds });
    openDetail(detail.user);
  };

  const submitRole = async (values: { role: string }) => {
    if (!roleFor) return;
    await http.post(`/admin/users/${roleFor.id}/platform-role`, values);
    setRoleFor(null);
    roleForm.resetFields();
    load();
  };

  return (
    <Card
      title="用户列表"
      extra={
        <Space>
          <Input.Search
            placeholder="邮箱 / 昵称 / QQ"
            allowClear
            onSearch={(v) => {
              setPage(1);
              setKeyword(v);
            }}
            style={{ width: 220 }}
          />
          <Select
            placeholder="封禁状态"
            allowClear
            style={{ width: 110 }}
            onChange={(v) => {
              setPage(1);
              setBanFilter(v);
            }}
            options={[
              { value: 0, label: '正常' },
              { value: 1, label: '已封禁' },
            ]}
          />
          <Button icon={<TagsOutlined />} onClick={() => { setTagsOpen(true); loadTags(); }}>
            标签管理
          </Button>
          <Button icon={<ReloadOutlined />} onClick={load} />
        </Space>
      }
    >
      <Table<UserRow>
        rowKey="id"
        loading={loading}
        dataSource={list}
        pagination={{ current: page, total, pageSize: 10, onChange: setPage }}
        columns={[
          { title: 'ID', dataIndex: 'id', width: 60 },
          { title: '邮箱', dataIndex: 'email' },
          { title: '昵称', dataIndex: 'username' },
          { title: 'QQ', dataIndex: 'qq', render: (v) => v || '-' },
          {
            title: '平台角色',
            dataIndex: 'platform_role',
            width: 100,
            render: (v) =>
              v ? <Tag color={v === 'superadmin' ? 'purple' : 'blue'}>{v}</Tag> : <Tag>普通用户</Tag>,
          },
          {
            title: '状态',
            dataIndex: 'ban',
            width: 80,
            render: (v) => (v === 1 ? <Tag color="red">封禁</Tag> : <Tag color="green">正常</Tag>),
          },
          { title: '最近登录', dataIndex: 'last_login_time', width: 160, render: (v) => v || '-' },
          {
            title: '操作',
            width: 200,
            render: (_, r) => (
              <Space>
                <a onClick={() => openDetail(r)}>详情</a>
                <a
                  onClick={() => {
                    setRoleFor(r);
                    roleForm.setFieldsValue({ role: r.platform_role });
                  }}
                >
                  设置角色
                </a>
                {r.ban === 1 ? (
                  <Popconfirm title="解封该用户?" onConfirm={() => toggleBan(r)}>
                    <a>解封</a>
                  </Popconfirm>
                ) : (
                  <Popconfirm title="封禁后立即下线全部会话" onConfirm={() => toggleBan(r)}>
                    <a style={{ color: '#ff4d4f' }}>封禁</a>
                  </Popconfirm>
                )}
              </Space>
            ),
          },
        ]}
      />

      <Modal
        title={`设置平台角色：${roleFor?.email || ''}`}
        open={!!roleFor}
        onCancel={() => setRoleFor(null)}
        onOk={() => roleForm.submit()}
        destroyOnClose
      >
        <Form form={roleForm} layout="vertical" onFinish={submitRole}>
          <Form.Item name="role" label="平台角色" rules={[{ required: true }]}>
            <Select
              options={[
                { value: '', label: '普通用户' },
                { value: 'ops', label: '运营 (ops)' },
                { value: 'superadmin', label: '超级管理员 (superadmin)' },
              ]}
            />
          </Form.Item>
        </Form>
      </Modal>

      <Drawer title={detail ? `用户：${detail.user.email}` : ''} width={720} open={!!detail} onClose={() => setDetail(null)}>
        {detail && (
          <>
            <Descriptions size="small" column={2} bordered>
              <Descriptions.Item label="ID">{detail.user.id}</Descriptions.Item>
              <Descriptions.Item label="昵称">{detail.user.username}</Descriptions.Item>
              <Descriptions.Item label="QQ">{detail.user.qq || '-'}</Descriptions.Item>
              <Descriptions.Item label="平台角色">{detail.user.platform_role || '普通用户'}</Descriptions.Item>
              <Descriptions.Item label="最近登录">{detail.user.last_login_time || '-'}</Descriptions.Item>
              <Descriptions.Item label="注册时间">{detail.user.created_time}</Descriptions.Item>
            </Descriptions>

            <Card title="登录会话（最近 50）" size="small" style={{ marginTop: 16 }}>
              <Table
                rowKey="id"
                size="small"
                dataSource={detail.sessions}
                pagination={false}
                columns={[
                  { title: '设备', dataIndex: 'device_name' },
                  { title: '平台', dataIndex: 'platform', width: 90 },
                  { title: 'IP', dataIndex: 'ip', width: 130 },
                  { title: '状态', dataIndex: 'status', width: 90, render: (v) => <Tag>{v}</Tag> },
                  { title: '登录时间', dataIndex: 'created_time', width: 160 },
                ]}
              />
            </Card>

            <Card title="加入的空间" size="small" style={{ marginTop: 16 }}>
              <Table
                rowKey="id"
                size="small"
                dataSource={detail.servers}
                pagination={false}
                columns={[
                  { title: '空间', render: (_, r: any) => r.server?.name || r.server_id },
                  { title: '角色', dataIndex: 'role_key', width: 90, render: (v) => <Tag>{v}</Tag> },
                  { title: '状态', dataIndex: 'status', width: 90 },
                ]}
              />
            </Card>

            <Card
              title="用户标签（文档可见性使用）"
              size="small"
              style={{ marginTop: 16 }}
              extra={<Button size="small" onClick={saveUserTags}>保存标签</Button>}
            >
              <Select
                mode="multiple"
                style={{ width: '100%' }}
                placeholder="选择标签"
                value={userTagIds}
                onChange={setUserTagIds}
                options={tagList.map((t: any) => ({ value: t.id, label: t.name }))}
              />
            </Card>
          </>
        )}
      </Drawer>
      <Modal
        title="用户标签管理"
        open={tagsOpen}
        onCancel={() => setTagsOpen(false)}
        footer={null}
      >
        <Space.Compact style={{ width: '100%', marginBottom: 12 }}>
          <Input
            placeholder="新标签名（回车添加）"
            value={newTagName}
            onChange={(e) => setNewTagName(e.target.value)}
            onPressEnter={addTag}
          />
          <Button type="primary" onClick={addTag}>
            添加
          </Button>
        </Space.Compact>
        <Table
          rowKey="id"
          size="small"
          dataSource={tagList}
          pagination={false}
          columns={[
            { title: '标签', dataIndex: 'name', render: (v) => <Tag color="purple">{v}</Tag> },
            { title: '用户数', dataIndex: 'user_count', width: 80 },
            {
              title: '操作',
              width: 70,
              render: (_, r: any) => (
                <Popconfirm
                  title="删除标签？（用户与文档上的引用同步失效）"
                  onConfirm={async () => {
                    await http.delete(`/admin/tags/${r.id}`);
                    loadTags();
                  }}
                >
                  <a style={{ color: '#ff4d4f' }}>删除</a>
                </Popconfirm>
              ),
            },
          ]}
        />
      </Modal>
    </Card>
  );
}
