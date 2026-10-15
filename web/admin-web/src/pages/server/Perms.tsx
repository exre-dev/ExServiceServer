import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Avatar,
  Button,
  Card,
  Checkbox,
  Empty,
  Input,
  Popconfirm,
  Space,
  Spin,
  Switch,
  Tag,
  Typography,
} from 'antd';
import { CloudDownloadOutlined, ReloadOutlined, UserOutlined } from '@ant-design/icons';
import { useParams } from 'react-router-dom';
import http from '../../api';

interface MemberItem {
  user_id: number;
  username: string;
  email: string;
  role_key: string;
}

interface FeatureItem {
  feature_key: string;
  name: string;
  default_enabled: boolean;
}

interface PluginPermItem {
  binding_id: number;
  binding_status: string;
  plugin_id: number;
  plugin_name: string;
  plugin_slug: string;
  plugin_status: string;
  allowed: boolean;
  features: Record<string, boolean> | null;
  has_perm_row: boolean;
  feature_list: FeatureItem[];
}

interface EditState {
  allowed: boolean;
  features: Record<string, boolean>;
}

const ROLE_TAG: Record<string, { color: string; text: string }> = {
  owner: { color: 'purple', text: 'Owner' },
  admin: { color: 'blue', text: '管理员' },
  member: { color: 'default', text: '成员' },
};

/** 计算某插件的展示态: 显式覆盖 ?? 默认开启 */
function initialEdit(item: PluginPermItem): EditState {
  if (item.features) {
    return { allowed: item.allowed, features: { ...item.features } };
  }
  const defaults: Record<string, boolean> = {};
  for (const f of item.feature_list) defaults[f.feature_key] = f.default_enabled;
  return { allowed: item.allowed, features: defaults };
}

export default function Perms() {
  const { sid } = useParams();
  const [members, setMembers] = useState<MemberItem[]>([]);
  const [myRole, setMyRole] = useState('');
  const [keyword, setKeyword] = useState('');
  const [selectedUid, setSelectedUid] = useState<number | undefined>();
  const [detail, setDetail] = useState<{ member: MemberItem; list: PluginPermItem[] } | null>(null);
  const [edits, setEdits] = useState<Record<number, EditState>>({});
  const [loadingList, setLoadingList] = useState(false);
  const [loadingDetail, setLoadingDetail] = useState(false);

  const loadMembers = useCallback(async () => {
    setLoadingList(true);
    try {
      const data = await http.get(`/server/${sid}/members`);
      setMembers(
        (data.list || [])
          .filter((m: any) => m.status === 'active')
          .map((m: any) => ({
            user_id: m.user_id,
            username: m.user?.username,
            email: m.user?.email,
            role_key: m.role_key,
          }))
      );
      setMyRole(data.my_role);
    } finally {
      setLoadingList(false);
    }
  }, [sid]);

  const loadDetail = useCallback(async (uid: number) => {
    setLoadingDetail(true);
    try {
      const data = await http.get(`/server/${sid}/member-perms`, { params: { user_id: uid } });
      setDetail(data);
      const init: Record<number, EditState> = {};
      for (const item of data.list || []) init[item.plugin_id] = initialEdit(item);
      setEdits(init);
    } finally {
      setLoadingDetail(false);
    }
  }, [sid]);

  useEffect(() => {
    loadMembers();
  }, [loadMembers]);
  useEffect(() => {
    if (selectedUid) loadDetail(selectedUid);
    else setDetail(null);
  }, [selectedUid]);

  const filteredMembers = useMemo(() => {
    const k = keyword.trim().toLowerCase();
    if (!k) return members;
    return members.filter(
      (m) => m.username?.toLowerCase().includes(k) || m.email?.toLowerCase().includes(k)
    );
  }, [members, keyword]);

  const patch = (pluginId: number, p: Partial<EditState>) => {
    setEdits((prev) => ({ ...prev, [pluginId]: { ...prev[pluginId], ...p } }));
  };

  const dirty = (item: PluginPermItem): boolean => {
    const cur = edits[item.plugin_id];
    const init = initialEdit(item);
    if (!cur) return false;
    return cur.allowed !== init.allowed || JSON.stringify(cur.features) !== JSON.stringify(init.features);
  };

  const save = async (item: PluginPermItem) => {
    const cur = edits[item.plugin_id];
    await http.post(`/server/${sid}/plugin-perms`, {
      plugin_id: item.plugin_id,
      user_id: selectedUid,
      allowed: cur.allowed,
      features: cur.features,
    });
    loadDetail(selectedUid!);
  };

  const reset = async (item: PluginPermItem) => {
    await http.post(`/server/${sid}/plugin-perms/reset`, {
      plugin_id: item.plugin_id,
      user_id: selectedUid,
    });
    loadDetail(selectedUid!);
  };

  const push = async () => {
    await http.post(`/server/${sid}/plugin-perms/push`);
  };

  return (
    <div>
      <Alert
        type="info"
        showIcon
        style={{ marginBottom: 12 }}
        message="未设置过的插件默认继承全部默认开启功能；保存后点击「下发权限」可让新版插件（EXCore ≥ 1.1）数秒内同步，不点则等插件心跳（最长 10 分钟）自动同步。"
      />
      <div style={{ display: 'flex', gap: 16, alignItems: 'flex-start' }}>
      {/* ============ 左: 成员列表 ============ */}
      <Card
        size="small"
        title={`成员（${filteredMembers.length}）`}
        style={{ width: 280, flexShrink: 0 }}
        extra={<Button size="small" icon={<ReloadOutlined />} onClick={loadMembers} />}
      >
        <Input.Search
          size="small"
          placeholder="搜索昵称 / 邮箱"
          allowClear
          onChange={(e) => setKeyword(e.target.value)}
          style={{ marginBottom: 8 }}
        />
        <div style={{ maxHeight: 560, overflowY: 'auto' }}>
          {filteredMembers.map((m) => (
            <div
              key={m.user_id}
              onClick={() => setSelectedUid(m.user_id)}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 8,
                padding: '8px',
                borderRadius: 6,
                cursor: 'pointer',
                background: selectedUid === m.user_id ? '#e6f4ff' : 'transparent',
              }}
            >
              <Avatar size="small" icon={<UserOutlined />} />
              <div style={{ minWidth: 0, flex: 1 }}>
                <div style={{ fontSize: 13, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                  {m.username}
                </div>
                <Typography.Text type="secondary" style={{ fontSize: 11 }}>
                  {m.email}
                </Typography.Text>
              </div>
              <Tag style={{ marginInlineEnd: 0 }} color={ROLE_TAG[m.role_key]?.color}>
                {ROLE_TAG[m.role_key]?.text || m.role_key}
              </Tag>
            </div>
          ))}
        </div>
      </Card>

      {/* ============ 右: 选中成员的插件权限 ============ */}
      <Card
        size="small"
        style={{ flex: 1, minWidth: 0 }}
        title="插件权限"
        extra={
          <Button
            size="small"
            icon={<CloudDownloadOutlined />}
            disabled={!detail || detail.list.length === 0}
            onClick={push}
          >
            下发权限
          </Button>
        }
      >
        {!selectedUid ? (
          <Empty description="从左侧选择成员" style={{ padding: '48px 0' }} />
        ) : loadingDetail || !detail ? (
          <Spin style={{ display: 'block', margin: '80px auto' }} />
        ) : (
          <>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
              <Typography.Title level={5} style={{ margin: 0 }}>
                {detail.member.username}
              </Typography.Title>
              <Tag color={ROLE_TAG[detail.member.role_key]?.color}>
                {ROLE_TAG[detail.member.role_key]?.text}
              </Tag>
              <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                {detail.member.email}
              </Typography.Text>
            </div>
            {detail.list.length === 0 ? (
              <Empty description="本空间还没有绑定任何插件（先到「插件与绑定码」生成）" style={{ padding: '32px 0' }} />
            ) : (
              detail.list.map((item) => {
                const cur = edits[item.plugin_id] ?? initialEdit(item);
                const isDirty = dirty(item);
                return (
                  <Card
                    key={item.plugin_id}
                    size="small"
                    style={{ marginBottom: 12 }}
                    title={
                      <Space>
                        {item.plugin_name}
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                          {item.plugin_slug}
                        </Typography.Text>
                      </Space>
                    }
                    extra={
                      <Space>
                        {item.has_perm_row && <Tag color="orange">已自定义</Tag>}
                        {item.binding_status !== 'active' && <Tag color="red">绑定码 {item.binding_status}</Tag>}
                      </Space>
                    }
                  >
                    <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 8 }}>
                      <Space>
                        <span style={{ fontSize: 13 }}>允许使用该插件</span>
                        <Switch
                          size="small"
                          checked={cur.allowed}
                          onChange={(v) => patch(item.plugin_id, { allowed: v })}
                        />
                        {!cur.allowed && <Typography.Text type="danger" style={{ fontSize: 12 }}>禁用后该成员无法使用</Typography.Text>}
                      </Space>
                      <Space>
                        <Button
                          size="small"
                          type="primary"
                          disabled={!isDirty}
                          onClick={() => save(item)}
                        >
                          保存
                        </Button>
                        {item.has_perm_row && (
                          <Popconfirm title="恢复默认（继承全部默认开启功能）?" onConfirm={() => reset(item)}>
                            <Button size="small">恢复默认</Button>
                          </Popconfirm>
                        )}
                      </Space>
                    </div>
                    {item.feature_list.length === 0 ? (
                      <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        该插件未配置功能点
                      </Typography.Text>
                    ) : (
                      <Checkbox.Group
                        value={Object.keys(cur.features).filter((k) => cur.features[k])}
                        onChange={(checked) => {
                          const next: Record<string, boolean> = {};
                          for (const f of item.feature_list) next[f.feature_key] = (checked as string[]).includes(f.feature_key);
                          patch(item.plugin_id, { features: next });
                        }}
                        options={item.feature_list.map((f) => ({
                          value: f.feature_key,
                          label: (
                            <span>
                              {f.name}{' '}
                              <Typography.Text type="secondary" style={{ fontSize: 11 }}>
                                {f.feature_key}
                              </Typography.Text>
                            </span>
                          ),
                        }))}
                      />
                    )}
                  </Card>
                );
              })
            )}
          </>
        )}
      </Card>
      </div>
    </div>
  );
}
