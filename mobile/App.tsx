import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { initSentry } from './src/sentry';
import { AppNavigator } from './src/navigation/AppNavigator';

// Must run before any component mounts so early errors are captured.
initSentry();

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { staleTime: 30_000, retry: 1 },
  },
});

export default function App() {
  return (
    <SafeAreaProvider>
      <QueryClientProvider client={queryClient}>
        <AppNavigator />
        <StatusBar style="light" translucent />
      </QueryClientProvider>
    </SafeAreaProvider>
  );
}
