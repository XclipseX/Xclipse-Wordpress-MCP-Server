/**
 * Xclipse Remote MCP Server (Streamable HTTP Transport)
 * Compatible with Gemini Spark, Claude Remote MCP, Cursor, MCP Inspector, Vercel, Render, Docker.
 */
import http from 'node:http';
import { createHash, timingSafeEqual } from 'node:crypto';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import {
  VERSION,
  ALL_GROUPS,
  TOOL_CATALOG,
  getEnvConfig,
  getSitesConfig,
  requestStore,
  createMcpServer,
} from './core.js';

const MAX_BODY_BYTES = 10 * 1024 * 1024; // 10MB (supports base64 media uploads)
const startedAt = Date.now();

function setCors(res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
  res.setHeader(
    'Access-Control-Allow-Headers',
    'Content-Type, Accept, Authorization, Mcp-Session-Id, Mcp-Protocol-Version, X-MCP-Auth, X-WP-Site, X-Xclipse-Site, X-WP-URL, X-WP-Key, X-WP-Tools, X-Xclipse-URL, X-Xclipse-Key, X-Xclipse-Tools, X-Tool-Mode'
  );
  res.setHeader('Access-Control-Expose-Headers', 'Mcp-Session-Id');
}

function sendJson(res, status, data) {
  setCors(res);
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(data));
}

function readJsonBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on('data', (chunk) => {
      size += chunk.length;
      if (size > MAX_BODY_BYTES) {
        reject(Object.assign(new Error('Payload too large'), { status: 413 }));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => {
      const raw = Buffer.concat(chunks).toString('utf8').trim();
      if (!raw) return resolve(undefined);
      try {
        resolve(JSON.parse(raw));
      } catch {
        reject(Object.assign(new Error('Invalid JSON body'), { status: 400 }));
      }
    });
    req.on('error', reject);
  });
}

function checkAuth(req, urlObj) {
  const expected = process.env.MCP_AUTH_TOKEN || '';
  // Fail closed: the HTTP transport never runs unauthenticated.
  if (!expected) return false;
  const auth = req.headers['authorization'] || '';
  const bearer = auth.toLowerCase().startsWith('bearer ') ? auth.slice(7).trim() : '';
  const headerToken = req.headers['x-mcp-auth'] || '';
  // Legacy fallback for clients that cannot send headers; prefer Authorization: Bearer.
  const queryToken = urlObj.searchParams.get('token') || '';
  const provided = String(bearer || headerToken || queryToken);
  if (!provided) return false;
  const digest = (str) => createHash('sha256').update(str).digest();
  return timingSafeEqual(digest(provided), digest(expected));
}

function resolveRequestContext(req, urlObj) {
  const env = getEnvConfig();
  const sites = getSitesConfig();
  const hdrs = req.headers;
  const query = urlObj.searchParams;

  const siteAlias = String(
    hdrs['x-wp-site'] || hdrs['x-xclipse-site'] || query.get('site') || ''
  ).trim();

  let siteCfg = null;
  if (siteAlias) {
    siteCfg = sites[siteAlias];
    if (!siteCfg || typeof siteCfg !== 'object') {
      const available = Object.keys(sites);
      const err = new Error(
        `Unknown site alias "${siteAlias}".` +
        (available.length ? ` Available sites: ${available.join(', ')}` : ' XCLIPSE_SITES is not configured.')
      );
      err.status = 400;
      throw err;
    }
  }

  // URL and key always come from the SAME source, so a caller-supplied URL can never
  // receive a key stored on the server (prevents key exfiltration / SSRF with stored creds).
  const headerUrl = String(hdrs['x-wp-url'] || hdrs['x-xclipse-url'] || '');
  const headerKey = String(hdrs['x-wp-key'] || hdrs['x-xclipse-key'] || '');
  let base;
  let key;
  if (headerUrl) {
    if (!headerKey) {
      const err = new Error('X-WP-URL requires X-WP-Key; stored keys are never sent to caller-supplied URLs.');
      err.status = 400;
      throw err;
    }
    if (!/^https:\/\//i.test(headerUrl)) {
      const err = new Error('X-WP-URL must use https://');
      err.status = 400;
      throw err;
    }
    base = headerUrl;
    key = headerKey;
  } else if (siteCfg) {
    base = String(siteCfg.url || '');
    key = String(siteCfg.key || '');
  } else {
    base = String(env.base || '');
    key = String(headerKey || env.key || '');
    if (headerKey && !env.base) key = '';
  }
  base = base.replace(/\/$/, '');

  const toolsRaw =
    hdrs['x-wp-tools'] || hdrs['x-xclipse-tools'] || query.get('tools') ||
    (Array.isArray(siteCfg?.tools) ? siteCfg.tools.join(',') : siteCfg?.tools || '');
  const tools = toolsRaw
    ? String(toolsRaw).split(',').map((text) => text.trim().toLowerCase()).filter(Boolean)
    : env.tools;

  const mode = String(
    hdrs['x-tool-mode'] || query.get('mode') || siteCfg?.mode || env.mode || 'full'
  ).trim().toLowerCase();

  return {
    ...env,
    base,
    key,
    tools,
    mode,
    site: siteAlias || undefined,
  };
}

export async function handleHttpRequest(req, res, preParsedBody = undefined) {
  setCors(res);

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  const urlObj = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);
  const pathname = urlObj.pathname.replace(/\/+$/, '') || '/';

  if (req.method === 'GET' && (pathname === '/' || pathname === '/health' || pathname === '/api/mcp' && urlObj.searchParams.has('health'))) {
    const env = getEnvConfig();
    const siteAliases = Object.keys(getSitesConfig());
    return sendJson(res, 200, {
      ok: true,
      name: 'xclipse-mcp',
      version: VERSION,
      transport: 'streamable-http',
      mode: env.mode,
      catalog_tools: TOOL_CATALOG.length,
      groups: ALL_GROUPS,
      configured_wp: Boolean(env.base && env.key) || siteAliases.length > 0,
      auth_required: true,
      auth_configured: Boolean(process.env.MCP_AUTH_TOKEN),
      endpoints: { mcp: '/mcp', health: '/health' },
      uptime_s: Math.floor((Date.now() - startedAt) / 1000),
    });
  }

  if (pathname !== '/mcp' && pathname !== '/api/mcp') {
    return sendJson(res, 404, { error: 'Not Found', endpoints: ['/mcp', '/health'] });
  }

  if (!checkAuth(req, urlObj)) {
    return sendJson(res, 401, {
      jsonrpc: '2.0',
      error: { code: -32001, message: process.env.MCP_AUTH_TOKEN ? 'Unauthorized: invalid or missing MCP_AUTH_TOKEN' : 'Server misconfigured: set MCP_AUTH_TOKEN to enable the HTTP transport' },
      id: null,
    });
  }

  if (req.method === 'GET' || req.method === 'DELETE') {
    return sendJson(res, 405, {
      jsonrpc: '2.0',
      error: { code: -32000, message: 'Method not allowed in stateless Streamable HTTP mode. Use POST /mcp.' },
      id: null,
    });
  }

  if (req.method !== 'POST') {
    return sendJson(res, 405, { error: 'Method Not Allowed' });
  }

  let body = preParsedBody;
  if (body === undefined) {
    try {
      body = await readJsonBody(req);
    } catch (eItem) {
      return sendJson(res, eItem.status || 400, {
        jsonrpc: '2.0',
        error: { code: -32700, message: eItem.message || 'Invalid JSON' },
        id: null,
      });
    }
  }

  let ctx;
  try {
    ctx = resolveRequestContext(req, urlObj);
  } catch (eItem) {
    return sendJson(res, eItem.status || 400, {
      jsonrpc: '2.0',
      error: { code: -32602, message: eItem.message || 'Invalid site configuration' },
      id: null,
    });
  }
  const { server } = createMcpServer({ mode: ctx.mode, tools: ctx.tools });
  const transport = new StreamableHTTPServerTransport({
    sessionIdGenerator: undefined,
  });

  res.on('close', () => {
    try { transport.close(); } catch {}
    try { server.close(); } catch {}
  });

  try {
    await requestStore.run(ctx, async () => {
      await server.connect(transport);
      await transport.handleRequest(req, res, body);
    });
  } catch (eItem) {
    if (!res.headersSent) {
      sendJson(res, 500, {
        jsonrpc: '2.0',
        error: { code: -32603, message: eItem?.message || 'Internal server error' },
        id: null,
      });
    }
  }
}

export function startHttpServer({ port = parseInt(process.env.PORT, 10) || 3000, host = process.env.HOST || '0.0.0.0' } = {}) {
  const httpServer = http.createServer((req, res) => handleHttpRequest(req, res));

  return new Promise((resolve) => {
    httpServer.listen(port, host, () => {
      const addr = httpServer.address();
      const actualPort = typeof addr === 'object' && addr ? addr.port : port;
      const env = getEnvConfig();
      console.error(
        `[XCLIPSE v${VERSION}] HTTP MCP listening on http://${host}:${actualPort}/mcp | mode=${env.mode} | catalog=${TOOL_CATALOG.length} tools`
      );
      resolve({ httpServer, port: actualPort });
    });
  });
}
