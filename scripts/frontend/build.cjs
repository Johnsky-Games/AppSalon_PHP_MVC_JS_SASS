const fs = require('node:fs/promises');
const path = require('node:path');
const sass = require('sass');
const postcss = require('postcss');
const autoprefixer = require('autoprefixer');
const cssnano = require('cssnano');
const sharp = require('sharp');
const { minify } = require('terser');
const root = path.resolve(__dirname, '../..');
process.chdir(root);
async function files(dir) {
  const entries = await fs.readdir(dir, { withFileTypes: true });
  return (await Promise.all(entries.map(e => e.isDirectory() ? files(path.join(dir,e.name)) : path.join(dir,e.name)))).flat();
}
async function css() {
  const result = sass.compile('src/scss/app.scss', { style: 'expanded', sourceMap: true, loadPaths: ['src/scss'] });
  const output = await postcss([autoprefixer(), cssnano()]).process(result.css, { from: 'src/scss/app.scss', to: 'public/build/css/app.css', map: { prev: result.sourceMap, inline: false } });
  await fs.mkdir('public/build/css', { recursive: true });
  await fs.writeFile('public/build/css/app.css', output.css);
  if(output.map) await fs.writeFile('public/build/css/app.css.map', output.map.toString());
}
async function javascript() {
  for(const file of await files('src/js')) {
    if(!file.endsWith('.js')) continue;
    const target = path.join('public/build/js', path.relative('src/js',file));
    const result = await minify(await fs.readFile(file,'utf8'), { ecma: 2020 });
    await fs.mkdir(path.dirname(target), { recursive: true });
    await fs.writeFile(target,result.code);
  }
}
async function images() {
  for(const file of await files('src/img')) {
    const target = path.join('public/build/img',path.relative('src/img',file));
    await fs.mkdir(path.dirname(target), { recursive: true });
    await fs.copyFile(file,target);
    if(/\.(jpe?g|png)$/i.test(file)) await sharp(file).rotate().webp({ quality: 82 }).toFile(target.replace(/\.[^.]+$/,'.webp'));
  }
}
async function vendor() {
  await fs.mkdir('public/build/vendor',{ recursive: true });
  for(const [pkg,file,target] of [
    ['sweetalert2','dist/sweetalert2.all.min.js','sweetalert2.all.min.js'],
    ['sweetalert2','dist/sweetalert2.min.css','sweetalert2.min.css'],
    ['toastify-js','src/toastify.js','toastify.js'],
    ['toastify-js','src/toastify.css','toastify.css'],
    ['sweetalert2','LICENSE','sweetalert2.LICENSE'],['toastify-js','LICENSE','toastify.LICENSE']
  ]) await fs.copyFile(path.join('node_modules',pkg,file),path.join('public/build/vendor',target));
}
async function build(task = 'build') {
  const tasks = { css, javascript, images, vendor, build: () => Promise.all([css(),javascript(),images(),vendor()]) };
  if(!tasks[task]) throw new Error('Tarea de compilación desconocida: '+task);
  await tasks[task]();
  console.log('Frontend compilado: '+task);
}
if(require.main === module) {
  build(process.argv[2]).then(() => {
    if(process.argv.includes('--watch')) {
      let timer;
      require('node:fs').watch('src',{ recursive:true }, () => { clearTimeout(timer); timer=setTimeout(() => build().catch(console.error),200); });
      console.log('Observando src/');
    }
  }).catch(error => { console.error(error); process.exitCode=1; });
}
module.exports = { build, css, javascript, images, vendor };
