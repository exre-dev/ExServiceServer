import { fileURLToPath } from 'node:url';
import { readContract } from '../scripts/openapi.mjs';

const audience = process.argv[2];
if (process.argv.length !== 3 || !['internal', 'public'].includes(audience)) {
  throw new Error('用法: node tests/website_http_contract.mjs internal|public');
}
const document = await readContract(
  fileURLToPath(new URL(`../api/${audience}/openapi.yaml`, import.meta.url))
);
process.stdout.write(JSON.stringify(document));
