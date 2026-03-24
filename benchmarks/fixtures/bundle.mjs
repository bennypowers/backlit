import { build } from 'esbuild';
import { readFileSync, writeFileSync } from 'fs';

// Stub Node.js built-ins for QuickJS (no node: available).
const stubNodeBuiltins = {
  name: 'stub-node-builtins',
  setup(build) {
    const nodeFilter = /^(?:node:)?(?:buffer|fs|path|stream|util|events|crypto|os|url|http|https|net|tls|child_process|module|vm|zlib)(?:\/|$)/;

    build.onResolve({ filter: nodeFilter }, args => ({
      path: args.path,
      namespace: 'node-stub',
    }));

    build.onLoad({ filter: /.*/, namespace: 'node-stub' }, () => ({
      contents: `
        export default {};
        export const Buffer = {
          from(x) { return typeof x === 'string' ? new TextEncoder().encode(x) : new Uint8Array(x); },
          isBuffer: () => false, alloc: n => new Uint8Array(n),
        };
        export const readFileSync = () => '';
        export const statSync = () => ({ size: 0, isFile: () => true, isDirectory: () => false });
        export const createReadStream = () => ({ on(){}, pipe(){} });
        export const promises = { readFile: async () => '', stat: async () => ({ size: 0 }) };
        export const resolve = (...a) => a.join('/');
        export const join = (...a) => a.join('/');
        export const dirname = p => p;
        export const basename = p => p;
        export const extname = () => '';
        export const createHash = () => ({ update(){ return this; }, digest: () => '' });
        export const Readable = class {};
      `,
      loader: 'js',
    }));

    // Stub @lit-labs/ssr (but not @lit-labs/ssr-dom-shim which is needed).
    build.onResolve({ filter: /^@lit-labs\/ssr/ }, args => {
      if (args.path.includes('ssr-dom-shim')) return undefined;
      return { path: args.path, namespace: 'lit-ssr-stub' };
    });

    build.onLoad({ filter: /.*/, namespace: 'lit-ssr-stub' }, () => ({
      contents: `
        export function installWindowOnGlobal(props) {
          for (const [k, v] of Object.entries(props)) {
            if (typeof globalThis[k] === 'undefined') {
              globalThis[k] = v;
            }
          }
        }
      `,
      loader: 'js',
    }));

  },
};

const entryPoint = process.argv[2] || 'components.js';
const outfile = process.argv[3] || 'bundle.js';

await build({
  entryPoints: [entryPoint],
  bundle: true,
  format: 'esm',
  platform: 'node',
  conditions: ['node'],
  outfile,
  logLevel: 'warning',
  plugins: [stubNodeBuiltins],
});

// Post-process for QuickJS eval compatibility.
let code = readFileSync(outfile, 'utf8');
code = code.replace(/^export\s*\{[^}]*\}\s*;?\s*$/gm, '');

// Shim document.documentElement before any RHDS code runs.
// Shims injected before bundle code. These fill gaps between
// lit-ssr-wasm's QuickJS environment and what RHDS/pfe-core expects.
// The lit-ssr-wasm runtime provides some DOM shims (document, window,
// customElements, HTMLElement, etc.) but is missing several browser
// globals that RHDS's pfe-core ssr-shims.js tries to use.
const shims = `
if (typeof globalThis.navigator === 'undefined') {
  globalThis.navigator = { userAgent: 'lit-ssr', language: 'en', languages: ['en'] };
}
if (typeof globalThis.Event === 'undefined') {
  globalThis.Event = class Event { constructor(t,o){this.type=t;} };
}
if (typeof globalThis.ErrorEvent === 'undefined') {
  globalThis.ErrorEvent = class ErrorEvent extends Event {};
}
if (typeof globalThis.CustomEvent === 'undefined') {
  globalThis.CustomEvent = class CustomEvent extends Event { constructor(t,o){super(t,o);this.detail=o?.detail;} };
}
const _ObserverShim = class { observe(){} unobserve(){} disconnect(){} };
if (typeof globalThis.ResizeObserver === 'undefined') globalThis.ResizeObserver = _ObserverShim;
if (typeof globalThis.IntersectionObserver === 'undefined') globalThis.IntersectionObserver = _ObserverShim;
if (typeof globalThis.MutationObserver === 'undefined') {
  globalThis.MutationObserver = class extends _ObserverShim { takeRecords(){ return []; } };
}
if (typeof globalThis.getComputedStyle === 'undefined') {
  globalThis.getComputedStyle = () => ({ getPropertyValue: () => '', getPropertyPriority: () => '' });
}
if (typeof globalThis.matchMedia === 'undefined') {
  const noop = () => {};
  globalThis.matchMedia = () => ({
    matches: false, media: '',
    addListener: noop, removeListener: noop,
    addEventListener: noop, removeEventListener: noop,
    dispatchEvent: () => false, onchange: null,
  });
}
if (typeof globalThis.requestAnimationFrame === 'undefined') {
  globalThis.requestAnimationFrame = (cb) => setTimeout(cb, 0);
  globalThis.cancelAnimationFrame = (id) => clearTimeout(id);
}
if (typeof document !== 'undefined') {
  if (!document.documentElement) {
    document.documentElement = document.createElement
      ? document.createElement('html')
      : { style: {}, getAttribute() { return null; }, setAttribute() {} };
  }
  if (!document.documentElement.computedStyleMap) {
    document.documentElement.computedStyleMap = () => ({ has: () => false, get: () => null });
  }
  if (!document.documentElement.style) {
    document.documentElement.style = {};
  }
  if (!document.adoptedStyleSheets) {
    document.adoptedStyleSheets = [];
  }
}
`;

code = `(async function(){${shims}${code}})();\n`;
writeFileSync(outfile, code);
