import { cpSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

// A disposable integration copy only: the shared Team/Index in the checkout stays untouched.
if (process.env.E106E_BROWSER_FIXTURE !== '1' || process.env.APP_ENV !== 'testing') throw new Error('Test fixture is disabled.');
const root = realpathSync(process.cwd());
const runtime = realpathSync(join(root, '.local', 'runtime'));
const expectedRuntime = resolve(root, '.local', 'runtime');
if (process.platform === 'win32' ? runtime.toLowerCase() !== expectedRuntime.toLowerCase() : runtime !== expectedRuntime) throw new Error('Unsafe runtime.');
const scratch = mkdtempSync(join(runtime, 'e106e-production-'));
cpSync(join(root, 'resources'), join(scratch, 'resources'), { recursive: true });
const team = join(scratch, 'resources', 'js', 'pages', 'Shell', 'Team', 'Index.tsx');
let source = readFileSync(team, 'utf8');
const needle = '  const { brand } = usePage<SharedPropsV1 & Record<string, unknown>>().props;';
const head = '      <Head title="Команда" />';
if (!source.includes(needle) || !source.includes(head) || source.includes('InvitationDialog')) throw new Error('Unexpected Team page; integration copy was not generated.');
source = "import InvitationDialog from '@/features/Shell/InvitationIssuance/InvitationDialog';\n" + source;
source = source.replace(needle, needle + '\n  const [issueOpen, setIssueOpen] = useState(false);');
source = source.replace(head, head + `
      {canViewRoles && <button type="button" onClick={() => setIssueOpen(true)}>Пригласить участника</button>}
      <InvitationDialog open={issueOpen} onClose={() => setIssueOpen(false)} canIssue={canViewRoles} brandId={brand?.id ?? ''} revision={teamBrandRevision} brands={filterBrands} />`);
writeFileSync(team, source);
const config = `
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
export default defineConfig({
  root: ${JSON.stringify(scratch)},
  plugins: [react(), tailwindcss()],
  resolve: { alias: { '@': ${JSON.stringify(join(scratch, 'resources', 'js'))} } },
  build: { outDir: ${JSON.stringify(join(root, 'public', 'build'))}, emptyOutDir: true, manifest: 'manifest.json',
    rollupOptions: { input: [${JSON.stringify(join(scratch, 'resources', 'css', 'app.css'))}, ${JSON.stringify(join(scratch, 'resources', 'js', 'app.tsx'))}] } },
});
`;
writeFileSync(join(scratch, 'vite.config.mjs'), config);
mkdirSync(join(root, '.local', 'runtime'), { recursive: true });
writeFileSync(join(root, '.local', 'runtime', 'e106e-production-config.txt'), resolve(scratch, 'vite.config.mjs'));
console.log('Synthetic production integration copy prepared.');
