import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdtempSync, readFileSync, readdirSync, rmdirSync, symlinkSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import process from 'node:process';
import test from 'node:test';

const root = process.cwd();
const common = execFileSync('git', ['-C', root, 'rev-parse', '--git-common-dir'], { encoding: 'utf8' }).trim();
const main = dirname(resolve(root, common));
const env = { ...process.env, APP_ENV: 'testing', E106E_BROWSER_FIXTURE: '1' };
const prepare = join(root, 'tests', 'Browser', 'E1-06E', 'prepare-production.mjs');
const vite = join(root, 'node_modules', 'vite', 'bin', 'vite.js');
const runtime = join(root, '.local', 'runtime');
const run = (args, cwd = root) => spawnSync(process.execPath, args, { cwd, env, encoding: 'utf8' });
const manifest = join(main, 'public', 'build', 'manifest.json');
const fingerprint = () => existsSync(manifest) ? createHash('sha256').update(readFileSync(manifest)).digest('hex') : null;
const prepareFixture = () => {
  const result = run([prepare]);
  assert.equal(result.status, 0, result.stderr);
  return readFileSync(join(runtime, 'e106e-production-config.txt'), 'utf8').trim();
};

test('rejects preparation from the main checkout before changing its assets', () => {
  const before = fingerprint();
  const result = run([prepare], main);
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /separate Git worktree is required/i);
  assert.equal(fingerprint(), before);
});

test('builds into a fresh runtime directory of the separate worktree', () => {
  const config = prepareFixture();
  const scratch = dirname(config);
  const before = fingerprint();
  const result = run([vite, 'build', '--config', config]);
  assert.equal(result.status, 0, result.stderr);
  assert.ok(existsSync(join(scratch, 'public', 'build', 'manifest.json')));
  assert.equal(fingerprint(), before);
});

test('rejects a redirected build directory before running Vite', () => {
  const config = prepareFixture();
  const build = join(dirname(config), 'public', 'build');
  const target = mkdtempSync(join(runtime, 'e106e-redirect-target-'));
  const marker = join(target, 'keep.txt');
  writeFileSync(marker, 'untouched');
  rmdirSync(build); // The directory was freshly created by this fixture and is empty.
  symlinkSync(target, build, process.platform === 'win32' ? 'junction' : 'dir');
  const result = run([vite, 'build', '--config', config]);
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /Redirected production fixture path/);
  assert.deepEqual(readdirSync(target), ['keep.txt']);
  assert.equal(readFileSync(marker, 'utf8'), 'untouched');
});
