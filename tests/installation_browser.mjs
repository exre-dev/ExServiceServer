/** 管理端初始化浏览器回归；接口边界由 mock 校验，数据库回归见 installation.php。 */
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const root = resolve(import.meta.dirname, '..');
const adminRoot = resolve(root, 'web/admin-web');
const requireAdmin = createRequire(resolve(adminRoot, 'package.json'));
const requireUser = createRequire(resolve(root, 'web/user-web/package.json'));
const { createServer } = await import(pathToFileURL(requireAdmin.resolve('vite')).href);
const { chromium } = requireUser('playwright');
const server = await createServer({ root: adminRoot, server: { host: '127.0.0.1', port: 0, open: false }, logLevel: 'error' });
let browser, page;
const errors = [];
try {
  await server.listen();
  const origin = `http://127.0.0.1:${server.httpServer.address().port}`;
  browser = await chromium.launch({ executablePath: process.env.CHROME_BINARY ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  page = await browser.newPage({ viewport: { width: 1280, height: 960 } });
  page.on('pageerror', error => errors.push(error.message));
  let ready = false, installed = false, submissions = 0, expectForce = false;
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname;
    let status = 200, data;
    if (path === '/api/installation/status') {
      data = { installed, ready, checks: [{ name: 'PostgreSQL 扩展', passed: true }, { name: '项目目录可写', passed: ready }] };
    } else if (path === '/api/installation/install') {
      const request = route.request();
      const form = request.postDataJSON();
      assert.equal(request.headers()['x-setup-request'], '1');
      assert.equal(form.database.database, 'deployment_test');
      assert.equal(form.database.password, 'database-test-secret');
      assert.equal(form.redis.database, 0);
      assert.equal(form.redis.password, '');
      assert.equal(form.admin.email, 'owner@example.com');
      assert.equal(form.admin.password, form.admin.password_confirmation);
      assert.equal(form.force, expectForce);
      if (expectForce) assert.equal(form.force_confirmation, '重置数据库');
      else assert.equal(form.force_confirmation, undefined);
      submissions++;
      if (submissions === 1 && !expectForce) {
        status = 400;
        data = { msg: 'PostgreSQL 连接失败，请检查连接信息' };
      } else {
        installed = true;
        data = { installed: true };
      }
    } else if (path === '/api/auth/captcha/challenges') {
      data = { challengeId: 'test-challenge', imageBase64: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aVocAAAAASUVORK5CYII=' };
    } else {
      throw new Error(`Unexpected pre-initialization API: ${path}`);
    }
    await route.fulfill({ status, json: status === 200 ? { msg: 'ok', data } : data });
  });
  await page.goto(`${origin}/users`);
  await page.waitForURL('**/install');
  await page.waitForLoadState('networkidle');
  assert.equal(await page.getByRole('button', { name: '下一步', exact: true }).isDisabled(), true);
  ready = true;
  await page.getByRole('button', { name: '重新检查环境', exact: true }).click();
  await page.getByRole('button', { name: '下一步', exact: true }).click();
  await page.getByLabel('数据库名称', { exact: true }).fill('deployment_test');
  await page.getByLabel('数据库密码', { exact: true }).fill('database-test-secret');
  assert.equal(await page.getByRole('checkbox', { name: '强制初始化（清空已有平台数据）', exact: true }).isChecked(), false);
  await page.getByRole('button', { name: '下一步', exact: true }).click();
  await page.getByLabel('管理员邮箱', { exact: true }).fill('owner@example.com');
  await page.getByLabel('管理员密码', { exact: true }).fill('Admin-test-123456');
  await page.getByLabel('确认管理员密码', { exact: true }).fill('different');
  await page.getByRole('button', { name: '开始初始化', exact: true }).click();
  await page.getByText('两次输入的密码不一致', { exact: true }).waitFor();
  assert.equal(submissions, 0);
  await page.getByLabel('确认管理员密码', { exact: true }).fill('Admin-test-123456');
  await page.getByRole('button', { name: '开始初始化', exact: true }).click();
  await page.getByText('PostgreSQL 连接失败，请检查连接信息', { exact: true }).waitFor();
  assert.equal(await page.getByLabel('管理员邮箱', { exact: true }).inputValue(), 'owner@example.com');
  mkdirSync(resolve(root, 'tests/tmp'), { recursive: true });
  await page.screenshot({ path: resolve(root, 'tests/tmp/installation-desktop.png'), fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({ path: resolve(root, 'tests/tmp/installation-mobile.png'), fullPage: true });
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Wizard must fit a mobile viewport');
  await page.getByRole('button', { name: '开始初始化', exact: true }).click();
  await page.getByText('初始化完成', { exact: true }).waitFor();
  await page.getByRole('button', { name: '前往登录', exact: true }).click();
  await page.waitForURL('**/login');
  await page.getByLabel('邮箱', { exact: true }).waitFor();
  await page.goto(`${origin}/install`);
  await page.waitForURL('**/login');
  assert.equal(submissions, 2);

  // 强制初始化须单独选择，并在最终提交前输入确认短语；取消不会请求后端。
  installed = false;
  expectForce = true;
  await page.goto(`${origin}/install`);
  await page.waitForLoadState('networkidle');
  await page.getByRole('button', { name: '下一步', exact: true }).click();
  await page.getByLabel('数据库名称', { exact: true }).fill('deployment_test');
  await page.getByLabel('数据库密码', { exact: true }).fill('database-test-secret');
  await page.getByRole('checkbox', { name: '强制初始化（清空已有平台数据）', exact: true }).check();
  await page.getByText('将删除目标数据库 public 下全部 app_ 业务表及数据，再创建新的表和超级管理员。请确认已备份需要保留的数据。', { exact: true }).waitFor();
  await page.getByRole('button', { name: '下一步', exact: true }).click();
  await page.getByLabel('管理员邮箱', { exact: true }).fill('owner@example.com');
  await page.getByLabel('管理员密码', { exact: true }).fill('Admin-test-123456');
  await page.getByLabel('确认管理员密码', { exact: true }).fill('Admin-test-123456');
  await page.getByRole('button', { name: '开始初始化', exact: true }).click();
  const confirmation = page.getByRole('dialog', { name: '确认强制初始化' });
  await confirmation.waitFor();
  await confirmation.getByLabel('强制初始化确认', { exact: true }).click();
  await page.screenshot({ path: resolve(root, 'tests/tmp/installation-force-confirmation.png'), fullPage: true, animations: 'disabled' });
  assert.equal(await confirmation.getByRole('button', { name: '确认重置并初始化', exact: true }).isDisabled(), true);
  await confirmation.getByRole('button', { name: /取\s*消/ }).click();
  assert.equal(submissions, 2);
  await page.getByRole('button', { name: '开始初始化', exact: true }).click();
  await confirmation.getByLabel('强制初始化确认', { exact: true }).fill('重置数据库');
  await confirmation.getByRole('button', { name: '确认重置并初始化', exact: true }).click();
  await page.getByText('初始化完成', { exact: true }).waitFor();
  assert.equal(submissions, 3);
  assert.deepEqual(errors, []);
  console.log('PASS: standard initialization, default data protection, force selection warning, confirmation/cancel, confirmed reset payload, desktop/mobile layout and completion');
} catch (error) {
  if (page) {
    console.error({ url: page.url(), pageErrors: errors, text: await page.locator('body').innerText() });
    await page.screenshot({ path: resolve(root, 'tests/tmp/installation-failure.png'), fullPage: true });
  }
  throw error;
} finally {
  await browser?.close();
  await server.close();
}
