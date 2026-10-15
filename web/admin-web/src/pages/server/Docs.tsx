import { useParams } from 'react-router-dom';
import DocManager from '../../components/DocManager';

/** 空间文档管理（三级可见性） */
export default function ServerDocs() {
  const { sid } = useParams();
  return <DocManager scope="server" sid={Number(sid)} />;
}
