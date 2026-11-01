const ulid = /^[0-9A-HJKMNP-TV-Z]{26}$/i;

export function validMembershipIds(ids: string[]) {
  return ids.length <= 25 && new Set(ids).size === ids.length && ids.every((id) => ulid.test(id));
}

export function parseLastLogins(
  value: unknown,
  ids: string[],
  revision: string,
): Record<string, string | null> {
  if (!value || typeof value !== 'object') throw new Error('Invalid response');
  const payload = value as Record<string, unknown>;
  if (
    payload.revision !== revision ||
    payload.available !== true ||
    !Array.isArray(payload.members) ||
    payload.members.length !== ids.length
  )
    throw new Error('Invalid response');
  const expected = new Set(ids);
  const result: Record<string, string | null> = {};
  for (const item of payload.members) {
    if (!item || typeof item !== 'object') throw new Error('Invalid response');
    const row = item as Record<string, unknown>;
    if (
      typeof row.id !== 'string' ||
      !expected.delete(row.id) ||
      (row.lastLoginAt !== null &&
        (typeof row.lastLoginAt !== 'string' || !Number.isFinite(Date.parse(row.lastLoginAt))))
    )
      throw new Error('Invalid response');
    result[row.id] = row.lastLoginAt as string | null;
  }
  if (expected.size) throw new Error('Invalid response');
  return result;
}
