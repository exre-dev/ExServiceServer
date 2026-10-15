import { useEffect, useState } from 'react';
import { Card, Col, Row, Statistic, Descriptions } from 'antd';
import { TeamOutlined, KeyOutlined, IdcardOutlined } from '@ant-design/icons';
import { useParams } from 'react-router-dom';
import http from '../../api';

export default function ServerOverview() {
  const { sid } = useParams();
  const [info, setInfo] = useState<any>(null);

  useEffect(() => {
    http.get(`/server/${sid}`).then(setInfo);
  }, [sid]);

  if (!info) return null;

  return (
    <div>
      <Row gutter={[16, 16]}>
        <Col xs={12} md={8}>
          <Card>
            <Statistic title="成员" value={info.member_count} prefix={<TeamOutlined />} />
          </Card>
        </Col>
        <Col xs={12} md={8}>
          <Card>
            <Statistic title="绑定码" value={info.binding_count} prefix={<KeyOutlined />} />
          </Card>
        </Col>
        <Col xs={12} md={8}>
          <Card>
            <Statistic title="游戏标识" value={info.ident_count} prefix={<IdcardOutlined />} />
          </Card>
        </Col>
      </Row>
      <Card title="空间信息" style={{ marginTop: 16 }}>
        <Descriptions size="small" column={2} bordered>
          <Descriptions.Item label="名称">{info.server.name}</Descriptions.Item>
          <Descriptions.Item label="Slug">{info.server.slug}</Descriptions.Item>
          <Descriptions.Item label="联系 QQ">{info.server.contact_qq || '-'}</Descriptions.Item>
          <Descriptions.Item label="状态">{info.server.status}</Descriptions.Item>
          <Descriptions.Item label="介绍" span={2}>
            {info.server.description || '-'}
          </Descriptions.Item>
        </Descriptions>
      </Card>
    </div>
  );
}
