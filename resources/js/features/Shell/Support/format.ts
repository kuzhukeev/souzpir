import { supportCopy as c } from './copy';
export function fileSize(bytes: number) {
  return bytes >= 1048576
    ? new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(bytes / 1048576) +
        ' ' +
        c.mb
    : bytes >= 1024
      ? new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(bytes / 1024) +
        ' ' +
        c.kb
      : bytes + ' ' + c.bytes;
}
