/** Browser acceptance against the real PHP controllers, PostgreSQL and Redis.
 * Run from the repository root: node tests/sso_browser.mjs
 * Requires the installed user/admin frontend dependencies and local Chrome.
 */
import assert from 'node:assert/strict'
import { execFileSync, spawn } from 'node:child_process'
import { once } from 'node:events'
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { createServer as netServer } from 'node:net'
import { createRequire } from 'node:module'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'

const root = resolve(import.meta.dirname, '..')
const webRoot = resolve(root, 'web/user-web')
const adminRoot = resolve(root, 'web/admin-web')
const webRequire = createRequire(resolve(webRoot, 'package.json'))
const adminRequire = createRequire(resolve(adminRoot, 'package.json'))
const { chromium } = webRequire('playwright')
const php = process.env.PHP_BINARY ?? 'php'
const statePath = resolve(root, `tests/tmp/sso-browser-${Date.now()}.json`)
const router = resolve(root, 'tests/sso_flow.php')
const chrome = process.env.CHROME_BINARY ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe'
assert(existsSync(chrome), 'Set CHROME_BINARY to an installed Chrome/Chromium executable')

async function freePort() {
  const server = netServer()
  server.listen(0, '127.0.0.1')
  await once(server, 'listening')
  const port = server.address().port
  await new Promise(resolve => server.close(resolve))
  return port
}
const backendPort = await freePort(), webPort = await freePort(), adminPort = await freePort()
const backendOrigin = `http://127.0.0.1:${backendPort}`
const webOrigin = `http://127.0.0.1:${webPort}`
const adminOrigin = `http://127.0.0.1:${adminPort}`
let phpServer, web, admin, browser, state
let backendErrors = ''
const pageErrors = []

async function request(path, body, token) {
  const response = await fetch(`${backendOrigin}${path}`, {
    method: body === undefined ? 'GET' : 'POST',
    headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    body: body === undefined ? undefined : JSON.stringify(body), signal: AbortSignal.timeout(10_000),
  })
  const json = await response.json()
  return { status: response.status, data: json.data ?? json }
}
async function init() {
  const response = await request('/api/oauth/device/init', { client_id: state.client_id, client_secret: state.secret })
  assert.equal(response.status, 200)
  return response.data
}
async function poll(device) {
  return request('/api/oauth/device/token', { client_id: state.client_id, client_secret: state.secret, device_code: device.device_code })
}
async function userPage(token) {
  const context = await browser.newContext()
  await context.addInitScript(({ token }) => {
    if (token) window.localStorage.setItem('exwebsite.auth.token', token)
    window.close = () => {}
  }, { token })
  const page = await context.newPage()
  page.on('pageerror', error => pageErrors.push(error.message))
  return page
}

try {
  execFileSync(php, [router, '--prepare', statePath], { cwd: root, windowsHide: true, stdio: 'pipe' })
  state = JSON.parse(readFileSync(statePath, 'utf8'))
  state.web_origin = webOrigin
  writeFileSync(statePath, JSON.stringify(state))
  phpServer = spawn(php, ['-S', `127.0.0.1:${backendPort}`, router], {
    cwd: root, windowsHide: true, env: { ...process.env, SSO_TEST_STATE: statePath }, stdio: ['ignore', 'pipe', 'pipe'],
  })
  phpServer.stderr.on('data', data => { backendErrors = (backendErrors + data.toString()).slice(-4000) })
  phpServer.stdout.resume()
  for (let attempt = 0; ; attempt++) {
    try { await request('/api/admin/sso-requests'); break } catch (error) {
      if (attempt >= 30) throw new Error(`Test backend failed to start: ${backendErrors}`, { cause: error })
      await new Promise(resolve => setTimeout(resolve, 100))
    }
  }

  process.env.INTEGRATION_SERVER_PORT = String(backendPort)
  process.env.SERVER_PUBLIC_WEB_BASE_URL = webOrigin
  const { createServer } = await import(pathToFileURL(webRequire.resolve('vite')).href)
  web = await createServer({ root: resolve(webRoot, 'apps/web'),
    configFile: resolve(webRoot, 'apps/web/vite.config.ts'), server: { host: '127.0.0.1', port: webPort, strictPort: true }, logLevel: 'error' })
  await web.listen()
  const { createServer: createAdmin } = await import(pathToFileURL(adminRequire.resolve('vite')).href)
  admin = await createAdmin({ root: adminRoot, configFile: resolve(adminRoot, 'vite.config.ts'),
    server: { host: '127.0.0.1', port: adminPort, strictPort: true, proxy: { '/api': { target: backendOrigin } } }, logLevel: 'error' })
  await admin.listen()
  browser = await chromium.launch({ executablePath: chrome, headless: true })

  // Password + real image challenge -> success page -> plugin token -> scoped userinfo.
  const device = await init()
  assert.equal(device.verification_uri_web, `${webOrigin}/login?ssoRequestId=${device.sso_request_id}`)
  const passwordPage = await userPage()
  await passwordPage.goto(device.verification_uri_web)
  await passwordPage.getByLabel('邮箱', { exact: true }).fill('member@sso-test.invalid')
  await passwordPage.getByLabel('密码', { exact: true }).fill(state.password)
  const captchaResponse = passwordPage.waitForResponse(response => response.url().endsWith('/api/auth/captcha/challenges') && response.status() === 200)
  await passwordPage.getByRole('button', { name: '继续', exact: true }).click()
  const captcha = (await (await captchaResponse).json()).data
  const answer = (await request(`/__test/captcha/${captcha.challengeId}`)).data.answer
  assert.equal(typeof answer, 'string')
  await passwordPage.getByLabel('图片验证码', { exact: true }).fill(answer)
  await passwordPage.getByRole('button', { name: '登录', exact: true }).click()
  await passwordPage.waitForURL(`${webOrigin}/sso/success`)
  await passwordPage.getByRole('heading', { name: '登录成功', exact: true }).waitFor()
  assert.equal(await passwordPage.evaluate(() => localStorage.getItem('exwebsite.auth.token')), null)
  const token = await poll(device)
  assert.equal(token.status, 200)
  const profile = await request('/api/open/userinfo', undefined, token.data.access_token)
  assert.equal(profile.data.id, state.member_id)
  assert.equal((await poll(device)).status, 400)
  console.log('PASS: browser password + captcha SSO, success page and plugin token/userinfo')

  // Existing website session confirms automatically; expired bearer falls back to credentials.
  const existing = await init()
  const existingPage = await userPage(state.member_token)
  await existingPage.goto(existing.verification_uri_web)
  await existingPage.waitForURL(`${webOrigin}/sso/success`)
  await existingPage.getByRole('heading', { name: '登录成功', exact: true }).waitFor()
  assert.equal((await poll(existing)).status, 200)
  const stale = await init()
  const stalePage = await userPage('expired-token')
  await stalePage.goto(stale.verification_uri_web)
  await stalePage.getByLabel('邮箱', { exact: true }).waitFor({ state: 'visible' })
  await stalePage.waitForFunction(() => localStorage.getItem('exwebsite.auth.token') === null)
  await stalePage.getByLabel('邮箱', { exact: true }).fill('member@sso-test.invalid')
  assert.equal(await stalePage.getByLabel('邮箱', { exact: true }).isEnabled(), true)
  console.log('PASS: browser existing-session confirmation and expired-token password fallback')

  // Actual admin table and Popconfirm invoke the real revoke endpoint.
  const adminContext = await browser.newContext()
  await adminContext.addInitScript(({ token, user }) => {
    localStorage.setItem('exre_token', token)
    localStorage.setItem('exre_user', JSON.stringify(user))
  }, { token: state.admin_token, user: state.admin_user })
  const adminPage = await adminContext.newPage()
  adminPage.on('pageerror', error => pageErrors.push(error.message))
  await adminPage.goto(`${adminOrigin}/sso-requests`)
  const row = adminPage.getByRole('row').filter({ hasText: stale.user_code })
  await row.getByText('待确认', { exact: true }).waitFor()
  await row.getByRole('button', { name: /撤\s*销/ }).click()
  const revokeResponse = adminPage.waitForResponse(response => response.url().endsWith(`/${stale.device_code}/revoke`))
  await adminPage.getByRole('button', { name: /确\s*定/ }).click()
  assert.equal((await revokeResponse).status(), 200)
  await row.waitFor({ state: 'hidden' })
  assert.equal((await poll(stale)).status, 400)
  await stalePage.reload()
  await stalePage.getByText('登录请求已失效或无法授权，请从发起应用重新登录', { exact: true }).waitFor()
  assert.equal(await stalePage.getByRole('button', { name: '重试', exact: true }).count(), 0)
  console.log('PASS: browser admin list/revoke, immediate plugin invalidation and terminal login message')
  assert.deepEqual(pageErrors, [], 'Unexpected browser exceptions')
} finally {
  await browser?.close()
  await admin?.close()
  await web?.close()
  if (phpServer && phpServer.exitCode === null) {
    const exited = once(phpServer, 'exit')
    phpServer.kill()
    await exited
  }
  if (existsSync(statePath)) {
    execFileSync(php, [router, '--cleanup', statePath], { cwd: root, windowsHide: true, stdio: 'pipe' })
  }
}
