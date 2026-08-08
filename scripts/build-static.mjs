import { execSync } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';

await mkdir('public/build', { recursive: true });
await writeFile('public/build/manifest.json', JSON.stringify({
  '_smart_company': {
    file: '../css/smart-company.css',
    src: 'public/css/smart-company.css',
    isEntry: true,
  },
}, null, 2) + '\n');

/**
 * 어느 커밋이 배포됐는지 빌드 시점에 파일로 새긴다.
 *
 * 왜 파일인가: 배포된 서버에는 .git 이 없을 수 있고 shell_exec 가 막혀 있을 수도 있어서,
 * 런타임에 커밋을 알아내려는 시도는 조용히 빈 값이 된다. 빌드는 소스가 있는 곳에서 도니까
 * 그때 적어 두는 것이 유일하게 확실한 방법이다.
 *
 * 이게 없으면 "배포가 됐나" 를 사이드바 글자를 눈으로 훑어 판단해야 한다.
 */
function shell(cmd) {
  try {
    return execSync(cmd, { stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim() || null;
  } catch {
    return null;
  }
}

const version = {
  // Laravel Cloud 등 호스팅이 넣어 주는 값을 먼저 쓰고, 없으면 git 에서 읽는다.
  commit: process.env.LARAVEL_CLOUD_COMMIT_SHA
    || process.env.GIT_COMMIT
    || shell('git rev-parse HEAD'),
  branch: process.env.LARAVEL_CLOUD_BRANCH
    || shell('git rev-parse --abbrev-ref HEAD'),
  subject: shell('git log -1 --pretty=%s'),
  committed_at: shell('git log -1 --pretty=%cI'),
  built_at: new Date().toISOString(),
};

await writeFile('public/build/version.json', JSON.stringify(version, null, 2) + '\n');

console.log('SMART COMPANY static assets are ready.');
console.log(`  build version: ${version.commit ? version.commit.slice(0, 7) : 'unknown'} (${version.branch || '?'})`);
