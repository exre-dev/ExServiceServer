import axios from 'axios';
import { message } from 'antd';

/**
 * 统一请求封装：
 * - baseURL /api：后端所有接口统一挂在 /api 前缀下（vite 代理与生产网关只转发 /api）
 * - 自动携带 Authorization: Bearer <token>
 * - 响应体约定 {msg, data}: HTTP 200 时直接返回 data
 * - HTTP 401 或提示含"登录"/"token"：清除本地态并跳转登录页
 */
const http = axios.create({ baseURL: '/api', timeout: 15000 });

http.interceptors.request.use((cfg) => {
  const token = localStorage.getItem('exre_token');
  if (token) cfg.headers.Authorization = `Bearer ${token}`;
  return cfg;
});

http.interceptors.response.use(
  (resp) => {
    const body = resp.data;
    if (body && typeof body === 'object' && 'data' in body) {
      // 后端 success(msg, data) 语义消息按需提示
      if (body.msg && body.msg !== 'ok') message.success(body.msg);
      return body.data;
    }
    return body;
  },
  (err) => {
    const data = err.response?.data;
    // 兼容 fail() 的 msg 与框架兜底的 message 两种错误体
    const msg: string = data?.msg || data?.message || err.message || '请求失败';
    if (err.response?.status === 503 && err.response?.headers?.['x-setup-required'] === '1') {
      location.href = '/install';
      return Promise.reject(err);
    }
    if (err.response?.status === 401 && location.pathname !== '/login') {
      localStorage.removeItem('exre_token');
      localStorage.removeItem('exre_user');
      location.href = '/login';
    } else if (/登录|token/i.test(String(msg)) && location.pathname !== '/login') {
      // 兜底: 部分旧错误路径仍是 400 + 登录提示
      localStorage.removeItem('exre_token');
      localStorage.removeItem('exre_user');
      location.href = '/login';
    } else {
      message.error(msg);
    }
    return Promise.reject(err);
  }
);

export default http;
