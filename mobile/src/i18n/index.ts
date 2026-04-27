import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import * as SecureStore from 'expo-secure-store';

import en from './locales/en.json';
import es from './locales/es.json';
import fr from './locales/fr.json';
import de from './locales/de.json';
import pl from './locales/pl.json';
import it from './locales/it.json';
import ru from './locales/ru.json';
import uk from './locales/uk.json';
import lv from './locales/lv.json';

export const SUPPORTED_LANGUAGES = [
  { code: 'en', name: 'English', native: 'English' },
  { code: 'es', name: 'Spanish', native: 'Español' },
  { code: 'fr', name: 'French',  native: 'Français' },
  { code: 'de', name: 'German',  native: 'Deutsch' },
  { code: 'pl', name: 'Polish',  native: 'Polski' },
  { code: 'it', name: 'Italian', native: 'Italiano' },
  { code: 'ru', name: 'Russian', native: 'Русский' },
  { code: 'uk', name: 'Ukrainian', native: 'Українська' },
  { code: 'lv', name: 'Latvian', native: 'Latviešu' },
] as const;

export type LanguageCode = typeof SUPPORTED_LANGUAGES[number]['code'];

const STORAGE_KEY = 'preferred_language_v1';

/**
 * Detects the device locale via the JS `Intl` API (Hermes-supported, no
 * native module dep). Falls back to English if the user's locale isn't
 * one of our 9 supported languages.
 */
function detectDeviceLanguage(): LanguageCode {
  try {
    const locale = Intl.DateTimeFormat().resolvedOptions().locale ?? 'en';
    const code = (locale.split('-')[0] || 'en').toLowerCase();
    if (SUPPORTED_LANGUAGES.some((l) => l.code === code)) {
      return code as LanguageCode;
    }
  } catch {
    /* Intl missing or failing — fall through */
  }
  return 'en';
}

/**
 * Reads the persisted language preference from SecureStore. Returns null
 * if the user has never picked one (so callers can fall back to device
 * detection).
 */
export async function loadStoredLanguage(): Promise<LanguageCode | null> {
  try {
    const v = await SecureStore.getItemAsync(STORAGE_KEY);
    if (v && SUPPORTED_LANGUAGES.some((l) => l.code === v)) {
      return v as LanguageCode;
    }
  } catch {
    /* ignore — first run, no token yet */
  }
  return null;
}

/**
 * Persists the language choice locally. The same value also lands on
 * the backend via UserProfile.preferred_language so cross-device
 * installs stay consistent — but local cache is the truth on each
 * device until the user reconnects.
 */
export async function persistLanguage(code: LanguageCode): Promise<void> {
  try {
    await SecureStore.setItemAsync(STORAGE_KEY, code);
  } catch {
    /* persistence failures aren't fatal — runtime change still works */
  }
}

i18n
  .use(initReactI18next)
  .init({
    resources: {
      en: { translation: en },
      es: { translation: es },
      fr: { translation: fr },
      de: { translation: de },
      pl: { translation: pl },
      it: { translation: it },
      ru: { translation: ru },
      uk: { translation: uk },
      lv: { translation: lv },
    },
    lng: detectDeviceLanguage(),
    fallbackLng: 'en',
    interpolation: { escapeValue: false },
    react: { useSuspense: false },
  });

/**
 * Switch the active language at runtime + persist. Use whenever the
 * user changes language: profile setup picker, Settings.
 */
export async function setLanguage(code: LanguageCode): Promise<void> {
  await i18n.changeLanguage(code);
  await persistLanguage(code);
}

/**
 * Bootstrap once at app start: prefer stored choice, then device locale.
 */
export async function bootstrapLanguage(): Promise<LanguageCode> {
  const stored = await loadStoredLanguage();
  const initial = stored ?? detectDeviceLanguage();
  if (i18n.language !== initial) {
    await i18n.changeLanguage(initial);
  }
  return initial;
}

export default i18n;
