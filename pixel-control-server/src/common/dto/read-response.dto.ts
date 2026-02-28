/**
 * Common paginated response wrapper for list endpoints.
 */
export interface PaginatedResponse<T> {
  data: T[];
  pagination: {
    total: number;
    limit: number;
    offset: number;
  };
}

/**
 * Builds a PaginatedResponse from a full dataset, applying offset + limit.
 */
export function paginate<T>(
  items: T[],
  limit: number,
  offset: number,
): PaginatedResponse<T> {
  const total = items.length;
  const data = items.slice(offset, offset + limit);
  return { data, pagination: { total, limit, offset } };
}
