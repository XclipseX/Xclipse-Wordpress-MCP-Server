#!/usr/bin/env node
/**
 * Xclipse WordPress MCP Server — Core Lite (18 tools)
 * - Dual transport: Stdio (default) & Streamable HTTP (--http or PORT)
 * - 18 Core tools: site_info, posts (5), pages (5), media (3), categories & tags (2), comments (2)
 * - Need 390+ tools (WooCommerce, Rank Math, Elementor, Flatsome, LiteSpeed Cache, ACF, OAuth 2.1)?
 *   https://www.agensi.io/r/XCLIPEWPMCPSRVR?to=/skills/xclipse-wordpress-mcp-390-tools-oauth-2-1
 */
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z as zod } from 'zod';
import {
  VERSION,
  getEnvConfig,
  api,
  toJson,
  ok,
  Pager,
  qs,
  registerCatalogTool,
  createMcpServer,
} from './core.js';
import { startHttpServer } from './http.js';

const server = { tool: registerCatalogTool };

// 1. SITE INFO (1 tool)
server.tool(
  'site_info',
  'Get WordPress site information: site name, URL, WP/PHP versions, active theme, and detected plugins.',
  {},
  async () => toJson(await api('/info'))
);

// 2. POSTS (5 tools)
server.tool(
  'posts_list',
  'List WordPress posts with pagination, search, status, category, and author filters.',
  {
    ...Pager,
    search:    zod.string().default(''),
    status:    zod.enum(['publish', 'draft', 'pending', 'private', 'any']).default('publish'),
    cat:       zod.number().default(0).describe('Category ID'),
    orderby:   zod.enum(['date', 'title', 'modified']).default('date'),
    order:     zod.enum(['ASC', 'DESC']).default('DESC'),
    author_id: zod.number().optional().describe('Filter by author ID'),
    fields:    zod.string().default('').describe('Comma-separated fields to return, e.g. id,title,url'),
  },
  async (args) => toJson(await api(`/posts?${qs(args)}`))
);

server.tool(
  'post_get',
  'Get full details of a single WordPress post by ID (content, SEO meta, categories, tags).',
  { id: zod.number().describe('Post ID') },
  async ({ id }) => toJson(await api(`/posts/${id}`))
);

server.tool(
  'post_create',
  'Create a new WordPress post. Returns {id, url, status}.',
  {
    title:      zod.string(),
    content:    zod.string().describe('HTML content'),
    status:     zod.enum(['draft', 'publish', 'pending']).default('draft'),
    excerpt:    zod.string().default(''),
    slug:       zod.string().default(''),
    categories: zod.array(zod.number()).default([]),
    tags:       zod.array(zod.string()).default([]),
    seo_title:  zod.string().default(''),
    seo_desc:   zod.string().default(''),
    focus_kw:   zod.string().default(''),
  },
  async (input) => toJson(await api('/posts', 'POST', input))
);

server.tool(
  'post_update',
  'Update an existing WordPress post. Only pass fields you want to change.',
  {
    id:         zod.number(),
    title:      zod.string().optional(),
    content:    zod.string().optional(),
    excerpt:    zod.string().optional(),
    status:     zod.enum(['draft', 'publish', 'pending', 'private']).optional(),
    slug:       zod.string().optional(),
    categories: zod.array(zod.number()).optional(),
    tags:       zod.array(zod.string()).optional(),
    seo_title:  zod.string().optional(),
    seo_desc:   zod.string().optional(),
    focus_kw:   zod.string().optional(),
  },
  async ({ id, ...input }) => ok(`Updated post ${id}`, await api(`/posts/${id}`, 'PUT', input))
);

server.tool(
  'post_delete',
  'Move a WordPress post to trash.',
  { id: zod.number().describe('Post ID') },
  async ({ id }) => ok(`Deleted post ${id}`, await api(`/posts/${id}`, 'DELETE'))
);

// 3. PAGES (5 tools)
server.tool(
  'pages_list',
  'List WordPress pages with search, slug, status, and author filters.',
  {
    per_page:  zod.number().default(30),
    search:    zod.string().default('').describe('Search by page title'),
    slug:      zod.string().default('').describe('Exact slug match'),
    status:    zod.enum(['publish', 'draft', 'private', 'any']).default('publish'),
    orderby:   zod.enum(['post_date', 'post_title', 'post_modified', 'menu_order']).default('post_date'),
    order:     zod.enum(['ASC', 'DESC']).default('DESC'),
    author_id: zod.number().optional().describe('Filter by author ID'),
    fields:    zod.string().default('').describe('Comma-separated fields to return, e.g. id,title,url'),
  },
  async (args) => toJson(await api(`/pages?${qs(args)}`))
);

server.tool(
  'page_get',
  'Get full details of a WordPress page by ID.',
  { id: zod.number().describe('Page ID') },
  async ({ id }) => toJson(await api(`/pages/${id}`))
);

server.tool(
  'page_create',
  'Create a new WordPress page. Returns {id, url, status}.',
  {
    title:        zod.string().describe('Page title'),
    content:      zod.string().optional().describe('HTML content'),
    excerpt:      zod.string().optional(),
    status:       zod.enum(['publish', 'draft', 'private', 'pending']).default('draft'),
    slug:         zod.string().optional(),
    parent:       zod.number().optional().describe('Parent page ID (0 = top level)'),
    order:        zod.number().optional().describe('menu_order'),
    template:     zod.string().optional(),
    thumbnail_id: zod.number().optional(),
    author_id:    zod.number().optional(),
    seo_title:    zod.string().optional(),
    seo_desc:     zod.string().optional(),
    focus_kw:     zod.string().optional(),
  },
  async (input) => toJson(await api('/pages', 'POST', input))
);

server.tool(
  'page_update',
  'Update an existing WordPress page. Only pass fields you want to change.',
  {
    id:           zod.number(),
    title:        zod.string().optional(),
    content:      zod.string().optional(),
    excerpt:      zod.string().optional(),
    status:       zod.enum(['publish', 'draft', 'private', 'pending']).optional(),
    slug:         zod.string().optional(),
    parent:       zod.number().optional(),
    order:        zod.number().optional(),
    template:     zod.string().optional(),
    thumbnail_id: zod.number().optional(),
    author_id:    zod.number().optional(),
    seo_title:    zod.string().optional(),
    seo_desc:     zod.string().optional(),
    focus_kw:     zod.string().optional(),
    canonical:    zod.string().optional(),
  },
  async ({ id, ...input }) => toJson(await api(`/pages/${id}`, 'PUT', input))
);

server.tool(
  'page_delete',
  'Delete a WordPress page. force=false moves to trash; force=true permanently deletes.',
  {
    id:    zod.number().describe('Page ID'),
    force: zod.boolean().optional().describe('true = permanently delete, false (default) = trash'),
  },
  async ({ id, force }) => toJson(await api(`/pages/${id}?force=${force ?? false}`, 'DELETE'))
);

// 4. MEDIA (3 tools)
server.tool(
  'media_list',
  'List WordPress media library items (id, title, url, alt, date).',
  {
    ...Pager,
    search: zod.string().default(''),
    mime:   zod.string().default('image').describe('image | video | application/pdf'),
  },
  async (args) => toJson(await api(`/media?${qs(args)}`))
);

server.tool(
  'media_get',
  'Get details of a media attachment (dimensions, alt text, caption, file size).',
  { id: zod.number().describe('Attachment ID') },
  async ({ id }) => toJson(await api(`/media/${id}`))
);

server.tool(
  'media_upload',
  'Upload an image from a public URL into the WordPress media library.',
  {
    url:     zod.string().describe('Image URL'),
    title:   zod.string().optional(),
    alt:     zod.string().optional(),
    post_id: zod.number().default(0).describe('Optional post ID to attach to'),
  },
  async (input) => toJson(await api('/media', 'POST', input))
);

// 5. CATEGORIES & TAGS (2 tools)
server.tool(
  'get_categories',
  'List WordPress post categories (id, name, slug, count).',
  {},
  async () => toJson(await api('/categories'))
);

server.tool(
  'get_tags',
  'List top 100 WordPress post tags (id, name, slug, count).',
  {},
  async () => toJson(await api('/tags'))
);

// 6. COMMENTS (2 tools)
server.tool(
  'comments_list',
  'List WordPress comments filtered by status and post ID.',
  {
    ...Pager,
    status:  zod.enum(['hold', 'approve', 'spam', 'trash', 'all']).default('hold').describe('hold = pending moderation'),
    post_id: zod.number().default(0).describe('Filter by post ID (0 = all)'),
  },
  async (args) => toJson(await api(`/comments?${qs(args)}`))
);

server.tool(
  'comment_update',
  'Moderate a comment (approve, pending, spam, trash) or edit its content.',
  {
    id:      zod.number().describe('Comment ID'),
    status:  zod.enum(['approved', 'pending', 'spam', 'trash']).optional(),
    content: zod.string().optional(),
  },
  async ({ id, ...input }) => ok(`Updated comment ${id}`, await api(`/comments/${id}`, 'PUT', input))
);

// ── Startup ──────────────────────────────────────────────────────────────────
const noAutoStart = process.env.MCP_NO_AUTOSTART === '1' || process.env.VERCEL === '1';

const isHttp =
  process.argv.includes('--http') ||
  /^(http|streamable-http)$/i.test(process.env.MCP_TRANSPORT || '') ||
  Boolean(process.env.PORT);

if (noAutoStart) {
  // Imported as library
} else if (isHttp) {
  await startHttpServer();
} else {
  const env = getEnvConfig();
  if (!env.base || !env.key) {
    console.error('[XCLIPSE] Missing XCLIPSE_URL or XCLIPSE_API_KEY');
    process.exit(1);
  }

  const { server: mcpServer, stats } = createMcpServer();
  const transport = new StdioServerTransport();
  await mcpServer.connect(transport);
  console.error(`[XCLIPSE Lite v${VERSION}] Connected to ${env.base} | ${stats.registered} Core tools`);
}
