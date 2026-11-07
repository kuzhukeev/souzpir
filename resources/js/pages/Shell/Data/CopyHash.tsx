import {useState} from 'react';

export default function CopyHash({hash}: {hash: string}) {
  const [status,setStatus] = useState('');
  async function copy() {
    try {
      if (!navigator.clipboard) throw new Error();
      await navigator.clipboard.writeText(hash);
      setStatus('SHA-256 скопирован.');
    } catch {
      setStatus('Не удалось скопировать SHA-256. Скопируйте значение вручную.');
    }
  }
  return <span className="inline-flex flex-wrap items-center gap-2"><button type="button" onClick={copy} className="min-h-10 cursor-pointer rounded-lg border border-(--color-border) px-3 text-sm font-semibold text-(--color-link)">Копировать SHA-256</button><span role="status" className="text-sm">{status}</span></span>;
}
