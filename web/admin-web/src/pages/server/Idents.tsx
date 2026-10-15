import { useCallback, useEffect, useState } from 'react';
import { Button, Card, Form, Input, Modal, Popconfirm, Select, Space, Table, Tag, Alert } from 'antd';
import { PlusOutlined, ReloadOutlined } from '@ant-design/icons';
import { useParams } from 'react-router-dom';
import http from '../../api';

const TYPE_TEXT: Record<string, string> = {
  fivem_license: 'FiveM License',
  steam: 'Steam',
  discord: 'Discord',
  qq: 'QQ',
};

export default function Idents() {
  const { sid } = useParams();
  const [list, setList] = useState<any[]>([]);
  const [members, setMembers] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);
  const [bindOpen, setBindOpen] = useState(false);
  const [form] = Form.useForm();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [idents, mem] = await Promise.all([
        http.get(`/server/${sid}/idents`),
        http.get(`/server/${sid}/members`),
      ]);
      setList(idents.list || []);
      setMembers((mem.list || []).filter((m: any) => m.status === 'active'));
    } finally {
      setLoading(false);
    }
  }, [sid]);

  useEffect(() => {
    load();
  }, [load]);

  const bind = async (values: any) => {
    await http.post(`/server/${sid}/idents/bind`, values);
    setBindOpen(false);
    form.resetFields();
    load();
  };

  const unbind = async (id: number) => {
    await http.post(`/server/${sid}/idents/${id}/unbind`);
    load();
  };

  return (
    <Card
      title="游戏绑定"
      extra={
        <Space>
          <Button icon={<ReloadOutlined />} onClick={load} />
          <Button type="primary" icon={<PlusOutlined />} onClick={() => setBindOpen(true)}>
            录入绑定码
          </Button>
        </Space>
      }
    >
      <Alert
        type="info"
        showIcon
        style={{ marginBottom: 12 }}
        message="流程：玩家在游戏内输入 /exbind 获得 6 位绑定码 → 管理员在此录入并选择归属用户 → 绑定成功后玩家自动加入空间。绑定码 5 分钟内有效且只能使用一次。"
      />
      <Table
        rowKey="id"
        loading={loading}
        dataSource={list}
        pagination={{ pageSize: 10 }}
        columns={[
          { title: 'ID', dataIndex: 'id', width: 60 },
          {
            title: '游戏标识',
            dataIndex: 'ident_value',
            render: (v) => <code>{v.length > 20 ? '...' + v.slice(-16) : v}</code>,
          },
          { title: '类型', dataIndex: 'ident_type', width: 120, render: (v) => <Tag>{TYPE_TEXT[v] || v}</Tag> },
          { title: '归属用户', render: (_, r) => `${r.user?.username}（${r.user?.email}）` },
          { title: '绑定时间', dataIndex: 'created_time', width: 160 },
          {
            title: '操作',
            width: 80,
            render: (_, r) => (
              <Popconfirm title="解绑后该玩家游戏权限立即失效" onConfirm={() => unbind(r.id)}>
                <a style={{ color: '#ff4d4f' }}>解绑</a>
              </Popconfirm>
            ),
          },
        ]}
      />

      <Modal
        title="录入游戏绑定码"
        open={bindOpen}
        onCancel={() => setBindOpen(false)}
        onOk={() => form.submit()}
        destroyOnClose
      >
        <Form form={form} layout="vertical" onFinish={bind}>
          <Form.Item name="ticket" label="玩家提供的 6 位绑定码" rules={[{ required: true }]}>
            <Input placeholder="654321" maxLength={6} style={{ width: 160 }} />
          </Form.Item>
          <Form.Item name="user_id" label="归属用户（从成员中选择）" rules={[{ required: true }]}>
            <Select
              showSearch
              optionFilterProp="label"
              options={members.map((m: any) => ({
                value: m.user_id,
                label: `${m.user?.username}（${m.user?.email}）`,
              }))}
            />
          </Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}
