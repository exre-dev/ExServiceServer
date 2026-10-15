import { useCallback, useEffect, useState } from 'react';
import { Button, Card, Popconfirm, Space, Table, Tag } from 'antd';
import { ReloadOutlined } from '@ant-design/icons';
import { useParams } from 'react-router-dom';
import http from '../../api';

const TYPE_TEXT: Record<string, string> = {
  third_party: '第三方平台',
  plugin: '插件',
};

export default function ServerClients() {
  const { sid } = useParams();
  const [list, setList] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await http.get(`/server/${sid}/clients`);
      setList(data.list || []);
    } finally {
      setLoading(false);
    }
  }, [sid]);

  useEffect(() => {
    load();
  }, [load]);

  const act = async (clientId: string, action: 'disconnect' | 'connect') => {
    await http.post(`/server/${sid}/clients/${action}`, { client_id: clientId });
    load();
  };

  return (
    <Card
      title="应用授权"
      extra={<Button icon={<ReloadOutlined />} onClick={load} />}
    >
      <Table
        rowKey="id"
        loading={loading}
        dataSource={list}
        pagination={false}
        columns={[
          { title: 'ID', dataIndex: 'id', width: 60 },
          { title: '应用名称', dataIndex: 'name' },
          { title: '类型', dataIndex: 'type', render: () => <Tag color="geekblue">插件</Tag> },
          {
            title: 'Scopes',
            dataIndex: 'scopes',
            render: (v: string[]) => v?.map((s) => <Tag key={s}>{s}</Tag>),
          },
          {
            title: '平台状态',
            dataIndex: 'client_status',
            width: 90,
            render: (v) => (v === 'active' ? <Tag color="green">正常</Tag> : <Tag color="red">已停用</Tag>),
          },
          {
            title: '空间授权',
            dataIndex: 'link_status',
            width: 100,
            render: (v) =>
              v === 'active' ? <Tag color="green">已连接</Tag> : <Tag color="orange">已断开</Tag>,
          },
          {
            title: '操作',
            width: 120,
            render: (_, r) =>
              r.link_status === 'active' ? (
                <Popconfirm
                  title="断开后该应用立即无法获取本空间数据，相关登录会话一并下线"
                  onConfirm={() => act(r.client_id, 'disconnect')}
                >
                  <a style={{ color: '#ff4d4f' }}>断开</a>
                </Popconfirm>
              ) : (
                <Popconfirm title="恢复该应用对本空间的数据访问?" onConfirm={() => act(r.client_id, 'connect')}>
                  <a>恢复</a>
                </Popconfirm>
              ),
          },
        ]}
      />
    </Card>
  );
}
