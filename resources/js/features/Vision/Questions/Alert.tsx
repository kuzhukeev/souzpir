
export function Alert({ message }: { message: string }) {
  return message ? (
    <p role="alert" className="rounded-lg bg-red-50 p-4 text-sm text-red-800">
      {message}
    </p>
  ) : null;
}
