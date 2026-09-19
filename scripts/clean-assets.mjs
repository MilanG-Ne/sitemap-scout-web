import { rmSync } from 'node:fs';
// Vite shares public/ with the PHP entry point, so emptyOutDir must stay false.
// Clear only the generated asset directory before a build.
rmSync(new URL('../public/assets', import.meta.url), {recursive: true, force: true});
