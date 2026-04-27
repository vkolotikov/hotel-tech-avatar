import { useEffect } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { initSentry } from './src/sentry';
import { AppNavigator } from './src/navigation/AppNavigator';
import { bootstrapLanguage } from './src/i18n';

// Must run before any component mounts so early errors are captured.
initSentry();

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { staleTime: 30_000, retry: 1 },
  },
});

export default function App() {
  // Apply the user's persisted language choice (or device locale on
  // first run) before any screen renders strings. i18next's init is
  // synchronous; bootstrap is just the persistence read.
  useEffect(() => {
    void bootstrapLanguage();
  }, []);

  return (
    <SafeAreaProvider>
      <QueryClientProvider client={queryClient}>
        <AppNavigator />
        <StatusBar style="light" translucent />
      </QueryClientProvider>
    </SafeAreaProvider>
  );
}
