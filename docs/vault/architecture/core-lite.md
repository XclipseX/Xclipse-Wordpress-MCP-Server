---
type: architecture
tags: [architecture, mcp, wordpress, core-lite]
related: ["[[00-Index]]", "[[2026-11-25]]"]
updated: 2026-11-25
---
# Xclipse WordPress MCP Server — Core Lite Architecture

## Overview
Public open-source (MIT) Core Lite edition extracted from `Xclipse-Wordpress-MCP-Pro`. Exposes **18 Core MCP tools** over Stdio or Streamable HTTP, backed by a lightweight WordPress plugin with **6 Core PHP modules**.

## Components
- **MCP Server (Node.js ESM):**
  - `src/index.js` — Registers 18 Core tools (`site_info`, 5 posts, 5 pages, 3 media, 2 taxonomy, 2 comments) and starts Stdio or HTTP transport.
  - `src/core.js` — REST client (`api()`), TTL cache (`/categories`, `/tags`), response compaction, output limiter, error classification.
  - `src/http.js` — Stateless Streamable HTTP transport (`/mcp`, `/health`) with Bearer token auth.
- **WordPress Plugin (`wordpress-plugin/xclipse-mcp-lite/`):**
  - `xclipse-mcp-lite.php` — API key auth (`X-MCP-Key`), Settings page, REST routes under `/wp-json/xclipse-mcp/v1`.
  - `includes/{site,posts,pages,media,taxonomy,comments}.php` — 6 Core PHP handler modules.
- **Packaging & Distribution:**
  - `smithery.yaml`, `Dockerfile`, `LICENSE` (MIT), `README.md` (links to Pro edition on Agensi.io).

## Code Graph Hubs
Built via `graphify update .` (see `graphify-out/GRAPH_REPORT.md`):
- Key code hubs: `core.js`, `xclipse_ok`, `package.json`.
