import { useMemo } from 'react';
import { qrMatrix } from './qr';
export function QrCode({ value, label }: { value: string; label: string }) {
  const matrix = useMemo(() => qrMatrix(value), [value]);
  if (!matrix) return null;
  const dimension = matrix.length + 8;
  const path = matrix
    .flatMap((row, y) => row.flatMap((black, x) => (black ? [`M${x + 4},${y + 4}h1v1h-1z`] : [])))
    .join('');
  return (
    <svg
      role="img"
      aria-label={label}
      viewBox={`0 0 ${dimension} ${dimension}`}
      shapeRendering="crispEdges"
      className="mx-auto h-auto w-64 max-w-full rounded-lg bg-white"
      xmlns="http://www.w3.org/2000/svg"
    >
      <rect width={dimension} height={dimension} fill="#fff" />
      <path d={path} fill="#000" />
    </svg>
  );
}
