// Local QR Model 2 encoder: byte mode, error correction L, deterministic mask 0.
// No URL, key, or image is sent to an external QR service.
const ecc = [0, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28];
const blockCounts = [0, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8];
function rawWords(version: number) {
  let modules = (16 * version + 128) * version + 64;
  if (version >= 2) {
    const count = Math.floor(version / 7) + 2;
    modules -= (25 * count - 10) * count - 55;
    if (version >= 7) modules -= 36;
  }
  return Math.floor(modules / 8);
}
function multiply(x: number, y: number) {
  let result = 0;
  for (let bit = 7; bit >= 0; bit--) {
    result = (result << 1) ^ ((result >>> 7) * 0x11d);
    result ^= ((y >>> bit) & 1) * x;
  }
  return result;
}
function remainder(data: number[], degree: number) {
  const divisor = Array<number>(degree).fill(0);
  divisor[degree - 1] = 1;
  let root = 1;
  for (let i = 0; i < degree; i++) {
    for (let j = 0; j < degree; j++)
      divisor[j] = multiply(divisor[j], root) ^ (j + 1 < degree ? divisor[j + 1] : 0);
    root = multiply(root, 2);
  }
  const result = Array<number>(degree).fill(0);
  for (const byte of data) {
    const factor = byte ^ result[0];
    result.shift();
    result.push(0);
    for (let i = 0; i < degree; i++) result[i] ^= multiply(divisor[i], factor);
  }
  return result;
}
export function qrMatrix(value: string): boolean[][] | null {
  const bytes = [...new TextEncoder().encode(value)];
  let version = 1;
  for (; version <= 20; version++)
    if (
      4 + (version < 10 ? 8 : 16) + bytes.length * 8 <=
      (rawWords(version) - ecc[version] * blockCounts[version]) * 8
    )
      break;
  if (version > 20) return null;
  const words = rawWords(version);
  const blocks = blockCounts[version];
  const degree = ecc[version];
  const capacity = (words - degree * blocks) * 8;
  const bits: number[] = [];
  const append = (value: number, count: number) => {
    for (let i = count - 1; i >= 0; i--) bits.push((value >>> i) & 1);
  };
  append(4, 4);
  append(bytes.length, version < 10 ? 8 : 16);
  for (const byte of bytes) append(byte, 8);
  append(0, Math.min(4, capacity - bits.length));
  while (bits.length % 8) bits.push(0);
  const data: number[] = [];
  for (let i = 0; i < bits.length; i += 8)
    data.push(bits.slice(i, i + 8).reduce((n, bit) => n * 2 + bit, 0));
  for (let pad = 0; data.length < capacity / 8; pad++) data.push(pad % 2 ? 0x11 : 0xec);
  const shortLength = Math.floor(words / blocks);
  const shortBlocks = blocks - (words % blocks);
  const encoded: number[][] = [];
  for (let block = 0, cursor = 0; block < blocks; block++) {
    const length = shortLength - degree + (block < shortBlocks ? 0 : 1);
    const part = data.slice(cursor, cursor + length);
    cursor += length;
    const check = remainder(part, degree);
    if (block < shortBlocks) part.push(0);
    encoded.push([...part, ...check]);
  }
  const interleaved: number[] = [];
  for (let i = 0; i < encoded[0].length; i++)
    for (let block = 0; block < blocks; block++)
      if (i !== shortLength - degree || block >= shortBlocks) interleaved.push(encoded[block][i]);
  const size = version * 4 + 17;
  const matrix = Array.from({ length: size }, () => Array<boolean>(size).fill(false));
  const reserved = Array.from({ length: size }, () => Array<boolean>(size).fill(false));
  const set = (x: number, y: number, black: boolean) => {
    matrix[y][x] = black;
    reserved[y][x] = true;
  };
  for (let i = 0; i < size; i++) {
    set(6, i, i % 2 === 0);
    set(i, 6, i % 2 === 0);
  }
  for (const [cx, cy] of [
    [3, 3],
    [size - 4, 3],
    [3, size - 4],
  ])
    for (let dy = -4; dy <= 4; dy++)
      for (let dx = -4; dx <= 4; dx++) {
        const x = cx + dx,
          y = cy + dy,
          distance = Math.max(Math.abs(dx), Math.abs(dy));
        if (x >= 0 && y >= 0 && x < size && y < size) set(x, y, distance !== 2 && distance !== 4);
      }
  if (version > 1) {
    const count = Math.floor(version / 7) + 2;
    const step = Math.floor((version * 4 + count * 2 + 1) / (count * 2 - 2)) * 2;
    const positions = [6];
    for (let pos = size - 7; positions.length < count; pos -= step) positions.splice(1, 0, pos);
    for (let i = 0; i < count; i++)
      for (let j = 0; j < count; j++) {
        if ((i === 0 && j === 0) || (i === 0 && j === count - 1) || (i === count - 1 && j === 0))
          continue;
        for (let dy = -2; dy <= 2; dy++)
          for (let dx = -2; dx <= 2; dx++)
            set(positions[i] + dx, positions[j] + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
      }
  }
  const formatData = 8; // L = 01, mask = 000.
  let formatRemainder = formatData;
  for (let i = 0; i < 10; i++)
    formatRemainder = (formatRemainder << 1) ^ ((formatRemainder >>> 9) * 0x537);
  const format = ((formatData << 10) | formatRemainder) ^ 0x5412;
  const formatBit = (i: number) => ((format >>> i) & 1) !== 0;
  for (let i = 0; i <= 5; i++) set(8, i, formatBit(i));
  set(8, 7, formatBit(6));
  set(8, 8, formatBit(7));
  set(7, 8, formatBit(8));
  for (let i = 9; i < 15; i++) set(14 - i, 8, formatBit(i));
  for (let i = 0; i < 8; i++) set(size - 1 - i, 8, formatBit(i));
  for (let i = 8; i < 15; i++) set(8, size - 15 + i, formatBit(i));
  set(8, size - 8, true);
  if (version >= 7) {
    let rem = version;
    for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1f25);
    const value = (version << 12) | rem;
    for (let i = 0; i < 18; i++) {
      const a = size - 11 + (i % 3),
        b = Math.floor(i / 3),
        black = ((value >>> i) & 1) !== 0;
      set(a, b, black);
      set(b, a, black);
    }
  }
  for (let right = size - 1, cursor = 0; right >= 1; right -= 2) {
    if (right === 6) right = 5;
    for (let vertical = 0; vertical < size; vertical++) {
      const y = ((right + 1) & 2) === 0 ? size - 1 - vertical : vertical;
      for (let j = 0; j < 2; j++) {
        const x = right - j;
        if (!reserved[y][x] && cursor < interleaved.length * 8) {
          matrix[y][x] = ((interleaved[cursor >>> 3] >>> (7 - (cursor & 7))) & 1) !== 0;
          cursor++;
        }
      }
    }
  }
  for (let y = 0; y < size; y++)
    for (let x = 0; x < size; x++)
      if (!reserved[y][x] && (x + y) % 2 === 0) matrix[y][x] = !matrix[y][x];
  return matrix;
}
