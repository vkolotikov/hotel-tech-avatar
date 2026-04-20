import { StatusBar } from 'expo-status-bar';
import { useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { AuthUser, login, logout, me, storedToken } from './src/api';

type Status = 'booting' | 'signed_out' | 'signing_in' | 'signed_in';

export default function App() {
  const [status, setStatus] = useState<Status>('booting');
  const [email, setEmail] = useState('test@example.com');
  const [password, setPassword] = useState('password');
  const [user, setUser] = useState<AuthUser | null>(null);

  useEffect(() => {
    (async () => {
      const token = await storedToken();
      if (!token) {
        setStatus('signed_out');
        return;
      }
      try {
        const current = await me();
        setUser(current);
        setStatus('signed_in');
      } catch {
        setStatus('signed_out');
      }
    })();
  }, []);

  const handleSignIn = async () => {
    setStatus('signing_in');
    try {
      const signedIn = await login(email, password, `${Platform.OS}-expo`);
      setUser(signedIn);
      setStatus('signed_in');
    } catch (error) {
      setStatus('signed_out');
      Alert.alert('Sign in failed', (error as Error).message);
    }
  };

  const handleSignOut = async () => {
    await logout();
    setUser(null);
    setStatus('signed_out');
  };

  if (status === 'booting') {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color="#7c5cff" />
        <StatusBar style="light" />
      </View>
    );
  }

  if (status === 'signed_in' && user) {
    return (
      <View style={styles.centered}>
        <Text style={styles.brand}>WellnessAI</Text>
        <Text style={styles.heading}>Signed in</Text>
        <Text style={styles.body}>{user.name}</Text>
        <Text style={styles.body}>{user.email}</Text>
        <Pressable style={styles.buttonSecondary} onPress={handleSignOut}>
          <Text style={styles.buttonSecondaryText}>Sign out</Text>
        </Pressable>
        <StatusBar style="light" />
      </View>
    );
  }

  return (
    <KeyboardAvoidingView
      style={styles.container}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
    >
      <View style={styles.card}>
        <Text style={styles.brand}>WellnessAI</Text>
        <Text style={styles.subheading}>Phase 0 sign-in</Text>

        <Text style={styles.label}>Email</Text>
        <TextInput
          style={styles.input}
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          autoComplete="email"
          keyboardType="email-address"
          placeholder="you@example.com"
          placeholderTextColor="#6b7280"
        />

        <Text style={styles.label}>Password</Text>
        <TextInput
          style={styles.input}
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          placeholder="••••••••"
          placeholderTextColor="#6b7280"
        />

        <Pressable
          style={[styles.button, status === 'signing_in' && styles.buttonDisabled]}
          onPress={handleSignIn}
          disabled={status === 'signing_in'}
        >
          {status === 'signing_in' ? (
            <ActivityIndicator color="#fff" />
          ) : (
            <Text style={styles.buttonText}>Sign in</Text>
          )}
        </Pressable>
      </View>
      <StatusBar style="light" />
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#0b0f17',
    justifyContent: 'center',
    padding: 24,
  },
  centered: {
    flex: 1,
    backgroundColor: '#0b0f17',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 24,
  },
  card: {
    backgroundColor: '#141a26',
    borderRadius: 16,
    padding: 24,
  },
  brand: {
    color: '#ffffff',
    fontSize: 28,
    fontWeight: '700',
    marginBottom: 4,
  },
  subheading: {
    color: '#9ca3af',
    fontSize: 14,
    marginBottom: 24,
  },
  heading: {
    color: '#ffffff',
    fontSize: 20,
    fontWeight: '600',
    marginTop: 16,
    marginBottom: 8,
  },
  body: {
    color: '#d1d5db',
    fontSize: 16,
    marginBottom: 4,
  },
  label: {
    color: '#9ca3af',
    fontSize: 12,
    marginBottom: 6,
    marginTop: 12,
  },
  input: {
    backgroundColor: '#1f2937',
    color: '#ffffff',
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 16,
  },
  button: {
    backgroundColor: '#7c5cff',
    paddingVertical: 14,
    borderRadius: 10,
    alignItems: 'center',
    marginTop: 24,
  },
  buttonDisabled: {
    opacity: 0.7,
  },
  buttonText: {
    color: '#ffffff',
    fontSize: 16,
    fontWeight: '600',
  },
  buttonSecondary: {
    marginTop: 24,
    paddingVertical: 12,
    paddingHorizontal: 20,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: '#374151',
  },
  buttonSecondaryText: {
    color: '#d1d5db',
    fontSize: 14,
  },
});
