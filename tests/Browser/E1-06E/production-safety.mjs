import { execFileSync } from 'node:child_process';
import { lstatSync, realpathSync } from 'node:fs';
import { basename, dirname, join, resolve } from 'node:path';
import process from 'node:process';

const same = (a, b) => process.platform === 'win32' ? a.toLowerCase() === b.toLowerCase() : a === b;
const real = (path) => realpathSync(path);

export function checkedWorktree() {
  if (process.env.E106E_BROWSER_FIXTURE !== '1' || process.env.APP_ENV !== 'testing') throw new Error('Test fixture is disabled.');
  const root = real(process.cwd());
  if (!lstatSync(join(root, '.git')).isFile()) throw new Error('A separate Git worktree is required.');
  const git = (...args) => execFileSync('git', ['-C', root, 'rev-parse', ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  const top = real(git('--show-toplevel'));
  const gitDir = real(resolve(root, git('--git-dir')));
  const commonDir = real(resolve(root, git('--git-common-dir')));
  if (!same(root, top) || same(gitDir, commonDir) || same(root, real(dirname(commonDir)))) throw new Error('A separate Git worktree is required.');
  const runtime = join(root, '.local', 'runtime');
  if (!same(real(runtime), runtime)) throw new Error('Unsafe runtime path.');
  return { root, runtime };
}

export function checkedProductionPaths(root, scratch) {
  const checked = checkedWorktree();
  if (!same(root, checked.root) || !same(dirname(scratch), checked.runtime) || !basename(scratch).startsWith('e106e-production-')) throw new Error('Unsafe production fixture.');
  for (const path of [scratch, join(scratch, 'resources'), join(scratch, 'public'), join(scratch, 'public', 'build')]) {
    if (!same(real(path), path)) throw new Error('Redirected production fixture path.');
  }
  return join(scratch, 'public', 'build');
}
