import { build } from 'esbuild';
import { mkdir, copyFile, cp, writeFile, readFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
const root = fileURLToPath(new URL('../', import.meta.url));
process.chdir(root);
await mkdir('assets/dist', { recursive: true });
// Preserve the legacy template vocabulary without shipping Bootstrap. New
// screens use prefixed Tailwind utilities; these map the existing 200+ templates.
let css = '';
const rule = (selector, declarations) => { css += `${selector}{${declarations}}\n`; };
const spaces = { 0: '0', 1: '.25rem', 2: '.5rem', 3: '1rem', 4: '1.5rem', 5: '3rem', auto: 'auto' };
for (const [key, value] of Object.entries(spaces)) {
    for (const [prefix, property] of [['m', 'margin'], ['p', 'padding']]) {
        if (prefix === 'p' && key === 'auto') continue;
        for (const [axis, sides] of Object.entries({ '': [''], t: ['-top'], b: ['-bottom'], s: ['-inline-start'], e: ['-inline-end'], x: ['-left', '-right'], y: ['-top', '-bottom'] })) {
            rule(`.${prefix}${axis}-${key}`, sides.map(side => `${property}${side}:${value}!important`).join(';'));
        }
    }
    if (key !== 'auto') rule(`.gap-${key}`, `gap:${value}!important`);
}
for (const [prefix, min] of Object.entries({ '': 0, sm: 576, md: 768, lg: 992, xl: 1200, xxl: 1400 })) {
    if (min) css += `@media(min-width:${min}px){\n`;
    const infix = prefix ? `${prefix}-` : '';
    for (const display of ['none', 'block', 'inline', 'inline-block', 'flex', 'inline-flex', 'grid']) rule(`.d-${infix}${display}`, `display:${display}!important`);
    rule(`.col-${infix}auto`, 'flex:0 0 auto;width:auto');
    for (let column = 1; column <= 12; column++) rule(`.col-${infix}${column}`, `flex:0 0 auto;width:${column / 12 * 100}%`);
    if (min) css += '}\n';
}
for (const [key, value] of Object.entries(spaces)) if (key !== 'auto') {
    rule(`.g-${key},.gx-${key}`, `--gutter-x:${value}`);
    rule(`.g-${key},.gy-${key}`, `--gutter-y:${value}`);
}
const colors = { primary: '#175c91', secondary: '#64748b', success: '#18794e', danger: '#b42338', warning: '#b77908', info: '#087e9d', light: '#f1f5f9', dark: '#182b3a', white: '#fff' };
for (const [name, color] of Object.entries(colors)) {
    const text = ['light', 'white', 'warning'].includes(name) ? '#182b3a' : '#fff';
    rule(`.text-${name}`, `color:${color}!important`);
    rule(`.bg-${name}`, `background-color:${color}!important;color:${text}`);
    rule(`.border-${name}`, `border-color:${color}!important`);
    rule(`.btn-${name}`, `--button-color:${color};background:${color};border-color:${color};color:${text}`);
    rule(`.btn-outline-${name}`, `--button-color:${color};background:transparent;border-color:${color};color:${color}`);
    rule(`.btn-outline-${name}:hover`, `background:${color};color:${text}`);
    rule(`.alert-${name}`, `background:color-mix(in srgb,${color} 9%,white);color:${['light', 'white'].includes(name) ? '#182b3a' : color};border-color:color-mix(in srgb,${color} 30%,white);border-inline-start-color:${color}`);
    rule(`.table-${name}`, `--row-bg:color-mix(in srgb,${color} 10%,white)`);
    rule(`.bg-${name}-subtle`, `background:color-mix(in srgb,${color} 10%,white)!important`);
}
await writeFile('frontend/generated-utilities.css', css);
const cli = path.resolve('node_modules/@tailwindcss/cli/dist/index.mjs');
const result = spawnSync(process.execPath, [cli, '-i', 'frontend/app.css', '-o', 'assets/dist/app.css', '--minify'], { stdio: 'inherit' });
if (result.status !== 0) process.exit(result.status || 1);
await build({ entryPoints: ['frontend/workspace.js', 'frontend/vendor.js', 'assets/js/ui-components.js'], outdir: 'assets/dist', bundle: true, minify: true, sourcemap: false, target: ['es2020'], legalComments: 'external', entryNames: '[name]' });
for (const name of ['vendor.js.LEGAL.txt', 'workspace.js.LEGAL.txt']) {
    const notices = await readFile('assets/dist/' + name, 'utf8');
    await writeFile('assets/dist/' + name, notices.replace(/[\t ]+$/gm, ''));
}
await copyFile('node_modules/@fortawesome/fontawesome-free/css/all.min.css', 'assets/dist/icons.css');
await cp('node_modules/@fortawesome/fontawesome-free/webfonts', 'assets/webfonts', { recursive: true });
// Cache-busting manifest contains only public generated files, never school data.
const { createHash } = await import('node:crypto');
const files = ['app.css', 'workspace.js', 'vendor.js', 'vendor.css', 'icons.css', 'ui-components.js'];
const manifest = Object.fromEntries(await Promise.all(files.map(async file => [file, createHash('sha256').update(await readFile('assets/dist/' + file)).digest('hex').slice(0, 12)])));
await writeFile('assets/dist/manifest.json', JSON.stringify(manifest, null, 2) + '\n');
console.log('Built static PHP-hosting assets. No Node.js process is needed on InfinityFree.');
