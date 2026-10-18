import { useCallback, useEffect, useState } from 'react';
import {
  Button,
  Card,
  Form,
  Input,
  Modal,
  Popconfirm,
  Radio,
  Select,
  Space,
  Table,
  Tag,
  Typography,
  message,
} from 'antd';
import { PlusOutlined, ReloadOutlined, SendOutlined } from '@ant-design/icons';
import http from '../api';

interface BatchRow {
  batch_uuid: string;
  title: string;
  category: string;
  scope: string;
  necessity: string;
  recipients: number;
  unread: number;
  expires_time: string | null;
  status: 'active' | 'expired';
  created_time: string;
}

const categoryText: Record<string, string> = {
  announcement: '公告',
  personal_update: '个人动态',
  service_notice: '服务通知',
};

export default function Notifications() {
  const [list, setList] = useState<BatchRow[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [keyword, setKeyword] = useState('');
  const [loading, setLoading] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const [sending, setSending] = useState(false);
  const [form] = Form.useForm();
  const scopeWatch = Form.useWatch('scope', form);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await http.get('/admin/notifications', {
        params: { page, page_size: 10, keyword },
      });
      setList(data.list || []);
      setTotal(data.total || 0);
    } finally {
      setLoading(false);
    }
  }, [page, keyword]);

  useEffect(() => {
    load();
  }, [load]);

  const send = async (values: any) => {
    setSending(true);
    try {
      await http.post('/admin/notifications', values);
      message.success('通知已发送');
      setCreateOpen(false);
      form.resetFields();
      load();
    } finally {
      setSending(false);
    }
  };

  const expire = async (row: BatchRow) => {
    await http.post(`/admin/notifications/${row.batch_uuid}/expire`);
    message.success('已撤回，用户侧立即生效');
    load();
  };

  const remove = async (row: BatchRow) => {
    await http.delete(`/admin/notifications/${row.batch_uuid}`);
    message.success('已删除');
    load();
  };

  return (
    <Card
      title="站内通知管理"
      extra={
        <Space>
          <Input.Search
            placeholder="标题搜索"
            allowClear
            style={{ width: 180 }}
            onSearch={(v) => {
              setPage(1);
              setKeyword(v || '');
            }}
          />
          <Button icon={<ReloadOutlined />} onClick={load} />
          <Button
            type="primary"
            icon={<SendOutlined />}
            onClick={() => {
              form.resetFields();
              setCreateOpen(true);
            }}
          >
            发送通知
          </Button>
        </Space>
      }
    >
      <Table<BatchRow>
        rowKey="batch_uuid"
        loading={loading}
        dataSource={list}
        pagination={{ current: page, total, pageSize: 10, onChange: setPage }}
        columns={[
          { title: '标题', dataIndex: 'title', ellipsis: true },
          {
            title: '类别',
            dataIndex: 'category',
            width: 100,
            render: (v) => <Tag color={v === 'announcement' ? 'blue' : v === 'service_notice' ? 'orange' : 'green'}>{categoryText[v] || v}</Tag>,
          },
          {
            title: '范围',
            dataIndex: 'scope',
            width: 90,
            render: (v) => (v === 'community' ? <Tag color="purple">广播</Tag> : <Tag>个人</Tag>),
          },
          {
            title: '必要性',
            dataIndex: 'necessity',
            width: 90,
            render: (v) => (v === 'necessary' ? <Tag color="red">必要</Tag> : <Tag>可选</Tag>),
          },
          {
            title: '触达（未读）',
            width: 120,
            render: (_, r) => `${r.recipients}（${r.unread}）`,
          },
          {
            title: '状态',
            dataIndex: 'status',
            width: 90,
            render: (v, r) => (v === 'expired' ? <Tag>已过期</Tag> : r.expires_time ? <Tag color="gold">定时过期</Tag> : <Tag color="green">生效中</Tag>),
          },
          { title: '发送时间', dataIndex: 'created_time', width: 170 },
          {
            title: '操作',
            width: 150,
            render: (_, r) => (
              <Space>
                {r.status === 'active' && (
                  <Popconfirm title="撤回后用户侧立即不可见，确定？" onConfirm={() => expire(r)}>
                    <Button size="small">撤回</Button>
                  </Popconfirm>
                )}
                <Popconfirm title="物理删除整批通知，不可恢复，确定？" onConfirm={() => remove(r)}>
                  <Button size="small" danger>
                    删除
                  </Button>
                </Popconfirm>
              </Space>
            ),
          },
        ]}
      />

      <Modal
        title="发送站内通知"
        open={createOpen}
        onCancel={() => setCreateOpen(false)}
        confirmLoading={sending}
        onOk={() => form.submit()}
        destroyOnClose
        width={640}
      >
        <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>
          正文为安全 HTML（自动生成纯文本回退）；广播会给每位用户插入一条回执，个人通知发给指定邮箱用户。撤回与删除均按发送批次操作。
        </Typography.Paragraph>
        <Form form={form} layout="vertical" onFinish={send} initialValues={{ scope: 'community', category: 'announcement', necessity: 'optional' }}>
          <Form.Item name="scope" label="范围" rules={[{ required: true }]}>
            <Radio.Group
              options={[
                { value: 'community', label: '广播（全体用户）' },
                { value: 'personal', label: '个人（指定邮箱）' },
              ]}
            />
          </Form.Item>
          {scopeWatch === 'personal' && (
            <Form.Item name="target_email" label="目标用户邮箱" rules={[{ required: true, type: 'email', message: '请输入有效邮箱' }]}>
              <Input placeholder="user@example.com" />
            </Form.Item>
          )}
          <Form.Item name="title" label="标题" rules={[{ required: true, max: 240 }]}>
            <Input placeholder="通知标题" />
          </Form.Item>
          <Form.Item name="summary_text" label="摘要（列表页展示）" rules={[{ max: 320 }]}>
            <Input placeholder="一句话摘要，可留空" />
          </Form.Item>
          <Form.Item name="body_html" label="正文 HTML">
            <Input.TextArea rows={5} placeholder={'<p>支持基础标签，如 <a>、<p>、<strong>；不要写脚本或内联样式</p>'} />
          </Form.Item>
          <Form.Item name="body_text" label="纯文本回退（可留空自动生成）">
            <Input.TextArea rows={2} placeholder="留空时自动从 HTML 派生" />
          </Form.Item>
          <Space size="large">
            <Form.Item name="category" label="类别" rules={[{ required: true }]} style={{ minWidth: 160 }}>
              <Select
                options={[
                  { value: 'announcement', label: '公告' },
                  { value: 'personal_update', label: '个人动态' },
                  { value: 'service_notice', label: '服务通知（必要类）' },
                ]}
              />
            </Form.Item>
            <Form.Item name="necessity" label="必要性" rules={[{ required: true }]} style={{ minWidth: 140 }}>
              <Select
                options={[
                  { value: 'optional', label: '可选（受偏好控制）' },
                  { value: 'necessary', label: '必要（不可关闭）' },
                ]}
              />
            </Form.Item>
          </Space>
          <Form.Item
            name="expires_time"
            label="过期时间（可留空，格式 YYYY-MM-DD HH:mm:ss）"
            rules={[
              {
                pattern: /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/,
                message: '格式应为 YYYY-MM-DD HH:mm:ss',
              },
            ]}
          >
            <Input placeholder="2030-01-01 00:00:00" style={{ width: 240 }} />
          </Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}
