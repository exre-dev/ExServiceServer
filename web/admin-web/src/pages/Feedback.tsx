import { useCallback, useEffect, useState } from 'react';
import {
  Button,
  Card,
  Descriptions,
  Drawer,
  Input,
  Modal,
  Select,
  Space,
  Table,
  Tag,
  Typography,
  message,
} from 'antd';
import { ReloadOutlined } from '@ant-design/icons';
import http from '../api';

interface FeedbackRow {
  id: number;
  uuid: string;
  area: string;
  kind: string;
  title: string;
  user: { id: number; email: string; username: string } | null;
  status: string;
  admin_status: string;
  attachment_count: number;
  created_time: string;
}

interface FeedbackDetail extends FeedbackRow {
  form: Record<string, unknown>;
  admin_note: string | null;
  attachments: {
    id: number;
    uuid: string;
    ordinal: number;
    original_name: string;
    content_type: string;
    byte_length: number;
    sha256: string;
    status: string;
    created_time: string;
  }[];
}

const kindText: Record<string, string> = {
  bug: '缺陷', feature: '功能建议', asset: '素材', request: '需求申请',
  content: '内容问题', campaign: '活动', suggestion: '建议', complaint: '投诉',
};
const adminStatusText: Record<string, { text: string; color: string }> = {
  pending: { text: '待处理', color: 'orange' },
  processing: { text: '处理中', color: 'blue' },
  resolved: { text: '已解决', color: 'green' },
};

export default function Feedback() {
  const [list, setList] = useState<FeedbackRow[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [keyword, setKeyword] = useState('');
  const [adminStatus, setAdminStatus] = useState('');
  const [kind, setKind] = useState('');
  const [loading, setLoading] = useState(false);
  const [detail, setDetail] = useState<FeedbackDetail | null>(null);
  const [detailOpen, setDetailOpen] = useState(false);
  const [noteOpen, setNoteOpen] = useState(false);
  const [noteValue, setNoteValue] = useState('');
  const [noteRow, setNoteRow] = useState<FeedbackRow | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await http.get('/admin/feedback', {
        params: { page, page_size: 10, keyword, admin_status: adminStatus, kind },
      });
      setList(data.list || []);
      setTotal(data.total || 0);
    } finally {
      setLoading(false);
    }
  }, [page, keyword, adminStatus, kind]);

  useEffect(() => {
    load();
  }, [load]);

  const openDetail = async (row: FeedbackRow) => {
    const data = await http.get(`/admin/feedback/${row.id}`);
    setDetail(data);
    setDetailOpen(true);
  };

  const setStatus = async (row: FeedbackRow, status: string, note?: string) => {
    await http.post(`/admin/feedback/${row.id}/status`, { admin_status: status, ...(note !== undefined ? { admin_note: note } : {}) });
    message.success('状态已更新');
    load();
    if (detailOpen && detail?.id === row.id) {
      setDetail(await http.get(`/admin/feedback/${row.id}`));
    }
  };

  return (
    <Card
      title="反馈工单"
      extra={
        <Space>
          <Input.Search
            placeholder="标题 / UUID"
            allowClear
            style={{ width: 180 }}
            onSearch={(v) => {
              setPage(1);
              setKeyword(v || '');
            }}
          />
          <Select
            placeholder="类型"
            allowClear
            style={{ width: 110 }}
            onChange={(v) => {
              setPage(1);
              setKind(v || '');
            }}
            options={Object.entries(kindText).map(([value, label]) => ({ value, label }))}
          />
          <Select
            placeholder="处理状态"
            allowClear
            style={{ width: 110 }}
            onChange={(v) => {
              setPage(1);
              setAdminStatus(v || '');
            }}
            options={Object.entries(adminStatusText).map(([value, v]) => ({ value, label: v.text }))}
          />
          <Button icon={<ReloadOutlined />} onClick={load} />
        </Space>
      }
    >
      <Table<FeedbackRow>
        rowKey="id"
        loading={loading}
        dataSource={list}
        pagination={{ current: page, total, pageSize: 10, onChange: setPage }}
        columns={[
          { title: 'ID', dataIndex: 'id', width: 60 },
          { title: '标题', dataIndex: 'title', ellipsis: true, render: (v, r) => <a onClick={() => openDetail(r)}>{v || '(无标题)'}</a> },
          { title: '类型', dataIndex: 'kind', width: 90, render: (v) => <Tag>{kindText[v] || v}</Tag> },
          { title: '入口', dataIndex: 'area', width: 90, render: (v) => (v === 'community' ? '社区' : '开发') },
          {
            title: '提交人',
            width: 180,
            render: (_, r) => (r.user ? <span>{r.user.username || r.user.email}</span> : '-'),
          },
          { title: '附件', dataIndex: 'attachment_count', width: 60 },
          {
            title: '提交状态',
            dataIndex: 'status',
            width: 90,
            render: (v) => (v === 'completed' ? <Tag color="green">已提交</Tag> : <Tag color="orange">收取中</Tag>),
          },
          {
            title: '处理状态',
            dataIndex: 'admin_status',
            width: 90,
            render: (v) => {
              const meta = adminStatusText[v] || { text: v, color: '' };
              return <Tag color={meta.color}>{meta.text}</Tag>;
            },
          },
          { title: '提交时间', dataIndex: 'created_time', width: 165 },
          {
            title: '操作',
            width: 200,
            render: (_, r) => (
              <Space>
                {r.admin_status !== 'processing' && (
                  <Button size="small" onClick={() => setStatus(r, 'processing')}>
                    开始处理
                  </Button>
                )}
                {r.admin_status !== 'resolved' && (
                  <Button
                    size="small"
                    type="primary"
                    ghost
                    onClick={() => {
                      setNoteRow(r);
                      setNoteValue('');
                      setNoteOpen(true);
                    }}
                  >
                    标记解决
                  </Button>
                )}
                <Button size="small" onClick={() => openDetail(r)}>
                  详情
                </Button>
              </Space>
            ),
          },
        ]}
      />

      <Drawer
        title={detail ? `反馈详情 · ${detail.title || detail.uuid}` : ''}
        open={detailOpen}
        onClose={() => setDetailOpen(false)}
        width={560}
      >
        {detail && (
          <>
            <Descriptions column={2} size="small" bordered>
              <Descriptions.Item label="类型">{kindText[detail.kind] || detail.kind}</Descriptions.Item>
              <Descriptions.Item label="入口">{detail.area === 'community' ? '社区' : '开发'}</Descriptions.Item>
              <Descriptions.Item label="提交人" span={2}>
                {detail.user ? `${detail.user.username || '-'} (${detail.user.email})` : '-'}
              </Descriptions.Item>
              <Descriptions.Item label="提交状态">{detail.status === 'completed' ? '已提交' : '收取中'}</Descriptions.Item>
              <Descriptions.Item label="处理状态">{adminStatusText[detail.admin_status]?.text || detail.admin_status}</Descriptions.Item>
              <Descriptions.Item label="提交时间" span={2}>{detail.created_time}</Descriptions.Item>
            </Descriptions>
            <Typography.Title level={5} style={{ marginTop: 16 }}>
              表单内容
            </Typography.Title>
            <pre style={{ background: '#fafafa', padding: 12, borderRadius: 6, maxHeight: 300, overflow: 'auto', fontSize: 12 }}>
              {JSON.stringify(detail.form, null, 2)}
            </pre>
            {detail.attachments.length > 0 && (
              <>
                <Typography.Title level={5} style={{ marginTop: 16 }}>
                  附件（{detail.attachments.length}）
                </Typography.Title>
                {detail.attachments.map((a) => (
                  <div key={a.id} style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 4 }}>
                    <Tag color={a.status === 'uploaded' ? 'green' : 'orange'}>{a.status === 'uploaded' ? '已上传' : '未传'}</Tag>
                    <Typography.Text ellipsis style={{ maxWidth: 260 }}>{a.original_name}</Typography.Text>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                      {(a.byte_length / 1024).toFixed(1)} KB
                    </Typography.Text>
                  </div>
                ))}
              </>
            )}
            {detail.admin_note && (
              <>
                <Typography.Title level={5} style={{ marginTop: 16 }}>
                  处理备注
                </Typography.Title>
                <Typography.Paragraph>{detail.admin_note}</Typography.Paragraph>
              </>
            )}
          </>
        )}
      </Drawer>

      <Modal
        title="标记解决"
        open={noteOpen}
        onCancel={() => setNoteOpen(false)}
        onOk={() => {
          if (!noteRow) return;
          void setStatus(noteRow, 'resolved', noteValue);
          setNoteOpen(false);
        }}
      >
        <Input.TextArea
          rows={3}
          placeholder="处理备注（可选）"
          value={noteValue}
          onChange={(e) => setNoteValue(e.target.value)}
        />
      </Modal>
    </Card>
  );
}
