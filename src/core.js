/**
 * Xclipse WordPress MCP Core Lite — API client, TTL cache, response compaction,
 * error classification & MCP server factory.
 */
import { AsyncLocalStorage } from 'node:async_hooks';
import { createHash } from 'node:crypto';
import { readFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z as zod } from 'zod';

const __dir = dirname(fileURLToPath(import.meta.url));

export let VERSION = '1.0.0';
try {
  VERSION = JSON.parse(readFileSync(resolve(__dir, '..', 'package.json'), 'utf8')).version || VERSION;
} catch {}

const ENV_ALLOWLIST = /^(XCLIPSE_[A-Z0-9_]+|MCP_AUTH_TOKEN|MCP_TRANSPORT|MCP_NO_AUTOSTART|PORT|HOST)$/;
const envPath = resolve(__dir, '..', '.env');
if (existsSync(envPath)) {
  for (const raw of readFileSync(envPath, 'utf8').replace(/^\uFEFF/, '').split(/\r?\n/)) {
    let line = raw.replace(/\r/g, '').trim();
    if (!line || line.startsWith('#')) continue;
    line = line.replace(/^export\s+/, '');
    const eq = line.indexOf('=');
    if (eq < 1) continue;
    const name = line.slice(0, eq).trim();
    if (!ENV_ALLOWLIST.test(name)) continue;
    let value = line.slice(eq + 1).trim();
    if (value.length >= 2 && ((value[0] === '"' && value.endsWith('"')) || (value[0] === "'" && value.endsWith("'")))) {
      value = value.slice(1, -1);
    }
    if (!(name in process.env)) process.env[name] = value;
  }
}

export const requestStore = new AsyncLocalStorage();

export function getEnvConfig() {
  return {
    base: (process.env.XCLIPSE_URL || '').replace(/\/$/, ''),
    key: process.env.XCLIPSE_API_KEY || '',
    timeoutMs: Math.max(1000, parseInt(process.env.XCLIPSE_TIMEOUT_MS, 10) || 30000),
    maxOut: Math.max(200, parseInt(process.env.XCLIPSE_MAX_OUT, 10) || 12000),
    compact: process.env.XCLIPSE_COMPACT !== '0',
    tools: [],
    mode: 'full',
  };
}

export function getSitesConfig() {
  const raw = (process.env.XCLIPSE_SITES || '').trim();
  if (!raw) return {};
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
  } catch {
    return {};
  }
}

function getCtx() {
  return requestStore.getStore() || getEnvConfig();
}

const RETRY_DELAYS = [500, 1500];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ── In-memory TTL Cache for safe read-only GET endpoints ─────────────────────
const CACHE_TTL_MS = Math.max(0, parseInt(process.env.XCLIPSE_CACHE_TTL_MS, 10) || 15000);
const CACHEABLE_PATHS = ['/categories', '/tags'];
const cache = new Map();

function isCacheable(method, path) {
  if (CACHE_TTL_MS <= 0 || method !== 'GET') return false;
  const cleanPath = path.split('?')[0];
  return CACHEABLE_PATHS.includes(cleanPath);
}

function cacheKey(ctx, path) {
  const keyHash = createHash('sha256').update(String(ctx.key || '')).digest('hex').slice(0, 16);
  return `${ctx.base}:${keyHash}:${path}`;
}

function invalidateCache(base) {
  const prefix = `${base}:`;
  for (const k of cache.keys()) {
    if (k.startsWith(prefix)) cache.delete(k);
  }
}

// ── Error classification & network translation ───────────────────────────────
function netError(error, base, timeoutMs) {
  const code = error?.cause?.code || error?.code || '';
  const raw = error?.cause?.message || error?.message || String(error);
  const hint = `Check XCLIPSE_URL (${base}), ensure the WordPress site is reachable and not blocked by a firewall.`;
  let msg;
  if (error?.name === 'TimeoutError' || error?.name === 'AbortError' || code === 'ETIMEDOUT' || code === 'UND_ERR_CONNECT_TIMEOUT') {
    msg = `Request timed out (${timeoutMs}ms) calling ${base}.`;
  } else if (code === 'ECONNREFUSED') {
    msg = `Connection refused (ECONNREFUSED) to ${base}.`;
  } else if (code === 'ENOTFOUND' || code === 'EAI_AGAIN') {
    msg = `Could not resolve hostname for ${base} (${code}).`;
  } else if (code === 'ECONNRESET' || code === 'UND_ERR_SOCKET') {
    msg = `Connection reset (${code}).`;
  } else if (/CERT|SSL|TLS|SELF_SIGNED|UNABLE_TO_VERIFY|DEPTH_ZERO/i.test(code + ' ' + raw)) {
    msg = `SSL/TLS certificate error (${code || raw}).`;
  } else {
    msg = `Network error calling ${base}: ${raw}.`;
  }
  const err = new Error(msg);
  err.net = true;
  err.code = code || 'NETWORK_ERROR';
  err.hint = hint;
  return err;
}

function classifyHttpError(status, message) {
  const err = new Error(message || `HTTP ${status}`);
  err.status = status;
  if (status === 401 || status === 403) {
    err.code = 'AUTH_ERROR';
    err.hint = 'Check XCLIPSE_API_KEY and verify the Xclipse MCP Lite plugin is active on WordPress.';
  } else if (status === 404) {
    err.code = 'NOT_FOUND';
    err.hint = 'Resource or endpoint not found. Verify the ID and WordPress permalinks / REST API.';
  } else if (status === 400 || status === 422) {
    err.code = 'VALIDATION_ERROR';
    err.hint = 'Invalid parameters. Check required fields and data types.';
  } else if (status === 429) {
    err.code = 'RATE_LIMIT';
    err.hint = 'Rate limited by the WordPress site. Wait a few seconds and retry.';
  } else if (status >= 500) {
    err.code = 'SERVER_ERROR';
    err.hint = 'WordPress server error (5xx). Check PHP error logs.';
  } else {
    err.code = `HTTP_${status}`;
  }
  return err;
}

const cleanSnippet = (text) =>
  String(text)
    .replace(/<(script|style)[\s\S]*?<\/\1>/gi, ' ')
    .replace(/<[^>]*>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 200);

async function apiOnce(ctx, path, method, body) {
  const { base, key, timeoutMs } = ctx;
  if (!base || !key) {
    const err = new Error('Missing XCLIPSE_URL or XCLIPSE_API_KEY');
    err.code = 'MISSING_CONFIG';
    err.hint = 'Set XCLIPSE_URL & XCLIPSE_API_KEY via environment variables or .env.';
    throw err;
  }
  let res, text;
  try {
    res = await fetch(`${base}/wp-json/xclipse-mcp/v1${path}`, {
      method,
      headers: { 'X-MCP-Key': key, 'Content-Type': 'application/json' },
      signal: AbortSignal.timeout(timeoutMs),
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    text = await res.text();
  } catch (error) {
    throw netError(error, base, timeoutMs);
  }

  let json;
  let parsed = true;
  if (text === '') json = {};
  else {
    try { json = JSON.parse(text); } catch { parsed = false; }
  }

  if (!parsed) {
    throw classifyHttpError(res.status, `HTTP ${res.status}: ${cleanSnippet(text)}`);
  }
  if (!res.ok) {
    const msg = (json && typeof json === 'object' && json.message) || `HTTP ${res.status}`;
    const err = classifyHttpError(res.status, msg);
    if (res.status === 429) {
      const ra = parseInt(res.headers.get('retry-after'), 10);
      if (ra > 0) err.retryAfterMs = Math.min(ra * 1000, 5000);
    }
    throw err;
  }
  return json;
}

export async function api(path, method = 'GET', body = null) {
  const ctx = getCtx();
  method = String(method).toUpperCase();

  if (method !== 'GET') {
    invalidateCache(ctx.base);
  } else if (isCacheable(method, path)) {
    const hit = cache.get(cacheKey(ctx, path));
    if (hit && hit.exp > Date.now()) return hit.data;
  }

  const canRetry = method === 'GET';
  for (let attempt = 0; ; attempt++) {
    try {
      const data = await apiOnce(ctx, path, method, body);
      if (isCacheable(method, path)) {
        cache.set(cacheKey(ctx, path), { exp: Date.now() + CACHE_TTL_MS, data });
      }
      return data;
    } catch (e) {
      const retryable =
        canRetry &&
        attempt < RETRY_DELAYS.length &&
        (e.net || e.status === 429 || e.status === 502 || e.status === 503 || e.status === 504);
      if (!retryable) throw e;
      await sleep(e.retryAfterMs || RETRY_DELAYS[attempt]);
    }
  }
}

// ── Token-saving Response Compaction ─────────────────────────────────────────
export function compactValue(val, depth = 0) {
  if (depth > 10 || val === null || val === undefined || val === '') return undefined;
  if (Array.isArray(val)) {
    if (val.length === 0) return undefined;
    const arr = val.map((v) => compactValue(v, depth + 1)).filter((v) => v !== undefined);
    return arr.length ? arr : undefined;
  }
  if (typeof val === 'object') {
    const out = {};
    let hasKeys = false;
    for (const [k, v] of Object.entries(val)) {
      const cv = compactValue(v, depth + 1);
      if (cv !== undefined) {
        out[k] = cv;
        hasKeys = true;
      }
    }
    return hasKeys ? out : undefined;
  }
  return val;
}

export const toJson = (data) => {
  const ctx = getCtx();
  const payload = ctx.compact !== false ? (compactValue(data) ?? {}) : data;
  return { content: [{ type: 'text', text: JSON.stringify(payload) }] };
};

export const ok = (msg, extra = {}) => {
  const ctx = getCtx();
  const payload = ctx.compact !== false ? (compactValue({ ok: true, msg, ...extra }) ?? { ok: true, msg }) : { ok: true, msg, ...extra };
  return { content: [{ type: 'text', text: JSON.stringify(payload) }] };
};

export const Pager = { per_page: zod.number().default(20), page: zod.number().default(1) };

export const qs = (params) =>
  new URLSearchParams(
    Object.fromEntries(
      Object.entries(params)
        .filter(([, v]) => v != null && v !== '')
        .map(([k, v]) => [k, String(v)])
    )
  );

export const ALL_GROUPS = ['core'];

const safeCut = (str, num) => {
  if (str.length <= num) return str;
  let end = num;
  const code = str.charCodeAt(end - 1);
  if (code >= 0xd800 && code <= 0xdbff) end--;
  return str.slice(0, end);
};

export function limitOutput(res, maxOut) {
  if (!res || !Array.isArray(res.content)) return res;
  const limit = maxOut || getCtx().maxOut || 12000;
  let total = 0;
  for (const item of res.content) {
    if (item && item.type === 'text' && typeof item.text === 'string') total += item.text.length;
  }
  if (total <= limit) return res;
  let left = limit;
  const out = [];
  for (const item of res.content) {
    if (item && item.type === 'text' && typeof item.text === 'string') {
      if (left <= 0) continue;
      const chunk = safeCut(item.text, left);
      left -= chunk.length;
      out.push({ ...item, text: chunk });
    } else {
      out.push(item);
    }
  }
  out.push({
    type: 'text',
    text: JSON.stringify({
      truncated: true,
      total_chars: total,
      hint: 'Use per_page/page/fields or *_get to fetch smaller results.',
    }),
  });
  return { ...res, content: out };
}

function wrapHandler(handler) {
  return async (...args) => {
    try {
      return limitOutput(await handler(...args));
    } catch (e) {
      const payload = { error: e?.message || String(e) };
      if (e?.code) payload.code = e.code;
      if (e?.status) payload.status = e.status;
      if (e?.hint) payload.hint = e.hint;
      return { isError: true, content: [{ type: 'text', text: JSON.stringify(payload) }] };
    }
  };
}

// ── Tool Catalog ─────────────────────────────────────────────────────────────
export const TOOL_CATALOG = [];
export const TOOL_MAP = new Map();

export function registerCatalogTool(name, description, schema, handler) {
  const entry = {
    name,
    group: 'core',
    description,
    schema: schema || {},
    handler,
    wrapped: wrapHandler(handler),
  };
  TOOL_CATALOG.push(entry);
  TOOL_MAP.set(name, entry);
  return entry;
}

export function createMcpServer() {
  const srv = new McpServer({ name: 'xclipse-wordpress-mcp-lite', version: VERSION });
  for (const t of TOOL_CATALOG) {
    srv.tool(t.name, t.description, t.schema, t.wrapped);
  }
  return {
    server: srv,
    stats: { registered: TOOL_CATALOG.length, total: TOOL_CATALOG.length, mode: 'full' },
  };
}
