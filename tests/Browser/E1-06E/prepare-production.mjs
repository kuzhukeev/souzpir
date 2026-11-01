import { cpSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import console from 'node:console';
import { checkedProductionPaths, checkedWorktree } from './production-safety.mjs';

// A disposable integration copy only: the shared Team/Index in the checkout stays untouched.
const { root, runtime } = checkedWorktree();
const scratch = mkdtempSync(join(runtime, 'e106e-production-'));
cpSync(join(root, 'resources'), join(scratch, 'resources'), { recursive: true });
mkdirSync(join(scratch, 'public', 'build'), { recursive: true });
const outDir = checkedProductionPaths(root, scratch);
const team = join(scratch, 'resources', 'js', 'pages', 'Shell', 'Team', 'Index.tsx');
let source = readFileSync(team, 'utf8');
const needle = '  const { brand } = usePage<SharedPropsV1 & Record<string, unknown>>().props;';
const head = '      <Head title="Команда" />';
if (!source.includes(needle) || !source.includes(head) || source.includes('InvitationDialog')) throw new Error('Unexpected Team page; integration copy was not generated.');
source = "import InvitationDialog from '@/features/Shell/InvitationIssuance/InvitationDialog';\n" + source;
source = source.replace(needle, needle + '\n  const canIssueInvitations = usePage().props.canIssueInvitations === true;\n  const [issueOpen, setIssueOpen] = useState(false);');
source = source.replace(head, head + `
      {canIssueInvitations && <button type="button" onClick={() => setIssueOpen(true)}>Пригласить участника</button>}
      <InvitationDialog open={issueOpen} onClose={() => setIssueOpen(false)} canIssue={canIssueInvitations} brandId={brand?.id ?? ''} revision={teamBrandRevision} brands={filterBrands} />`);
writeFileSync(team, source);
const config = `
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { checkedProductionPaths } from ${JSON.stringify(pathToFileURL(join(root, 'tests', 'Browser', 'E1-06E', 'production-safety.mjs')).href)};
const output = checkedProductionPaths(${JSON.stringify(root)}, ${JSON.stringify(scratch)});
export default defineConfig({
  root: ${JSON.stringify(scratch)},
  base: '/build/',
  publicDir: false,
  plugins: [react(), tailwindcss()],
  resolve: { alias: { '@': ${JSON.stringify(join(scratch, 'resources', 'js'))} } },
  build: { outDir: output, emptyOutDir: false, manifest: 'manifest.json',
    rollupOptions: { input: [${JSON.stringify(join(scratch, 'resources', 'css', 'app.css'))}, ${JSON.stringify(join(scratch, 'resources', 'js', 'app.tsx'))}] } },
});
`;
writeFileSync(join(scratch, 'vite.config.mjs'), config);
mkdirSync(join(root, '.local', 'runtime'), { recursive: true });
writeFileSync(join(root, '.local', 'runtime', 'e106e-production-config.txt'), resolve(scratch, 'vite.config.mjs'));
writeFileSync(join(root, '.local', 'runtime', 'e106e-production-public.txt'), resolve(scratch, 'public'));
if (outDir !== join(scratch, 'public', 'build')) throw new Error('Unexpected output.');
console.log('Synthetic production integration copy prepared.');
