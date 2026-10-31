import { useHttp } from '@inertiajs/react';
import type { FormDataType, UseHttpSubmitOptions } from '@inertiajs/core';
import { useQueryClient } from '@tanstack/react-query';
import { brandQueryKey, type BrandScope } from '@/lib/query/client';

/** Mutation errors, validation and cancellation remain the responsibility of Inertia. */
export function useBrandHttp<TForm extends FormDataType<TForm>, TResponse = unknown>(
  scope: BrandScope,
  initial: TForm,
) {
  const http = useHttp<TForm, TResponse>(initial);
  const client = useQueryClient();
  const queryKey = brandQueryKey(scope);
  const withInvalidation = (options?: UseHttpSubmitOptions<TResponse, TForm>) => ({
    ...options,
    onSuccess: (
      ...args: Parameters<NonNullable<UseHttpSubmitOptions<TResponse, TForm>['onSuccess']>>
    ) => {
      void client.invalidateQueries({ queryKey });
      options?.onSuccess?.(...args);
    },
  });
  const { submit, ...form } = http;
  void submit; // Mutations must go through the methods below to invalidate the brand cache.
  return {
    ...form,
    post: (url: string, options?: UseHttpSubmitOptions<TResponse, TForm>) =>
      http.post(url, withInvalidation(options)),
    put: (url: string, options?: UseHttpSubmitOptions<TResponse, TForm>) =>
      http.put(url, withInvalidation(options)),
    patch: (url: string, options?: UseHttpSubmitOptions<TResponse, TForm>) =>
      http.patch(url, withInvalidation(options)),
    delete: (url: string, options?: UseHttpSubmitOptions<TResponse, TForm>) =>
      http.delete(url, withInvalidation(options)),
  };
}
