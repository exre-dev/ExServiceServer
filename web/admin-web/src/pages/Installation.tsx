import { useState } from 'react';
import { Alert, Button, Card, Checkbox, Col, Form, Input, InputNumber, Modal, Result, Row, Space, Steps, Tag, Typography } from 'antd';

export interface InstallationStatus {
  installed: boolean;
  ready?: boolean;
  checks?: { name: string; passed: boolean }[];
}

/** 安装接口在 Redis、登录态均未就绪时也必须独立可用。 */
export async function installationRequest<T>(path: string, body?: unknown): Promise<T> {
  const response = await fetch(`/api/installation/${path}`, {
    method: body === undefined ? 'GET' : 'POST',
    cache: 'no-store',
    headers: body === undefined ? {} : { 'Content-Type': 'application/json', 'X-Setup-Request': '1' },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const result = await response.json();
  if (!response.ok) throw new Error(result.msg || '初始化请求失败，请检查服务后重试');
  return result.data;
}

interface InstallationForm {
  force: boolean;
  force_confirmation?: string;
  database: { host: string; port: number; database: string; username: string; password: string };
  redis: { host: string; port: number; database: number; password: string };
  admin: { email: string; username: string; password: string; password_confirmation: string };
}

export default function Installation({ status, onComplete, onRetry }: {
  status: InstallationStatus;
  onComplete: () => void;
  onRetry: () => void;
}) {
  const [form] = Form.useForm<InstallationForm>();
  const [step, setStep] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [completed, setCompleted] = useState(false);
  const [error, setError] = useState('');
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [confirmation, setConfirmation] = useState('');
  const force = Form.useWatch('force', form);

  const next = async () => {
    try {
      if (step === 1) await form.validateFields(['database', 'redis'], { recursive: true });
      setStep(step + 1);
      setError('');
    } catch {
      // 表单直接展示字段错误。
    }
  };

  const submitInstallation = async () => {
    setSubmitting(true);
    setError('');
    try {
      const values = form.getFieldsValue(true);
      values.database.password ||= '';
      values.redis.password ||= '';
      if (values.force) values.force_confirmation = confirmation;
      await installationRequest('install', values);
      form.resetFields();
      setCompleted(true);
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : '初始化失败，请重试');
    } finally {
      setConfirmOpen(false);
      setConfirmation('');
      setSubmitting(false);
    }
  };

  const install = async () => {
    try {
      await form.validateFields();
    } catch {
      return;
    }
    if (form.getFieldValue('force')) {
      setConfirmation('');
      setConfirmOpen(true);
      return;
    }
    await submitInstallation();
  };

  return (
    <div style={{ minHeight: '100vh', background: '#f0f2f5', padding: '48px 16px' }}>
      <Card style={{ maxWidth: 820, margin: '0 auto' }}>
        <Typography.Title level={3}>EXRE 控制台 · 部署初始化</Typography.Title>
        {completed ? (
          <Result status="success" title="初始化完成" subTitle="数据库已导入，超级管理员已创建。请使用刚设置的邮箱和密码登录。"
            extra={<Button type="primary" onClick={onComplete}>前往登录</Button>} />
        ) : (
          <>
            <Typography.Paragraph type="secondary">首次部署需要连接数据库与 Redis，并创建首个超级管理员。</Typography.Paragraph>
            <Steps current={step} items={[{ title: '环境检查' }, { title: '数据库与缓存' }, { title: '管理员账号' }]} style={{ margin: '24px 0' }} />
            {error && <Alert showIcon type="error" message={error} style={{ marginBottom: 24 }} />}
            <div hidden={step !== 0}>
              <Alert type="info" showIcon message="请先创建 PostgreSQL 数据库，并准备可读写的 Redis 服务。默认使用空数据库，强制初始化可重置已有业务表。" style={{ marginBottom: 20 }} />
              {status.checks?.map(check => (
                <div key={check.name} style={{ display: 'flex', justifyContent: 'space-between', padding: '10px 0', borderBottom: '1px solid #f0f0f0' }}>
                  <span>{check.name}</span><Tag color={check.passed ? 'success' : 'error'}>{check.passed ? '通过' : '未通过'}</Tag>
                </div>
              ))}
              {!status.ready && <Button onClick={onRetry} style={{ marginTop: 16 }}>重新检查环境</Button>}
            </div>
            <Form<InstallationForm> form={form} layout="vertical" initialValues={{
              force: false,
              database: { host: '127.0.0.1', port: 5432, database: 'webman', username: 'webman', password: '' },
              redis: { host: '127.0.0.1', port: 6379, database: 0, password: '' },
              admin: { username: '超级管理员' },
            }}>
              <div hidden={step !== 1}>
                <Typography.Title level={5}>PostgreSQL 数据库</Typography.Title>
                <Alert type="warning" showIcon message="数据库须已创建；初始化只创建表和管理员，不导入测试账号。默认拒绝覆盖已有业务表。" style={{ marginBottom: 20 }} />
                <Row gutter={16}>
                  <Col xs={24} sm={16}><Form.Item name={['database', 'host']} label="数据库主机" rules={[{ required: true }]}><Input autoComplete="off" /></Form.Item></Col>
                  <Col xs={24} sm={8}><Form.Item name={['database', 'port']} label="数据库端口" rules={[{ required: true }]}><InputNumber min={1} max={65535} style={{ width: '100%' }} /></Form.Item></Col>
                  <Col xs={24} sm={12}><Form.Item name={['database', 'database']} label="数据库名称" rules={[{ required: true }, { pattern: /^[a-zA-Z0-9_-]{1,63}$/, message: '仅支持字母、数字、下划线和短横线，最长 63 字符' }]}><Input /></Form.Item></Col>
                  <Col xs={24} sm={12}><Form.Item name={['database', 'username']} label="数据库用户名" rules={[{ required: true }]}><Input autoComplete="off" /></Form.Item></Col>
                </Row>
                <Form.Item name={['database', 'password']} label="数据库密码"><Input.Password autoComplete="new-password" /></Form.Item>
                <Form.Item name="force" valuePropName="checked" style={{ marginBottom: 12 }}>
                  <Checkbox>强制初始化（清空已有平台数据）</Checkbox>
                </Form.Item>
                {force && <Alert type="error" showIcon message="将删除目标数据库 public 下全部 app_ 业务表及数据，再创建新的表和超级管理员。请确认已备份需要保留的数据。" style={{ marginBottom: 24 }} />}
                <Typography.Title level={5}>Redis 缓存</Typography.Title>
                <Row gutter={16}>
                  <Col xs={24} sm={12}><Form.Item name={['redis', 'host']} label="Redis 主机" rules={[{ required: true }]}><Input autoComplete="off" /></Form.Item></Col>
                  <Col xs={12} sm={6}><Form.Item name={['redis', 'port']} label="Redis 端口" rules={[{ required: true }]}><InputNumber min={1} max={65535} style={{ width: '100%' }} /></Form.Item></Col>
                  <Col xs={12} sm={6}><Form.Item name={['redis', 'database']} label="Redis 数据库" rules={[{ required: true }]}><InputNumber min={0} max={65535} style={{ width: '100%' }} /></Form.Item></Col>
                </Row>
                <Form.Item name={['redis', 'password']} label="Redis 密码（可留空）"><Input.Password autoComplete="new-password" /></Form.Item>
              </div>
              <div hidden={step !== 2}>
                <Form.Item name={['admin', 'email']} label="管理员邮箱" rules={[{ required: true }, { type: 'email', message: '请输入有效邮箱' }]}><Input autoComplete="username" /></Form.Item>
                <Form.Item name={['admin', 'username']} label="管理员昵称" rules={[{ required: true }, { max: 64 }]}><Input /></Form.Item>
                <Form.Item name={['admin', 'password']} label="管理员密码" rules={[{ required: true }, { min: 12, max: 72, message: '请输入 12 到 72 字节的密码' }, { pattern: /^(?=.*[a-zA-Z])(?=.*[0-9]).+$/, message: '密码须包含字母和数字' }]}><Input.Password autoComplete="new-password" /></Form.Item>
                <Form.Item name={['admin', 'password_confirmation']} label="确认管理员密码" dependencies={[["admin", "password"]]} rules={[{ required: true }, ({ getFieldValue }) => ({ validator(_, value) { return !value || value === getFieldValue(['admin', 'password']) ? Promise.resolve() : Promise.reject(new Error('两次输入的密码不一致')); } })]}><Input.Password autoComplete="new-password" /></Form.Item>
                <Alert type="info" showIcon message="完成后将自动关闭初始化入口，并使用此账号管理平台。" />
                {force && <Alert type="error" showIcon message="已选择强制初始化，提交时会重置目标数据库中的平台数据。" style={{ marginTop: 12 }} />}
              </div>
            </Form>
            <Space style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 24 }}>
              {step > 0 && <Button disabled={submitting} onClick={() => { setStep(step - 1); setError(''); }}>上一步</Button>}
              {step < 2 ? <Button type="primary" disabled={!status.ready} onClick={next}>下一步</Button> : <Button type="primary" loading={submitting} onClick={install}>开始初始化</Button>}
            </Space>
          </>
        )}
      </Card>
      <Modal open={confirmOpen} title="确认强制初始化" okText="确认重置并初始化" cancelText="取消" confirmLoading={submitting}
        okButtonProps={{ danger: true, disabled: confirmation !== '重置数据库' }} cancelButtonProps={{ disabled: submitting }}
        closable={!submitting} maskClosable={false} keyboard={!submitting}
        onCancel={() => { setConfirmOpen(false); setConfirmation(''); }} onOk={submitInstallation}>
        <Typography.Paragraph>将清空数据库 <Typography.Text strong>{form.getFieldValue(['database', 'database'])}</Typography.Text> 中 public 下全部 app_ 业务表及数据，并写入新的超级管理员。</Typography.Paragraph>
        <Typography.Paragraph>请输入“重置数据库”确认此操作。</Typography.Paragraph>
        <Input aria-label="强制初始化确认" value={confirmation} onChange={event => setConfirmation(event.target.value)} disabled={submitting} autoComplete="off" placeholder="重置数据库" />
      </Modal>
    </div>
  );
}
