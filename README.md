# Backlit

<img src="backlit.png" alt="Backlit" width="200">

Server-render [Lit](https://lit.dev) web components from [Drupal](https://www.drupal.org/). No Node.js. No containers. No HTTP sidecar. Just a single binary that speaks NUL bytes.

Backlit hooks into Drupal's response pipeline and renders every Lit web component with [Declarative Shadow DOM](https://html.spec.whatwg.org/multipage/scripting.html#attr-template-shadowrootmode). Users see styled, laid-out content on first paint -- before any JavaScript loads. Disable JS entirely and the components still render. That is the way of the Lit.

## Quick start

```sh
composer require bennypowers/backlit
drush en backlit
```

That's it. Composer downloads the right binary for your platform. Drush enables the module. Every page response now gets its web components server-rendered.

If the binary download didn't run automatically:
```sh
cd web/modules/contrib/backlit
./scripts/download-binary.sh
```

## How it works

Backlit ships a pre-compiled Go binary that embeds a WASM module. Inside that WASM module: [QuickJS](https://bellard.org/quickjs/) running [`@lit-labs/ssr`](https://www.npmjs.com/package/@lit-labs/ssr) and your component definitions.

When Drupal finishes rendering a page, Backlit's `SsrResponseSubscriber` intercepts the response, pipes the HTML through the binary's stdin, and reads Declarative Shadow DOM enhanced HTML from stdout. The binary uses a NUL-delimited read-loop protocol, so the WASM instance stays warm across renders.

```
First render:  ~350ms  (WASM cold start -- paid once per PHP-FPM worker)
Every render after:  ~0.32ms  (just pipe I/O)
```

The binary auto-detects your platform. Supported: linux-x64, linux-arm64, darwin-x64, darwin-arm64, win32-x64, win32-arm64. Yes, we support Windows. No, we haven't tested it. Godspeed.

## What gets rendered

Every custom element tag name that has a definition baked into the WASM module gets its shadow DOM injected. Unknown elements pass through unchanged. Your `<div>`s are safe.

The default binary ships with example components (`<x-card>`, `<x-tabs>`, `<my-alert>`, etc.) from [lit-ssr-wasm](https://github.com/bennypowers/lit-ssr-wasm). To render your own components, you'll need to build a custom WASM module.

## Building custom components

1. Clone [lit-ssr-wasm](https://github.com/bennypowers/lit-ssr-wasm)
2. Write your LitElement components in `src/components/`
3. Import them in `src/entry.ts`, add tag names to `KNOWN_ELEMENTS`
4. `npm run build` (requires [Javy](https://github.com/bytecodealliance/javy))
5. Copy `dist/lit-ssr-builtin.wasm` to `go/lit-ssr.wasm`
6. `cd go && make linux-x64` (or your target platform)
7. Replace the binary in Backlit's `bin/` directory

## Performance

| Metric | Value |
|---|---|
| Cold start | ~350ms (once per PHP-FPM worker) |
| Warm render | ~0.32ms |
| Binary size | ~9 MB (statically linked, no dependencies) |
| Memory | ~20 MB per WASM instance |
| Dependencies | Zero. The binary is the dependency. |

For comparison, the [previous approach](https://bennypowers.dev/posts/drupal-lit-ssr/) required a Node.js container, HTTP round-trips, and ~50ms per render. The drop has moved.

## Requirements

- Drupal 10 or 11
- PHP 8.1+
- A server that can run a binary (so, any server)
- No PHP extensions, no PECL, no FFI, no containers, no npm

## Graceful degradation

If the binary isn't available or fails to start, Backlit returns the original HTML unchanged. Your components will still work client-side once their JavaScript loads -- they just won't have the instant first paint from DSD. This means you can develop locally without the binary and deploy with it.

## Architecture

```
Browser request
       |
   Drupal renders page (Twig, etc.)
       |
   KernelEvents::RESPONSE
       |
   SsrResponseSubscriber
       |
   LitSsrRenderer::render()
       |
   proc_open() -> lit-ssr binary (Go + wazero + QuickJS + @lit-labs/ssr)
       |
   stdin: HTML\0  ->  stdout: HTML-with-DSD\0
       |
   Response sent to browser with Declarative Shadow DOM
       |
   User sees styled content immediately
   (JavaScript loads later, hydrates if needed)
```

## FAQ

**Does this work with any web component framework?**
Only Lit. The SSR engine is `@lit-labs/ssr`, which understands Lit's template system. Vanilla custom elements or other frameworks would need their own SSR implementation.

**What about caching?**
Backlit operates on every response. If you have Drupal's page cache enabled, the rendered HTML (with DSD) gets cached, so subsequent requests skip the binary entirely. This is the recommended setup.

**Can I use this in production?**
The binary is statically linked with no runtime dependencies. The protocol is simple (NUL-delimited pipes). The failure mode is graceful (returns original HTML). So... probably? But this is still early days. File issues, send PRs, report back.

**Why "Backlit"?**
Because your Lit components are lit from behind -- server-rendered before the browser even sees them. Also because naming things is hard and this one was available.

## Links

- [lit-ssr-wasm](https://github.com/bennypowers/lit-ssr-wasm) -- the WASM module, Go library, and browser demo
- [Live demo](https://bennypowers.github.io/lit-ssr-wasm/compiled.html) -- runs the WASM module in your browser
- [Blog post](https://bennypowers.dev/posts/drupal-lit-ssr-wasm/) -- the full story
- [Previous approach (2024)](https://bennypowers.dev/posts/drupal-lit-ssr/) -- the Node.js sidecar version

## License

MIT
