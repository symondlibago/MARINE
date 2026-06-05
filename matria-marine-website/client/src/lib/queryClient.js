import { QueryClient } from '@tanstack/react-query';

// Single shared client for the app. The marketing pages don't use react-query;
// the staff portal and the vendor quote page do.
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      refetchOnWindowFocus: false,
      staleTime: 30_000,
    },
  },
});
