const test = require('node:test');
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');

test('synthetic encryption keys are fresh, valid and absent from shell trace output', () => {
    const script = `source tests/browser/environment.sh
export erp_browser_first_key="$APP_KEY"
source tests/browser/environment.sh
php -r '$key=getenv("APP_KEY"); $prior=getenv("erp_browser_first_key"); if (!str_starts_with($key,"base64:") || strlen(base64_decode(substr($key,7),true))!==32 || $key===$prior || getenv("APP_ENV")!=="testing" || getenv("DB_HOST")!=="127.0.0.1" || getenv("DB_DATABASE")!=="erp_assistant_browser_test") { exit(1); } echo "synthetic key checks passed\\n";'
`;
    const result = spawnSync('bash', ['-x', '-e', '-c', script], {cwd: root, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.stdout, 'synthetic key checks passed\n');
    assert.doesNotMatch(result.stderr, /base64:|erp_browser_first_key=/);
});

test('browser seed and server share a single process environment without a persisted key', () => {
    const workflow = fs.readFileSync(path.join(root, '.github/workflows/assistant-browser.yml'), 'utf8');
    const environment = fs.readFileSync(path.join(root, 'tests/browser/environment.sh'), 'utf8');
    assert.equal(workflow.match(/source tests\/browser\/environment\.sh/g).length, 1);
    const guard = workflow.indexOf('php tests/browser/fixture.php guard');
    const seed = workflow.indexOf('php tests/browser/fixture.php seed');
    const serve = workflow.indexOf('php artisan serve');
    assert.ok(guard >= 0 && seed > guard && serve > seed);
    assert.doesNotMatch(workflow, /APP_KEY|key:generate/);
    assert.doesNotMatch(environment, /APP_KEY=['"]?base64:|GITHUB_ENV|\.env\b/);
});
