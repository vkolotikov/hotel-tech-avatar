import { useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { Ionicons } from '@expo/vector-icons';
import { useTranslation } from 'react-i18next';
import { AuthUser, login, register } from '../api';
import { colors, spacing, radius, fontSize } from '../theme';

type Props = {
  onSignedIn: (user: AuthUser) => void;
};

type Mode = 'signin' | 'signup';

export function SignInScreen({ onSignedIn }: Props) {
  const { t } = useTranslation();
  const [mode, setMode] = useState<Mode>('signin');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);

  const deviceName = `${Platform.OS}-expo`;

  const handleSubmit = async () => {
    const trimmedEmail = email.trim();
    if (!trimmedEmail || !password) {
      Alert.alert(t('common.errorTitle'), `${t('auth.email')} + ${t('auth.password')}.`);
      return;
    }
    if (mode === 'signup' && !name.trim()) {
      Alert.alert(t('common.errorTitle'), t('auth.name'));
      return;
    }
    if (mode === 'signup' && password.length < 8) {
      Alert.alert(t('common.errorTitle'), '8+ ' + t('auth.password'));
      return;
    }

    setLoading(true);
    try {
      const user = mode === 'signin'
        ? await login(trimmedEmail, password, deviceName)
        : await register(name.trim(), trimmedEmail, password, deviceName);
      onSignedIn(user);
    } catch (error) {
      Alert.alert(t('common.errorTitle'), (error as Error).message);
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
      behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
    >
      <ScrollView
        contentContainerStyle={styles.scrollContent}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
      >
      <View style={styles.card}>
        <View style={styles.brandRow}>
          <View style={styles.brandIcon}>
            <Ionicons name="leaf" size={20} color={colors.textPrimary} />
          </View>
          <Text style={styles.brand}>Hexalife</Text>
        </View>
        <Text style={styles.subheading}>
          {mode === 'signin' ? t('auth.signInToContinue') : t('auth.createAccount')}
        </Text>

        <View style={styles.modeTabs}>
          <Pressable
            onPress={() => setMode('signin')}
            style={[styles.modeTab, mode === 'signin' && styles.modeTabActive]}
          >
            <Text style={[styles.modeTabText, mode === 'signin' && styles.modeTabTextActive]}>
              {t('auth.signIn')}
            </Text>
          </Pressable>
          <Pressable
            onPress={() => setMode('signup')}
            style={[styles.modeTab, mode === 'signup' && styles.modeTabActive]}
          >
            <Text style={[styles.modeTabText, mode === 'signup' && styles.modeTabTextActive]}>
              {t('auth.signUp')}
            </Text>
          </Pressable>
        </View>

        {mode === 'signup' && (
          <>
            <Text style={styles.label}>{t('auth.name')}</Text>
            <TextInput
              style={styles.input}
              value={name}
              onChangeText={setName}
              autoCapitalize="words"
              autoComplete="name"
              placeholder={t('auth.name')}
              placeholderTextColor={colors.textMuted}
            />
          </>
        )}

        <Text style={styles.label}>{t('auth.email')}</Text>
        <TextInput
          style={styles.input}
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          autoComplete="email"
          keyboardType="email-address"
          placeholder={t('auth.emailPlaceholder')}
          placeholderTextColor={colors.textMuted}
        />

        <Text style={styles.label}>{t('auth.password')}</Text>
        <TextInput
          style={styles.input}
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          autoComplete={mode === 'signup' ? 'new-password' : 'password'}
          placeholder={mode === 'signup' ? t('auth.passwordHint') : '••••••••'}
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
              {mode === 'signin' ? t('auth.signIn') : t('auth.signUp')}
            </Text>
          )}
        </Pressable>

        <Pressable onPress={toggleMode} style={styles.switchLink}>
          <Text style={styles.switchLinkText}>
            {mode === 'signin' ? t('auth.noAccount') : t('auth.hasAccount')}
          </Text>
        </Pressable>
      </View>
      </ScrollView>
      <StatusBar style="light" />
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background,
  },
  scrollContent: {
    // grow to fill the screen so the card vertically centers when
    // the keyboard isn't open, then the ScrollView naturally scrolls
    // the focused TextInput above the keyboard when it is.
    flexGrow: 1,
    justifyContent: 'center',
    padding: spacing.lg,
    paddingBottom: spacing.xxl,
  },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
  },
  brandRow: {
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: spacing.xs,
  },
  brandIcon: {
    width: 36,
    height: 36,
    borderRadius: radius.pill,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing.sm,
  },
  brand: {
    color: colors.textPrimary,
    fontSize: fontSize.xl,
    fontWeight: '700',
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
