import { build } from 'esbuild';
import * as sass from 'sass';
import { readFile, writeFile, mkdir, cp } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
process.chdir(root);
const production = process.argv.includes('--production');
const output = path.join(root, process.argv.includes('--acceptance') ? '.local-acceptance/frontend' : 'public');
await mkdir(path.join(output, 'js'), { recursive: true });
await mkdir(path.join(output, 'css'), { recursive: true });
await build({ entryPoints: ['resources/js/app.js'], bundle: true, platform: 'browser', format: 'iife',
    target: ['es2020'], minify: production, legalComments: 'eof',
    define: { 'process.env.NODE_ENV': JSON.stringify(production ? 'production' : 'development') },
    outfile: path.join(output, 'js/init.js'), logLevel: 'info' });
const compiled = sass.compile('resources/sass/app.scss', { loadPaths: [root, path.join(root, 'node_modules')],
    style: production ? 'compressed' : 'expanded', quietDeps: true, silenceDeprecations: ['import', 'global-builtin', 'color-functions'] });
await writeFile(path.join(output, 'css/init.css'), compiled.css);

// Ordered static inputs are data, independent of the retired Mix/webpack runtime.
const groups = JSON.parse(await readFile('frontend.assets.json', 'utf8'));
if (Object.keys(groups).length !== 3) throw new Error('Static asset manifest changed; review the build script.');
for (const [destination, files] of Object.entries(groups)) {
    if (!['public/js/vendor.js', 'public/css/vendor.css', 'public/css/rtl.css'].includes(destination)) throw new Error('Unexpected asset output.');
    const chunks = [];
    for (const file of files) {
        const source = ['public/js/init.js', 'public/css/init.css'].includes(file)
            ? path.join(output, file.slice(7)) : path.join(root, file);
        chunks.push(await readFile(source, 'utf8'));
    }
    if (destination === 'public/css/vendor.css') chunks.push(await readFile('node_modules/jodit/es2021/jodit.min.css', 'utf8'));
    await writeFile(path.join(output, destination.slice(7)), chunks.join(destination.endsWith('.js') ? '\n;\n' : '\n'));
}
await mkdir(path.join(output, 'fonts'), { recursive: true });
for (const font of ['glyphicons-halflings-regular.woff2', 'glyphicons-halflings-regular.woff', 'glyphicons-halflings-regular.ttf']) {
    await cp(`resources/plugins/bootstrap/fonts/${font}`, path.join(output, 'fonts', font));
}
await cp('resources/plugins/ionicons/fonts/ionicons.ttf', path.join(output, 'fonts/ionicons.ttf'));
await cp('node_modules/@fortawesome/fontawesome-free/webfonts', path.join(output, 'webfonts'), { recursive: true });
await cp('node_modules/jquery-ui-dist/images', path.join(output, 'css/images'), { recursive: true });
const manifest = Object.fromEntries(['js/init.js', 'js/vendor.js', 'css/init.css', 'css/vendor.css', 'css/rtl.css'].map(file => ['/'+file, '/'+file]));
await writeFile(path.join(output, 'mix-manifest.json'), JSON.stringify(manifest, null, 2)+'\n');
console.log(`Frontend built at ${path.relative(root, output)}; no application database was opened.`);
