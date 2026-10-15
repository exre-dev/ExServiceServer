import { useCallback, useEffect, useState } from 'react';
import {
  Button,
  Card,
  Form,
  Input,
  Modal,
  Popconfirm,
  Select,
  Space,
  Table,
  Tag,
} from 'antd';
import { PlusOutlined, ReloadOutlined } from '@ant-design/icons';
import { useParams } from 'react-router-dom';
import http from '../../api';

const ROLE_TAG: Record<string, { color: string; text: string }> = {
  owner: { color: 'purple', text: 'Owner' },
  admin: { color: 'blue', text: '管理员' },
  member: { color: 'default', text: '成员' },
};
const STATUS_TAG: Record<string, { color: string; text: string }> = {
  active: { color: 'green', text: '正常' },
  banned: { color: 'red', text: '已禁用' },
  left: { color: 'default', text: '已离开' },
};

export default function Members() {
  const { sid } = useParams();
  const [list, setList] = useState<any[]>([]);
  const [myRole, setMyRole] = useState('');
  const [loading, setLoading] = useState(false);
  const [addOpen, setAddOpen] = useState(false);
  const [form] = Form.useForm();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await http.get(`/server/${sid}/members`);
      setList(data.list || []);
      setMyRole(data.my_role);
    } finally {
      setLoading(false);
    }
  }, [sid]);

  useEffect(() => {
    load();
  }, [load]);

  const add = async (values: any) => {
    await http.post(`/server/${sid}/members`, values);
    setAddOpen(false);
    form.resetFields();
    load();
  };

  const act = async (uid: number, action: string, body?: any) => {
    await http.post(`/server/${sid}/members/${uid}/${action}`, body);
    load();
  };

  return (
    <Card
      title="成员管理"
      extra={
        <Space>
          <Button icon={<ReloadOutlined />} onClick={load} />
          <Button type="primary" icon={<PlusOutlined />} onClick={() => setAddOpen(true)}>
            添加成员
          </Button>
        </Space>
      }
    >
      <Table
        rowKey="id"
        loading={loading}
        dataSource={list}
        pagination={{ pageSize: 10 }}
        columns={[
          { title: '用户', render: (_, r) => `${r.user?.username}（${r.user?.email}）` },
          { title: 'QQ', render: (_, r) => r.user?.qq || '-' },
          {
            title: '角色',
            dataIndex: 'role_key',
            width: 100,
            render: (v) => <Tag color={ROLE_TAG[v]?.color}>{ROLE_TAG[v]?.text || v}</Tag>,
          },
          {
            title: '状态',
            dataIndex: 'status',
            width: 90,
            render: (v) => <Tag color={STATUS_TAG[v]?.color}>{STATUS_TAG[v]?.text || v}</Tag>,
          },
          { title: '来源', dataIndex: 'source', width: 80 },
          { title: '加入时间', dataIndex: 'joined_time', width: 160 },
          {
            title: '操作',
            width: 240,
            render: (_, r) =>
              r.role_key === 'owner' ? (
                <Tag>所有者</Tag>
              ) : (
                <Space>
                  {myRole === 'owner' && (
                    <Select
                      size="small"
                      style={{ width: 90 }}
                      defaultValue={r.role_key}
                      disabled={r.status !== 'active'}
                      onChange={(v) => act(r.user_id, 'role', { role: v })}
                      options={[
                        { value: 'member', label: '成员' },
                        { value: 'admin', label: '管理员' },
                      ]}
                    />
                  )}
                  {r.status === 'active' && (
                    <>
                      <Popconfirm title="移除后可重新添加" onConfirm={() => act(r.user_id, 'remove')}>
                        <a>移除</a>
                      </Popconfirm>
                      <Popconfirm title="禁用后该成员游戏权限立即失效" onConfirm={() => act(r.user_id, 'ban')}>
                        <a style={{ color: '#ff4d4f' }}>禁用</a>
                      </Popconfirm>
                    </>
                  )}
                  {r.status === 'banned' && (
                    <Popconfirm title="恢复为正常成员?" onConfirm={() => act(r.user_id, 'unban')}>
                      <a>解除禁用</a>
                    </Popconfirm>
                  )}
                </Space>
              ),
          },
        ]}
      />

      <Modal
        title="添加成员"
        open={addOpen}
        onCancel={() => setAddOpen(false)}
        onOk={() => form.submit()}
        destroyOnClose
      >
        <Form form={form} layout="vertical" onFinish={add} initialValues={{ role: 'member' }}>
          <Form.Item
            name="keyword"
            label="用户邮箱或 QQ（须已在平台注册）"
            rules={[{ required: true }]}
          >
            <Input placeholder="user@example.com" />
          </Form.Item>
          <Form.Item name="role" label="角色">
            <Select
              options={[
                { value: 'member', label: '成员' },
                ...(myRole === 'owner' ? [{ value: 'admin', label: '管理员（仅 Owner 可授予）' }] : []),
              ]}
            />
          </Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}
