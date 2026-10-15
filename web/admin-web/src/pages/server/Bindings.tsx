import { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  Button,
  Card,
  Form,
  Modal,
  Popconfirm,
  Select,
  Space,
  Table,
  Tag,
  Typography,
  message,
} from 'antd';
import { PlusOutlined, ReloadOutlined } from '@ant-design/icons';
import { useParams } from 'react-router-dom';
import http from '../../api';

const STATUS_TAG: Record<string, { color: string; text: string }> = {
  active: { color: 'green', text: '生效' },
  disabled: { color: 'orange', text: '已停用' },
  revoked: { color: 'red', text: '已吊销' },
};

export default function ServerBindings() {
  const { sid } = useParams();
  const [list, setList] = useState<any[]>([]);
  const [options, setOptions] = useState<any[]>([]);
  const [serverStatus, setServerStatus] = useState('active');
  const [loading, setLoading] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const [form] = Form.useForm();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await http.get(`/server/${sid}/bindings`);
      setList(data.list || []);
      setOptions(data.plugin_options || []);
      setServerStatus(data.server_status);
    } finally {
      setLoading(false);
    }
  }, [sid]);

  useEffect(() => {
    load();
  }, [load]);

  const create = async (values: any) => {
    const data = await http.post(`/server/${sid}/bindings`, values);
    setCreateOpen(false);
    form.resetFields();
    load();
    Modal.success({
      title: '绑定码已生成',
      content: (
        <Typography.Paragraph copyable code>
          {data.code}
        </Typography.Paragraph>
      ),
    });
  };

  const act = async (id: number, action: string) => {
    const data = await http.post(`/server/${sid}/bindings/${id}/${action}`);
    load();
    if (action === 'reset' && data?.code) {
      Modal.success({
        title: '绑定码已重置（旧码立即失效）',
        content: (
          <Typography.Paragraph copyable code>
            {data.code}
          </Typography.Paragraph>
        ),
      });
    }
  };

  return (
    <Card
      title="插件与绑定码"
      extra={
        <Space>
          <Button icon={<ReloadOutlined />} onClick={load} />
          <Button
            type="primary"
            icon={<PlusOutlined />}
            disabled={serverStatus !== 'active'}
            onClick={() => setCreateOpen(true)}
          >
            生成绑定码
          </Button>
        </Space>
      }
    >
      <Alert
        type="info"
        showIcon
        style={{ marginBottom: 12 }}
        message="将绑定码填入从外部平台购买的插件配置。首次校验会绑定当前服务器（sv_licenseKey 哈希）；更换服务器需在此重置绑定码。"
      />
      <Table
        rowKey="id"
        loading={loading}
        dataSource={list}
        pagination={false}
        columns={[
          { title: 'ID', dataIndex: 'id', width: 60 },
          { title: '插件', render: (_, r) => `${r.plugin?.name}（${r.plugin?.slug}）` },
          {
            title: '绑定码',
            dataIndex: 'code',
            render: (v) => (
              <a
                onClick={() => {
                  navigator.clipboard?.writeText(v);
                  message.success('已复制');
                }}
              >
                <code>{v}</code>
              </a>
            ),
          },
          {
            title: '状态',
            dataIndex: 'status',
            width: 90,
            render: (v) => <Tag color={STATUS_TAG[v]?.color}>{STATUS_TAG[v]?.text || v}</Tag>,
          },
          {
            title: '服务器绑定',
            width: 110,
            render: (_, r) => (r.server_key_hash ? <Tag color="green">已锁定</Tag> : <Tag>未激活</Tag>),
          },
          { title: '最近心跳', dataIndex: 'last_heartbeat', width: 150, render: (v) => v || '从未' },
          {
            title: '操作',
            width: 200,
            render: (_, r) => (
              <Space>
                {r.status !== 'revoked' && (
                  <>
                    <Popconfirm title="重置后旧码立即失效并解除服务器绑定" onConfirm={() => act(r.id, 'reset')}>
                      <a>重置</a>
                    </Popconfirm>
                    {r.status === 'active' ? (
                      <Popconfirm title="停用后插件校验立即失败" onConfirm={() => act(r.id, 'disable')}>
                        <a style={{ color: '#faad14' }}>停用</a>
                      </Popconfirm>
                    ) : (
                      <Popconfirm title="重新启用?" onConfirm={() => act(r.id, 'enable')}>
                        <a>启用</a>
                      </Popconfirm>
                    )}
                  </>
                )}
              </Space>
            ),
          },
        ]}
      />

      <Modal
        title="生成绑定码"
        open={createOpen}
        onCancel={() => setCreateOpen(false)}
        onOk={() => form.submit()}
        destroyOnClose
      >
        <Form form={form} layout="vertical" onFinish={create}>
          <Form.Item name="plugin_id" label="选择在线插件" rules={[{ required: true }]}>
            <Select
              options={options.map((p: any) => ({
                value: p.id,
                label: `${p.name}（${p.slug}）`,
                disabled: list.some((b: any) => b.plugin_id === p.id),
              }))}
            />
          </Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}
