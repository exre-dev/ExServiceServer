import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button, Card, Form, Input, Typography } from 'antd';
import http from '../api';
import { useAuth, type CurrentUser } from '../store';

interface LoginForm {
  email: string;
  password: string;
  captcha: string;
}

export default function Login() {
  const navigate = useNavigate();
  const setAuth = useAuth((s) => s.setAuth);
  const [captcha, setCaptcha] = useState<{ challengeId: string; imageBase64: string } | null>(null);
  const [captchaLoading, setCaptchaLoading] = useState(false);
  const [form] = Form.useForm<LoginForm>();

  const refreshCaptcha = useCallback(async () => {
    setCaptchaLoading(true);
    try {
      // 挑战端点挂 /api/auth/captcha/challenges（用户侧契约路径, 管理端共用）
      const data = await http.post('/auth/captcha/challenges');
      setCaptcha({ challengeId: data.challengeId, imageBase64: data.imageBase64 });
      form.setFieldValue('captcha', '');
    } catch {
      setCaptcha(null);
    } finally {
      setCaptchaLoading(false);
    }
  }, [form]);

  useEffect(() => {
    refreshCaptcha();
  }, [refreshCaptcha]);

  const onFinish = async (values: LoginForm) => {
    try {
      const data = await http.post('/account/login', {
        email: values.email,
        password: values.password,
        captcha: {
          challengeId: captcha?.challengeId,
          answer: values.captcha,
        },
      });
      setAuth(data.token as string, data.user as CurrentUser);
      navigate('/');
    } catch {
      // 失败后挑战已一次性焚毁, 换新图重输
      refreshCaptcha();
    }
  };

  return (
    <div
      style={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        background: '#f0f2f5',
      }}
    >
      <Card style={{ width: 380 }}>
        <Typography.Title level={3} style={{ textAlign: 'center', marginBottom: 24 }}>
          EXRE 控制台
        </Typography.Title>
        <Form<LoginForm> form={form} layout="vertical" onFinish={onFinish}>
          <Form.Item
            name="email"
            label="邮箱"
            rules={[{ required: true, message: '请输入邮箱' }]}
          >
            <Input placeholder="admin@exre.local" autoComplete="username" />
          </Form.Item>
          <Form.Item
            name="password"
            label="密码"
            rules={[{ required: true, message: '请输入密码' }]}
          >
            <Input.Password placeholder="密码" autoComplete="current-password" />
          </Form.Item>
          <Form.Item
            name="captcha"
            label="验证码"
            rules={[{ required: true, message: '请输入图片验证码' }]}
          >
            <Input
              placeholder="不区分大小写"
              autoComplete="off"
              maxLength={16}
              suffix={
                captcha ? (
                  <img
                    src={`data:image/png;base64,${captcha.imageBase64}`}
                    alt="验证码"
                    title="看不清？点击换一张"
                    onClick={refreshCaptcha}
                    style={{
                      height: 32,
                      borderRadius: 4,
                      cursor: captchaLoading ? 'default' : 'pointer',
                      opacity: captchaLoading ? 0.6 : 1,
                    }}
                  />
                ) : (
                  <a onClick={refreshCaptcha} style={{ fontSize: 12 }}>
                    点击获取
                  </a>
                )
              }
            />
          </Form.Item>
          <Button type="primary" htmlType="submit" block>
            登录
          </Button>
        </Form>
      </Card>
    </div>
  );
}
