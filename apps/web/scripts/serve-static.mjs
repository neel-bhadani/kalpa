// Serves the built app (nuxi generate → .output/public) for preview mode. Node built-ins only.
// Unknown paths get index.html, as the SPA routes in the browser.
import { createReadStream, statSync } from 'node:fs'
import { createServer } from 'node:http'
import { extname, join, normalize } from 'node:path'

const root = new URL('../.output/public/', import.meta.url).pathname
const types = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.mjs': 'text/javascript', '.css': 'text/css',
  '.json': 'application/json', '.webmanifest': 'application/manifest+json', '.svg': 'image/svg+xml',
  '.png': 'image/png', '.ico': 'image/x-icon', '.woff2': 'font/woff2', '.txt': 'text/plain',
}

function fileFor(urlPath) {
  const path = normalize(decodeURIComponent(urlPath.split('?')[0])).replace(/^(\.\.[/\\])+/, '')
  for (const candidate of [path, join(path, 'index.html')]) {
    const full = join(root, candidate)
    try {
      if (full.startsWith(root) && statSync(full).isFile()) return full
    } catch {}
  }
  return join(root, 'index.html')
}

createServer((request, response) => {
  const file = fileFor(request.url ?? '/')
  const name = file.slice(root.length)
  response.writeHead(200, {
    'Content-Type': types[extname(file)] ?? 'application/octet-stream',
    // Hashed bundles never change; the service worker and HTML must always be checked.
    'Cache-Control': name.startsWith('_nuxt/') ? 'public, max-age=31536000, immutable' : 'no-cache',
  })
  createReadStream(file).pipe(response)
}).listen(3000, '0.0.0.0', () => console.log('Serving the built app from .output/public on :3000'))
