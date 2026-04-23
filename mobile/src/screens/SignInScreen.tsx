import { useState } from 'react';
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
import { StatusBar } from 'expo-status-bar';
import { AuthUser, login, register } from '../api';
import { colors, spacing, radius, fontSize } from '../theme';

type Props = {
  onSignedIn: (user: AuthUser) => void;
};

type Mode = 'signin' | 'signup';

export function SignInScreen({ onSignedIn }: Props) {
  const [mode, setMode] = useState<Mode>('signin');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);

  const deviceName = `${Platform.OS}-expo`;

  const handleSubmit = async () => {
    const trimmedEmail = email.trim();
    if (!trimmedEmail || !password) {
      Alert.alert('Missing info', 'Email and password are required.');
      return;
    }
    if (mode === 'signup' && !name.trim()) {
      Alert.alert('Missing info', 'Please enter your name.');
      return;
    }
    if (mode === 'signup' && password.length < 8) {
      Alert.alert('Password too short', 'Pick at least 8 characters.');
      return;
    }

    setLoading(true);
    try {
      const user = mode === 'signin'
        ? await login(trimmedEmail, password, deviceName)
        : await register(name.trim(), trimmedEmail, password, deviceName);
      onSignedIn(user);
    } catch (error) {
      const label = mode === 'signin' ? 'Sign in failed' : 'Sign up failed';
      Alert.alert(label, (error as Error).message);
    } finally {
      setLoading(false);
    }
  };

  const toggleMode = () => {
    setMode(mode === 'signin' ? 'signup' : 'signin');
  };

  return (
    <KeyboardAvoidingView
      style={styles.container}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
    >
      <View style={styles.card}>
        <Text style={styles.brand}>WellnessAI</Text>
        <Text style={styles.subheading}>
          {mode === 'signin' ? 'Sign in to continue' : 'Create your account'}
        </Text>

        <View style={styles.modeTabs}>
          <Pressable
            onPress={() => setMode('signin')}
            style={[styles.modeTab, mode === 'signin' && styles.modeTabActive]}
          >
            <Text style={[styles.modeTabText, mode === 'signin' && styles.modeTabTextActive]}>
              Sign in
            </Text>
          </Pressable>
          <Pressable
            onPress={() => setMode('signup')}
            style={[styles.modeTab, mode === 'signup' && styles.modeTabActive]}
          >
            <Text style={[styles.modeTabText, mode === 'signup' && styles.modeTabTextActive]}>
              Sign up
            </Text>
          </Pressable>
        </View>

        {mode === 'signup' && (
          <>
            <Text style={styles.label}>Name</Text>
            <TextInput
              style={styles.input}
              value={name}
              onChangeText={setName}
              autoCapitalize="words"
              autoComplete="name"
              placeholder="Your name"
              placeholderTextColor={colors.textMuted}
            />
          </>
        )}

        <Text style={styles.label}>Email</Text>
        <TextInput
          style={styles.input}
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          autoComplete="email"
          keyboardType="email-address"
          placeholder="you@example.com"
          placeholderTextColor={colors.textMuted}
        />

        <Text style={styles.label}>Password</Text>
        <TextInput
          style={styles.input}
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          autoComplete={mode === 'signup' ? 'new-password' : 'password'}
          placeholder={mode === 'signup' ? 'At least 8 characters' : '••••••••'}
          placeholderTextColor={colors.textMuted}
        />

        <Pressable
          style={[styles.button, loading && styles.buttonDisabled]}
          onPress={handleSubmit}
          disabled={loading}
        >
          {loading ? (
            <ActivityIndicator color={colors.textPrimary} />
          ) : (
            <Text style={styles.buttonText}>
              {mode === 'signin' ? 'Sign in' : 'Create account'}
            </Text>
          )}
        </Pressable>

        <Pressable onPress={toggleMode} style={styles.switchLink}>
          <Text style={styles.switchLinkText}>
            {mode === 'signin'
              ? 'New here? Create an account'
              : 'Already have an account? Sign in'}
          </Text>
        </Pressable>
      </View>
      <StatusBar style="light" />
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background,
    justifyContent: 'center',
    padding: spacing.lg,
  },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
  },
  brand: {
    color: colors.textPrimary,
    fontSize: fontSize.xl,
    fontWeight: '700',
    marginBottom: spacing.xs,
  },
  subheading: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    marginBottom: spacing.lg,
  },
  modeTabs: {
    flexDirection: 'row',
    backgroundColor: colors.surfaceElevated,
    borderRadius: radius.pill,
    padding: 4,
    marginBottom: spacing.md,
  },
  modeTab: {
    flex: 1,
    paddingVertical: spacing.sm,
    alignItems: 'center',
    borderRadius: radius.pill,
  },
  modeTabActive: {
    backgroundColor: colors.primary,
  },
  modeTabText: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    fontWeight: '600',
  },
  modeTabTextActive: {
    color: colors.textPrimary,
  },
  label: {
    color: colors.textMuted,
    fontSize: fontSize.xs,
    marginBottom: spacing.xs,
    marginTop: spacing.sm,
  },
  input: {
    backgroundColor: colors.surfaceElevated,
    color: colors.textPrimary,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    fontSize: fontSize.md,
  },
  button: {
    backgroundColor: colors.primary,
    paddingVertical: 14,
    borderRadius: radius.md,
    alignItems: 'center',
    marginTop: spacing.lg,
  },
  buttonDisabled: { opacity: 0.7 },
  buttonText: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '600',
  },
  switchLink: {
    marginTop: spacing.md,
    alignItems: 'center',
  },
  switchLinkText: {
    color: colors.textSecondary,
    fontSize: fontSize.sm,
  },
});
