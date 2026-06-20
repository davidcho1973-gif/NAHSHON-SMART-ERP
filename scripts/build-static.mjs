import { mkdir, writeFile } from 'node:fs/promises';

await mkdir('public/build', { recursive: true });
await writeFile('public/build/manifest.json', JSON.stringify({
  '_smart_company': {
    file: '../css/smart-company.css',
    src: 'public/css/smart-company.css',
    isEntry: true,
  },
}, null, 2) + '\n');

console.log('SMART COMPANY static assets are ready.');
