import { useId, useState, type DragEvent, type ReactNode } from 'react';
type Column<T> = {
  key: keyof T;
  label: string;
  render?: (row: T) => ReactNode;
  sortable?: boolean;
};
export function DataTable<T extends { id: string }>({
  label,
  rows,
  columns,
  empty = 'Нет данных',
}: {
  label: string;
  rows: T[];
  columns: Column<T>[];
  empty?: string;
}) {
  const [sort, setSort] = useState<{ key: keyof T; asc: boolean } | null>(null);
  const sorted = sort
    ? [...rows].sort(
        (a, b) =>
          String(a[sort.key] ?? '').localeCompare(String(b[sort.key] ?? ''), 'ru', {
            numeric: true,
          }) * (sort.asc ? 1 : -1),
      )
    : rows;
  if (!rows.length)
    return (
      <p role="status" className="p-5 text-sm text-(--color-ink-muted)">
        {empty}
      </p>
    );
  return (
    <>
      <div className="hidden overflow-x-auto md:block">
        <table className="w-full border-collapse text-left text-sm">
          <caption className="sr-only">{label}</caption>
          <thead>
            <tr>
              {columns.map((column) => (
                <th
                  key={String(column.key)}
                  scope="col"
                  aria-sort={
                    sort?.key === column.key ? (sort.asc ? 'ascending' : 'descending') : undefined
                  }
                  className="border-b border-(--color-border) p-3 text-left"
                >
                  {column.sortable ? (
                    <button
                      type="button"
                      onClick={() =>
                        setSort({
                          key: column.key,
                          asc: sort?.key === column.key ? !sort.asc : true,
                        })
                      }
                    >
                      {column.label} ↕
                    </button>
                  ) : (
                    column.label
                  )}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {sorted.map((row) => (
              <tr key={row.id}>
                {columns.map((column, index) => (
                  <td
                    key={String(column.key)}
                    className={`border-b border-(--color-border) p-3 ${index === 0 ? 'sticky left-0 bg-(--color-surface)' : ''}`}
                  >
                    {column.render ? column.render(row) : String(row[column.key] ?? '—')}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div aria-label={label} className="space-y-3 md:hidden">
        {sorted.map((row) => (
          <dl key={row.id} className="rounded-xl border border-(--color-border) p-3 text-sm">
            {columns.map((column) => (
              <div key={String(column.key)} className="grid grid-cols-2 gap-2 py-1">
                <dt className="text-(--color-ink-muted)">{column.label}</dt>
                <dd>{column.render ? column.render(row) : String(row[column.key] ?? '—')}</dd>
              </div>
            ))}
          </dl>
        ))}
      </div>
    </>
  );
}
export function FilterBar({
  children,
  label = 'Фильтры',
}: {
  children: ReactNode;
  label?: string;
}) {
  return (
    <div role="group" aria-label={label} className="flex flex-wrap items-end gap-3">
      {children}
    </div>
  );
}
export function FileDrop({
  label,
  onFiles,
  accept,
  multiple = false,
  disabled = false,
}: {
  label: string;
  onFiles: (files: File[]) => void;
  accept?: string;
  multiple?: boolean;
  disabled?: boolean;
}) {
  const id = useId();
  function selectFiles(files: File[]) {
    const types = (accept ?? '')
      .split(',')
      .map((value) => value.trim().toLowerCase())
      .filter(Boolean);
    const allowed = files.filter(
      (file) =>
        !types.length ||
        types.some((type) => {
          if (type.startsWith('.')) return file.name.toLowerCase().endsWith(type);
          if (type.endsWith('/*')) return file.type.toLowerCase().startsWith(type.slice(0, -1));
          return file.type.toLowerCase() === type;
        }),
    );
    onFiles(multiple ? allowed : allowed.slice(0, 1));
  }
  function drop(event: DragEvent<HTMLLabelElement>) {
    event.preventDefault();
    if (!disabled) selectFiles(Array.from(event.dataTransfer.files));
  }
  return (
    <label
      htmlFor={id}
      onDragOver={(event) => event.preventDefault()}
      onDrop={drop}
      className={`block rounded-xl border border-dashed border-(--color-control-border) p-5 text-center text-sm has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-(--color-focus) ${disabled ? 'opacity-60' : 'cursor-pointer'}`}
    >
      <span>{label}</span>
      <input
        id={id}
        type="file"
        className="sr-only"
        accept={accept}
        multiple={multiple}
        disabled={disabled}
        onChange={(event) => {
          selectFiles(Array.from(event.target.files ?? []));
          event.target.value = '';
        }}
      />
    </label>
  );
}
