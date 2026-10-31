import https from 'node:https';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
const root = process.cwd();
const credentials = {
  key: fs.readFileSync(path.join(root, '.local/e107-test-key.pem')),
  cert: fs.readFileSync(path.join(root, '.local/e107-test-cert.pem')),
};
const token = fs.readFileSync(path.join(root, '.local/e107-proxy-token'), 'utf8').trim();
if (token.length < 32) throw new Error('Missing test proxy token.');
https
  .createServer(credentials, (req, res) => {
    if (req.socket.remoteAddress !== '127.0.0.1' || req.headers.host !== '127.0.0.1:8107') {
      res.writeHead(403);
      res.end();
      return;
    }
    const headers = {};
    for (const [key, value] of Object.entries(req.headers)) {
      if (
        !key.startsWith('x-forwarded-') &&
        !['forwarded', 'x-real-ip', 'cf-connecting-ip', 'x-e107-proxy-token'].includes(key)
      )
        headers[key] = value;
    }
    headers['x-forwarded-proto'] = 'https';
    headers['x-e107-proxy-token'] = token;
    const upstream = http.request(
      { host: '127.0.0.1', port: 8108, method: req.method, path: req.url, headers },
      (reply) => {
        res.writeHead(reply.statusCode || 502, reply.headers);
        reply.pipe(res);
      },
    );
    upstream.on('error', () => {
      if (!res.headersSent) res.writeHead(502);
      res.end();
    });
    req.pipe(upstream);
  })
  .listen(8107, '127.0.0.1', () =>
    process.stdout.write('E107 test TLS proxy ready on 127.0.0.1:8107\n'),
  );
