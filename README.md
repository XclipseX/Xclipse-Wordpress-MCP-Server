# Xclipse WordPress MCP Server (Core Lite)

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Node.js >= 18](https://img.shields.io/badge/node-%3E%3D18-brightgreen.svg)](https://nodejs.org/)
[![MCP Compatible](https://img.shields.io/badge/MCP-Stdio%20%7C%20Streamable%20HTTP-purple.svg)](https://modelcontextprotocol.io/)

A lightweight, open-source **Model Context Protocol (MCP)** server and WordPress REST bridge for managing WordPress content directly from **Claude Desktop, Cursor, Windsurf, Gemini**, and any MCP-compatible AI client.

> **Looking for the full Pro edition (390+ tools)?**
> Upgrade to **[Xclipse WordPress MCP Pro on Agensi.io](https://www.agensi.io/r/XCLIPEWPMCPSRVR?to=/skills/xclipse-wordpress-mcp-390-tools-oauth-2-1)** for **WooCommerce, Rank Math SEO, Elementor, Flatsome UX Builder, LiteSpeed Cache, ACF, WP Mail SMTP, Contact Form 7, OAuth 2.1, Safety Mode, Bulk Operations, and 90% token-saving Compact Mode**.

---

## Included Tools (18 Core Tools)

| Category | Tools |
| :--- | :--- |
| **Site Info (1)** | `site_info` |
| **Posts (5)** | `posts_list`, `post_get`, `post_create`, `post_update`, `post_delete` |
| **Pages (5)** | `pages_list`, `page_get`, `page_create`, `page_update`, `page_delete` |
| **Media (3)** | `media_list`, `media_get`, `media_upload` |
| **Taxonomy (2)** | `get_categories`, `get_tags` |
| **Comments (2)** | `comments_list`, `comment_update` |

---

## Quick Start

### 1. Install the WordPress Plugin (6 Core PHP Modules)

1. Zip the [`wordpress-plugin/xclipse-mcp-lite`](wordpress-plugin/xclipse-mcp-lite) folder (or upload it directly to `wp-content/plugins/xclipse-mcp-lite/`).
2. In WordPress Admin, go to **Plugins → Installed Plugins** and activate **Xclipse MCP Lite**.
3. Go to **Settings → Xclipse MCP Lite** to copy your **Site URL** (`XCLIPSE_URL`) and **API Key** (`XCLIPSE_API_KEY`).

### 2. Install & Run the MCP Server

```bash
git clone https://github.com/XclipseX/Xclipse-Wordpress-MCP-Server.git
cd Xclipse-Wordpress-MCP-Server
npm install
```

### 3. Configure Your AI Client

#### Claude Desktop (`claude_desktop_config.json`) / Cursor (`.cursor/mcp.json`)

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "node",
      "args": ["/absolute/path/to/Xclipse-Wordpress-MCP-Server/src/index.js"],
      "env": {
        "XCLIPSE_URL": "https://your-site.example",
        "XCLIPSE_API_KEY": "your-api-key-here"
      }
    }
  }
}
```

#### Streamable HTTP Mode (Optional)

```bash
XCLIPSE_URL=https://your-site.example \
XCLIPSE_API_KEY=your-api-key-here \
MCP_AUTH_TOKEN=your-secret-bearer-token \
PORT=3000 \
npm run start:http
```

---

## Core Lite vs. Pro Edition

| Feature | Core Lite (Free / MIT) | [Xclipse WordPress MCP Pro](https://www.agensi.io/r/XCLIPEWPMCPSRVR?to=/skills/xclipse-wordpress-mcp-390-tools-oauth-2-1) |
| :--- | :---: | :---: |
| Core WordPress (Site, Posts, Pages, Media, Taxonomy, Comments) | 18 tools | Included |
| WooCommerce (Products, Variations, Orders, Coupons, Reports) | — | Included |
| Rank Math SEO, Redirects, Site Audit & Auto-Fix | — | Included |
| Elementor, Flatsome UX Builder, Menus, Widgets, Snippets | — | Included |
| LiteSpeed Cache, ACF, CPT UI, Contact Form 7, WP Mail SMTP | — | Included |
| Native OAuth 2.1 Authorization Server & Safety Mode | — | Included |
| Token-Saving Compact Gateway (`xclipse_discover` + `xclipse_call`) | — | Included |
| **Total Tools** | **18** | **390+** |

**[Get Xclipse WordPress MCP Pro (390+ Tools + OAuth 2.1) →](https://www.agensi.io/r/XCLIPEWPMCPSRVR?to=/skills/xclipse-wordpress-mcp-390-tools-oauth-2-1)**

---

## License

MIT License — see [LICENSE](LICENSE) for details.