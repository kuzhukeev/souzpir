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
  // Inertia's fluent helpers return the unwrapped form and would bypass invalidation.
  // Do not expose them until their returned object is wrapped too.
  const { submit, dontRemember, optimistic, withAllErrors, withPrecognition, ...form } = http;
  void submit;
  void dontRemember;
  void optimistic;
  void withAllErrors;
  void withPrecognition;
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
