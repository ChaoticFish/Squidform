// A stand-in for the ClamAV daemon, for tests only: answers zPING/zVERSION and
// zINSTREAM. A file "is infected" when it contains SQUIDFORM-TEST-INFECTED (a
// harmless marker, so no real test virus string is ever needed).
import net from 'node:net';
export function startFakeClamd(port = 33100) {
  const server = net.createServer((sock) => {
    let buf = Buffer.alloc(0);
    let mode = null;
    const data = [];
    sock.on('data', (d) => {
      buf = Buffer.concat([buf, d]);
      if (!mode) {
        const z = buf.indexOf(0);
        if (z < 0) return;
        mode = buf.subarray(0, z).toString();
        buf = buf.subarray(z + 1);
        if (mode === 'zPING') { sock.end('PONG\0'); return; }
        if (mode === 'zVERSION') { sock.end('ClamAV 0.0 (SquidForm test stand-in)\0'); return; }
      }
      while (mode === 'zINSTREAM' && buf.length >= 4) {
        const n = buf.readUInt32BE(0);
        if (n === 0) {
          const all = Buffer.concat(data).toString('latin1');
          sock.end(all.includes('SQUIDFORM-TEST-INFECTED') ? 'stream: SquidForm.Test.Marker FOUND\0' : 'stream: OK\0');
          mode = 'done';
          return;
        }
        if (buf.length < 4 + n) return;
        data.push(buf.subarray(4, 4 + n));
        buf = buf.subarray(4 + n);
      }
    });
    sock.on('error', () => {});
  });
  return new Promise((ok) => server.listen(port, '127.0.0.1', () => ok(server)));
}
