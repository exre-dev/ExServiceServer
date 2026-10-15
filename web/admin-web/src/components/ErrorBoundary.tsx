import { Component, type ReactNode } from 'react';
import { Button, Card, Typography } from 'antd';

interface Props {
  children: ReactNode;
}

interface State {
  error: Error | null;
}

/**
 * 全局错误边界：渲染异常时显示错误信息而不是白屏
 */
export default class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: unknown) {
    // eslint-disable-next-line no-console
    console.error('[ErrorBoundary]', error, info);
  }

  render() {
    if (this.state.error) {
      return (
        <Card style={{ margin: 24, maxWidth: 720 }}>
          <Typography.Title level={4}>页面渲染出错</Typography.Title>
          <Typography.Paragraph type="danger" code>
            {this.state.error.message}
          </Typography.Paragraph>
          <Button type="primary" onClick={() => location.reload()}>
            刷新重试
          </Button>
        </Card>
      );
    }
    return this.props.children;
  }
}
