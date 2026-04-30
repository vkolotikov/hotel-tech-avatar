import { useEffect, useMemo, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { StatusBar } from 'expo-status-bar';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useTranslation } from 'react-i18next';
import { setLanguage, SUPPORTED_LANGUAGES, type LanguageCode } from '../i18n';
import {
  fetchProfile,
  updateProfile,
  type UserProfile,
  type AgeBand,
  type SexAtBirth,
  type ActivityLevel,
  type JobType,
  type TimeBand,
  type SleepQuality,
  type Chronotype,
  type SmokingStatus,
  type AlcoholFreq,
  type CaffeineFreq,
  type StressLevel,
  type EatingPattern,
  type EatingSchedule,
  type LivingSituation,
  type TravelFrequency,
  type FemaleStatus,
  type Contraception,
  type GoalTimeline,
} from '../api/profile';
import { colors, spacing, radius, fontSize } from '../theme';

type Mode = 'setup' | 'edit';

type Props = {
  visible: boolean;
  mode: Mode;
  onFinish: () => void;
  onClose?: () => void;
};

type StepKey =
  | 'language'
  | 'welcome'
  | 'about'
  | 'heritage'
  | 'body'
  | 'day'
  | 'sleep'
  | 'habits'
  | 'eating'
  | 'health'
  | 'life'
  | 'female'
  | 'goals'
  | 'review';

// Onboarding flow shrunk on 2026-04-28: cooking/motivation/coaching slides
// dropped per product feedback that they made the funnel feel longer
// without driving meaningfully better avatar replies. The fields stay
// in the DB schema (column drops would force everyone to re-onboard
// for nothing) — the SystemPromptBuilder just renders empty when these
// are null, which is the existing graceful-degradation path.
const ALL_STEPS_SETUP: StepKey[] = [
  'language',
  'welcome',
  'about',
  'heritage',
  'body',
  'day',
  'sleep',
  'habits',
  'eating',
  'health',
  'life',
  'female',
  'goals',
  'review',
];

const ALL_STEPS_EDIT: StepKey[] = [
  'language',
  'about',
  'heritage',
  'body',
  'day',
  'sleep',
  'habits',
  'eating',
  'health',
  'life',
  'female',
  'goals',
];

// ─── Option catalogues — single source of truth ──────────────────────────
//
// Built as factories that take the i18n `t` function so the chip labels
// pick up the active language. Static structure (value, icon, emoji)
// stays the same across languages; only the label/sub text varies.
//
// Adding a new language is purely a JSON file change — these factories
// reference the existing locale keys (sex.*, ageBand.*, activity.*, …).

type IoniconName = keyof typeof Ionicons.glyphMap;

type Option<T> = { value: T; label: string; icon?: IoniconName; emoji?: string; sub?: string };

type T = (key: string) => string;

const sexOptions = (t: T): Option<NonNullable<SexAtBirth>>[] => [
  { value: 'F', label: t('profileSetup.sexFemale'),   icon: 'female-outline' },
  { value: 'M', label: t('profileSetup.sexMale'),     icon: 'male-outline' },
  { value: 'I', label: t('profileSetup.sexIntersex'), icon: 'transgender-outline' },
];

const PRONOUN_OPTIONS = ['she/her', 'he/him', 'they/them', 'ze/zir', 'other'];

const ageOptions = (t: T): Option<NonNullable<AgeBand>>[] => [
  { value: 'under-18', label: t('ageBand.under-18') },
  { value: '18-24',    label: t('ageBand.18-24') },
  { value: '25-34',    label: t('ageBand.25-34') },
  { value: '35-44',    label: t('ageBand.35-44') },
  { value: '45-54',    label: t('ageBand.45-54') },
  { value: '55-64',    label: t('ageBand.55-64') },
  { value: '65+',      label: t('ageBand.65+') },
];

// Ethnicity, allergies, conditions etc. are kept as label-keyed string
// arrays because the *value stored in the DB* IS the label — changing
// the label would orphan existing rows. To localise, MultiChip looks
// up `multichip.{section}.{key}` if present and falls back to the raw
// label so future languages can be added without breaking storage.
// The keys below match `allergy.*`, `intolerance.*`, etc. in en.json.
const ETHNICITY_OPTIONS = [
  'White / European',
  'Black / African',
  'East Asian',
  'South Asian',
  'Hispanic / Latino',
  'Middle Eastern',
  'Indigenous',
  'Mixed',
  'Other',
  'Prefer not to say',
];

const ETHNICITY_KEYS: Record<string, string> = {
  'White / European':    'ethnicity.white',
  'Black / African':     'ethnicity.black',
  'East Asian':          'ethnicity.east-asian',
  'South Asian':         'ethnicity.south-asian',
  'Hispanic / Latino':   'ethnicity.hispanic',
  'Middle Eastern':      'ethnicity.middle-eastern',
  'Indigenous':          'ethnicity.indigenous',
  'Mixed':               'ethnicity.mixed',
  'Other':               'ethnicity.other',
  'Prefer not to say':   'common.preferNotToSay',
};

const activityOptions = (t: T): Option<NonNullable<ActivityLevel>>[] => [
  { value: 'sedentary', label: t('activity.sedentary'), icon: 'bed-outline',     sub: t('activity.sedentarySub') },
  { value: 'light',     label: t('activity.light'),     icon: 'walk-outline',    sub: t('activity.lightSub') },
  { value: 'moderate',  label: t('activity.moderate'),  icon: 'bicycle-outline', sub: t('activity.moderateSub') },
  { value: 'active',    label: t('activity.active'),    icon: 'barbell-outline', sub: t('activity.activeSub') },
  { value: 'athlete',   label: t('activity.athlete'),   icon: 'trophy-outline',  sub: t('activity.athleteSub') },
];

const jobOptions = (t: T): Option<NonNullable<JobType>>[] => [
  { value: 'desk',     label: t('jobType.desk'),     icon: 'laptop-outline' },
  { value: 'mixed',    label: t('jobType.mixed'),    icon: 'shuffle' },
  { value: 'feet',     label: t('jobType.feet'),     icon: 'walk' },
  { value: 'physical', label: t('jobType.physical'), icon: 'construct-outline' },
  { value: 'shift',    label: t('jobType.shift'),    icon: 'moon-outline' },
  { value: 'none',     label: t('jobType.none'),     icon: 'home-outline' },
];

const timeBandOutdoor = (t: T): Option<NonNullable<TimeBand>>[] => [
  { value: 'under-15', label: t('timeBand.under-15'), icon: 'cloud-outline' },
  { value: '15-60',    label: t('timeBand.15-60'),    icon: 'partly-sunny-outline' },
  { value: '60-120',   label: t('timeBand.60-120'),   icon: 'sunny-outline' },
  { value: '120+',     label: t('timeBand.120+'),     icon: 'sunny' },
];

const timeBandWellness = (t: T): Option<NonNullable<TimeBand>>[] => [
  { value: 'under-15', label: t('timeBand.under-15'), icon: 'time-outline' },
  { value: '15-30',    label: t('timeBand.15-30'),    icon: 'time-outline' },
  { value: '30-60',    label: t('timeBand.30-60'),    icon: 'timer-outline' },
  { value: '60+',      label: t('timeBand.60+'),      icon: 'timer' },
];

const sleepQualityOptions = (t: T): Option<NonNullable<SleepQuality>>[] => [
  { value: 'great', label: t('sleepQuality.great'), emoji: '😴' },
  { value: 'okay',  label: t('sleepQuality.okay'),  emoji: '😐' },
  { value: 'poor',  label: t('sleepQuality.poor'),  emoji: '😩' },
];

const chronotypeOptions = (t: T): Option<NonNullable<Chronotype>>[] => [
  { value: 'morning', label: t('chronotype.morning'), icon: 'sunny-outline', sub: t('chronotype.morningSub') },
  { value: 'night',   label: t('chronotype.night'),   icon: 'moon-outline',  sub: t('chronotype.nightSub') },
  { value: 'shift',   label: t('chronotype.shift'),   icon: 'sync-outline',  sub: t('chronotype.shiftSub') },
];

const smokingOptions = (t: T): Option<NonNullable<SmokingStatus>>[] => [
  { value: 'never',      label: t('smoking.never'),      icon: 'close-circle-outline' },
  { value: 'quit',       label: t('smoking.quit'),       icon: 'checkmark-done-circle-outline' },
  { value: 'occasional', label: t('smoking.occasional'), icon: 'time-outline' },
  { value: 'daily',      label: t('smoking.daily'),      icon: 'flame-outline' },
];

const alcoholOptions = (t: T): Option<NonNullable<AlcoholFreq>>[] => [
  { value: 'none',     label: t('alcohol.none'),     icon: 'close-circle-outline' },
  { value: 'light',    label: t('alcohol.light'),    icon: 'wine-outline' },
  { value: 'moderate', label: t('alcohol.moderate'), icon: 'wine' },
  { value: 'heavy',    label: t('alcohol.heavy'),    icon: 'beer-outline' },
];

const caffeineOptions = (t: T): Option<NonNullable<CaffeineFreq>>[] => [
  { value: 'none', label: t('caffeine.none'), icon: 'close-circle-outline' },
  { value: '1-2',  label: t('caffeine.1-2'),  icon: 'cafe-outline' },
  { value: '3-4',  label: t('caffeine.3-4'),  icon: 'cafe' },
  { value: '5+',   label: t('caffeine.5+'),   icon: 'flash' },
];

const stressOptions = (t: T): Option<NonNullable<StressLevel>>[] => [
  { value: 'low',    label: t('stress.low'),    icon: 'happy-outline' },
  { value: 'medium', label: t('stress.medium'), icon: 'remove-circle-outline' },
  { value: 'high',   label: t('stress.high'),   icon: 'alert-circle' },
];

const eatingPatternOptions = (t: T): Option<NonNullable<EatingPattern>>[] => [
  { value: 'omnivore',      label: t('eatingPattern.omnivore'),      icon: 'restaurant-outline' },
  { value: 'pescatarian',   label: t('eatingPattern.pescatarian'),   icon: 'fish-outline' },
  { value: 'vegetarian',    label: t('eatingPattern.vegetarian'),    icon: 'leaf-outline' },
  { value: 'vegan',         label: t('eatingPattern.vegan'),         icon: 'leaf' },
  { value: 'mediterranean', label: t('eatingPattern.mediterranean'), icon: 'sunny-outline' },
  { value: 'keto',          label: t('eatingPattern.keto'),          icon: 'flame-outline' },
  { value: 'paleo',         label: t('eatingPattern.paleo'),         icon: 'pizza-outline' },
  { value: 'no-specific',   label: t('eatingPattern.no-specific'),   icon: 'help-outline' },
];

const eatingScheduleOptions = (t: T): Option<NonNullable<EatingSchedule>>[] => [
  { value: '3-meals',        label: t('eatingSchedule.3-meals'),        icon: 'list-outline' },
  { value: '2-meals',        label: t('eatingSchedule.2-meals'),        icon: 'remove-outline' },
  { value: 'if',             label: t('eatingSchedule.if'),             icon: 'time-outline' },
  { value: 'snacky',         label: t('eatingSchedule.snacky'),         icon: 'apps-outline' },
  { value: 'skip-breakfast', label: t('eatingSchedule.skip-breakfast'), icon: 'cafe-outline' },
];

const ALLERGY_OPTIONS = [
  'Peanuts', 'Tree nuts', 'Dairy', 'Eggs', 'Gluten',
  'Shellfish', 'Soy', 'Sesame', 'Fish', 'None',
];
const ALLERGY_KEYS: Record<string, string> = {
  'Peanuts': 'allergy.peanuts',
  'Tree nuts': 'allergy.tree-nuts',
  'Dairy': 'allergy.dairy',
  'Eggs': 'allergy.eggs',
  'Gluten': 'allergy.gluten',
  'Shellfish': 'allergy.shellfish',
  'Soy': 'allergy.soy',
  'Sesame': 'allergy.sesame',
  'Fish': 'allergy.fish',
  'None': 'allergy.none',
};

const INTOLERANCE_OPTIONS = [
  'Lactose', 'Fructose', 'FODMAP-sensitive', 'Gluten (non-coeliac)',
  'Histamine', 'Caffeine', 'None',
];
const INTOLERANCE_KEYS: Record<string, string> = {
  'Lactose': 'intolerance.lactose',
  'Fructose': 'intolerance.fructose',
  'FODMAP-sensitive': 'intolerance.fodmap',
  'Gluten (non-coeliac)': 'intolerance.gluten-non-coeliac',
  'Histamine': 'intolerance.histamine',
  'Caffeine': 'intolerance.caffeine',
  'None': 'intolerance.none',
};

const CONDITION_OPTIONS = [
  'Type 2 diabetes', 'Type 1 diabetes', 'Prediabetes',
  'High blood pressure', 'High cholesterol', 'Heart disease',
  'Asthma', 'IBS / IBD', 'Autoimmune', 'Anxiety', 'Depression',
  'ADHD', 'Thyroid', 'Endometriosis', 'PCOS',
];
const CONDITION_KEYS: Record<string, string> = {
  'Type 2 diabetes':     'condition.t2d',
  'Type 1 diabetes':     'condition.t1d',
  'Prediabetes':         'condition.prediabetes',
  'High blood pressure': 'condition.hbp',
  'High cholesterol':    'condition.hcl',
  'Heart disease':       'condition.heart',
  'Asthma':              'condition.asthma',
  'IBS / IBD':           'condition.ibs',
  'Autoimmune':          'condition.autoimmune',
  'Anxiety':             'condition.anxiety',
  'Depression':          'condition.depression',
  'ADHD':                'condition.adhd',
  'Thyroid':             'condition.thyroid',
  'Endometriosis':       'condition.endometriosis',
  'PCOS':                'condition.pcos',
};

const COMMON_MEDS = [
  'Statin', 'Metformin', 'SSRI', 'Beta-blocker',
  'Birth control', 'Levothyroxine', 'PPI / acid reducer',
];

const FAMILY_HISTORY_OPTIONS = [
  'Heart disease', 'Type 2 diabetes', 'Cancer',
  'Dementia', 'Stroke', 'Mental health', 'None',
];
const FAMILY_HISTORY_KEYS: Record<string, string> = {
  'Heart disease':    'familyHistory.heart',
  'Type 2 diabetes':  'familyHistory.t2d',
  'Cancer':           'familyHistory.cancer',
  'Dementia':         'familyHistory.dementia',
  'Stroke':           'familyHistory.stroke',
  'Mental health':    'familyHistory.mental',
  'None':             'familyHistory.none',
};

const INJURY_OPTIONS = [
  'Lower back', 'Knee', 'Shoulder', 'Hip',
  'Neck', 'Ankle', 'Wrist', 'None',
];
const INJURY_KEYS: Record<string, string> = {
  'Lower back': 'injury.lower-back',
  'Knee':       'injury.knee',
  'Shoulder':   'injury.shoulder',
  'Hip':        'injury.hip',
  'Neck':       'injury.neck',
  'Ankle':      'injury.ankle',
  'Wrist':      'injury.wrist',
  'None':       'injury.none',
};

const MENTAL_HEALTH_OPTIONS = [
  'Anxiety', 'Depression', 'ADHD',
  'Eating disorder', 'OCD', 'Prefer not to say',
];
const MENTAL_HEALTH_KEYS: Record<string, string> = {
  'Anxiety':            'mentalHealth.anxiety',
  'Depression':         'mentalHealth.depression',
  'ADHD':               'mentalHealth.adhd',
  'Eating disorder':    'mentalHealth.eating-disorder',
  'OCD':                'mentalHealth.ocd',
  'Prefer not to say':  'common.preferNotToSay',
};

const livingOptions = (t: T): Option<NonNullable<LivingSituation>>[] => [
  { value: 'alone',       label: t('living.alone'),       icon: 'person-outline' },
  { value: 'partner',     label: t('living.partner'),     icon: 'heart-outline' },
  { value: 'family-kids', label: t('living.family-kids'), icon: 'people-outline' },
  { value: 'parents',     label: t('living.parents'),     icon: 'home-outline' },
  { value: 'roommates',   label: t('living.roommates'),   icon: 'people-circle-outline' },
];

const travelOptions = (t: T): Option<NonNullable<TravelFrequency>>[] => [
  { value: 'rarely',  label: t('travel.rarely'),  icon: 'home-outline' },
  { value: 'monthly', label: t('travel.monthly'), icon: 'airplane-outline' },
  { value: 'weekly',  label: t('travel.weekly'),  icon: 'airplane' },
];

const femaleStatusOptions = (t: T): Option<NonNullable<FemaleStatus>>[] => [
  { value: 'regular',           label: t('femaleStatus.regular'),         icon: 'refresh-circle-outline' },
  { value: 'irregular',         label: t('femaleStatus.irregular'),       icon: 'pulse-outline' },
  { value: 'trying',            label: t('femaleStatus.trying'),          icon: 'heart-circle-outline' },
  { value: 'pregnant',          label: t('femaleStatus.pregnant'),        icon: 'female' },
  { value: 'breastfeeding',     label: t('femaleStatus.breastfeeding'),   icon: 'water-outline' },
  { value: 'perimenopause',     label: t('femaleStatus.perimenopause'),   icon: 'thermometer-outline' },
  { value: 'menopause',         label: t('femaleStatus.menopause'),       icon: 'sunny-outline' },
  { value: 'post-menopause',    label: t('femaleStatus.post-menopause'),  icon: 'sparkles-outline' },
  { value: 'prefer-not-to-say', label: t('common.preferNotToSay'),        icon: 'eye-off-outline' },
];

const contraceptionOptions = (t: T): Option<NonNullable<Contraception>>[] => [
  { value: 'none',              label: t('contraception.none') },
  { value: 'pill',              label: t('contraception.pill') },
  { value: 'iud-hormonal',      label: t('contraception.iud-hormonal') },
  { value: 'iud-copper',        label: t('contraception.iud-copper') },
  { value: 'implant',           label: t('contraception.implant') },
  { value: 'patch',             label: t('contraception.patch') },
  { value: 'ring',              label: t('contraception.ring') },
  { value: 'injection',         label: t('contraception.injection') },
  { value: 'natural',           label: t('contraception.natural') },
  { value: 'prefer-not-to-say', label: t('common.preferNotToSay') },
];

type GoalKey =
  | 'fitness' | 'weight' | 'energy' | 'sleep' | 'stress'
  | 'nutrition' | 'gut' | 'skin' | 'longevity' | 'labs';

type GoalDef = {
  key: GoalKey;
  label: string;
  icon: IoniconName;
  avatars: string[]; // names shown when selected
};

const goalOptions = (t: T): GoalDef[] => [
  { key: 'fitness',   label: t('goal.fitness'),   icon: 'barbell-outline',    avatars: ['Axel'] },
  { key: 'weight',    label: t('goal.weight'),    icon: 'fitness-outline',    avatars: ['Nora', 'Axel'] },
  { key: 'energy',    label: t('goal.energy'),    icon: 'flash-outline',      avatars: ['Nora', 'Luna'] },
  { key: 'sleep',     label: t('goal.sleep'),     icon: 'moon-outline',       avatars: ['Luna'] },
  { key: 'stress',    label: t('goal.stress'),    icon: 'leaf-outline',       avatars: ['Zen'] },
  { key: 'nutrition', label: t('goal.nutrition'), icon: 'restaurant-outline', avatars: ['Nora'] },
  { key: 'gut',       label: t('goal.gut'),       icon: 'medkit-outline',     avatars: ['Nora'] },
  { key: 'skin',      label: t('goal.skin'),      icon: 'sparkles-outline',   avatars: ['Aura'] },
  { key: 'longevity', label: t('goal.longevity'), icon: 'infinite-outline',   avatars: ['Dr. Integra', 'Axel'] },
  { key: 'labs',      label: t('goal.labs'),      icon: 'flask-outline',      avatars: ['Dr. Integra'] },
];

const timelineOptions = (t: T): Option<NonNullable<GoalTimeline>>[] => [
  { value: 'weeks',       label: t('timeline.weeks'),       icon: 'flash-outline' },
  { value: 'months',      label: t('timeline.months'),      icon: 'calendar-outline' },
  { value: 'year',        label: t('timeline.year'),        icon: 'calendar' },
  { value: 'no-deadline', label: t('timeline.no-deadline'), icon: 'infinite-outline' },
];

// ─── Helpers ─────────────────────────────────────────────────────────────

function clamp(n: number, lo: number, hi: number): number {
  return Math.max(lo, Math.min(hi, n));
}

// Female-health slide is conditional — only when sex_at_birth=F and the
// user is in reproductive age. We exclude `under-18` because that
// range straddles 12-17 and our content was authored for adults; we
// also exclude 55+ because most users in those bands are post-
// menopausal and the cycle / pregnancy questions are noise. Users in
// excluded bands can still set female_status manually from Settings →
// Edit profile if they want.
function femaleSlideRelevant(profile: Partial<UserProfile>): boolean {
  if (profile.sex_at_birth !== 'F') return false;
  const band = profile.age_band ?? null;
  if (!band) return true; // unknown — show, user can skip
  return band === '18-24' || band === '25-34' || band === '35-44' || band === '45-54';
}

// ─── Reusable UI primitives ──────────────────────────────────────────────

function StepHero({ icon, tint, title, subtitle }: {
  icon: IoniconName;
  tint: string;
  title: string;
  subtitle?: string;
}) {
  return (
    <View style={heroStyles.wrap}>
      <View style={[heroStyles.iconCircle, { backgroundColor: tint + '22', borderColor: tint }]}>
        <Ionicons name={icon} size={30} color={tint} />
      </View>
      <Text style={heroStyles.title}>{title}</Text>
      {subtitle ? <Text style={heroStyles.subtitle}>{subtitle}</Text> : null}
    </View>
  );
}

function FieldLabel({ children, optional }: { children: string; optional?: boolean }) {
  const { t } = useTranslation();
  return (
    <Text style={fieldStyles.label}>
      {children}
      {optional ? <Text style={fieldStyles.optional}>  {t('common.optional')}</Text> : null}
    </Text>
  );
}

/**
 * Single-select chips. Each chip can have an icon + label + optional
 * sub-line. Auto-flows in a 2-column grid; long-label chips break to
 * full width with the wrap parameter set to 'auto'.
 */
function ChipChoice<T extends string>({
  options, value, onChange, columns = 2,
}: {
  options: Option<T>[];
  value: T | null | undefined;
  onChange: (next: T) => void;
  columns?: 1 | 2 | 3;
}) {
  return (
    <View style={[chipStyles.grid, columns === 1 && { flexDirection: 'column' }]}>
      {options.map((opt) => {
        const selected = value === opt.value;
        return (
          <Pressable
            key={String(opt.value)}
            onPress={() => onChange(opt.value)}
            style={({ pressed }) => [
              chipStyles.card,
              columns === 2 && chipStyles.cardHalf,
              columns === 3 && chipStyles.cardThird,
              columns === 1 && chipStyles.cardFull,
              selected && chipStyles.cardSelected,
              pressed && { opacity: 0.85 },
            ]}
          >
            {opt.emoji ? (
              <Text style={chipStyles.emoji}>{opt.emoji}</Text>
            ) : opt.icon ? (
              <Ionicons name={opt.icon} size={22} color={selected ? colors.primary : colors.textPrimary} />
            ) : null}
            <Text style={[chipStyles.label, selected && chipStyles.labelSelected]}>{opt.label}</Text>
            {opt.sub ? <Text style={chipStyles.sub}>{opt.sub}</Text> : null}
          </Pressable>
        );
      })}
    </View>
  );
}

/**
 * Multi-select chip strip. The `options` are stored as the canonical
 * English label (which is also the value persisted to the DB), while
 * `keys` is an optional lookup table mapping each option to a
 * translation key. When provided, the chip renders the localised
 * string; without `keys`, the raw label shows.
 *
 * Storing the English label as the value keeps existing rows working
 * — changing it would orphan thousands of profiles. The translation
 * lookup happens at render time, never at write time.
 */
function MultiChip({ options, values, onChange, keys }: {
  options: string[];
  values: string[];
  onChange: (next: string[]) => void;
  keys?: Record<string, string>;
}) {
  const { t } = useTranslation();
  const toggle = (option: string) => {
    if (values.includes(option)) {
      onChange(values.filter((v) => v !== option));
    } else {
      // "None" is exclusive — selecting it clears the others.
      if (option === 'None') {
        onChange(['None']);
      } else {
        onChange([...values.filter((v) => v !== 'None'), option]);
      }
    }
  };
  const display = (option: string): string => {
    const key = keys?.[option];
    if (!key) return option;
    const translated = t(key);
    // i18next returns the key when no translation is found — fall
    // back to the English label so the chip never shows raw key text.
    return translated === key ? option : translated;
  };
  return (
    <View style={multiStyles.wrap}>
      {options.map((option) => {
        const selected = values.includes(option);
        return (
          <Pressable
            key={option}
            onPress={() => toggle(option)}
            style={({ pressed }) => [
              multiStyles.chip,
              selected && multiStyles.chipSelected,
              pressed && { opacity: 0.85 },
            ]}
          >
            {selected ? (
              <Ionicons name="checkmark" size={14} color={colors.primary} />
            ) : null}
            <Text style={[multiStyles.chipText, selected && multiStyles.chipTextSelected]}>
              {display(option)}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

/**
 * Numeric stepper with -5/-1/+1/+5 buttons and an optional clear button.
 *
 * Pass `onClear` (alongside `onChange`) to let the user delete a value
 * after they've set it — useful for body fields like height/weight
 * where a user might tap "Tap to set", see the placeholder value, then
 * decide they'd rather skip the field. Without onClear, once a value
 * is set the user can only adjust it, not undo it.
 */
function Stepper({ value, onChange, onClear, min, max, step = 1, unit, icon, tint }: {
  value: number | null | undefined;
  onChange: (n: number) => void;
  onClear?: () => void;
  min: number;
  max: number;
  step?: number;
  unit: string;
  icon: IoniconName;
  tint: string;
}) {
  const { t } = useTranslation();
  const safeStep = step;
  const display = value ?? Math.round((min + max) / 2);
  const change = (delta: number) => onChange(clamp(display + delta, min, max));
  if (value == null) {
    return (
      <Pressable
        style={[stepperStyles.empty, { borderColor: tint }]}
        onPress={() => onChange(display)}
      >
        <Ionicons name={icon} size={18} color={tint} />
        <Text style={[stepperStyles.emptyText, { color: tint }]}>
          {t('common.tapToSet')} ({display} {unit})
        </Text>
      </Pressable>
    );
  }
  return (
    <View style={stepperStyles.row}>
      <Pressable onPress={() => change(-safeStep * 5)} style={stepperStyles.btnLg}>
        <Ionicons name="remove" size={20} color={colors.textPrimary} />
      </Pressable>
      <Pressable onPress={() => change(-safeStep)} style={stepperStyles.btn}>
        <Ionicons name="remove-outline" size={18} color={colors.textPrimary} />
      </Pressable>
      <View style={[stepperStyles.value, { borderColor: tint }]}>
        <Ionicons name={icon} size={16} color={tint} />
        <Text style={stepperStyles.valueText}>{value}</Text>
        <Text style={stepperStyles.unitText}>{unit}</Text>
      </View>
      <Pressable onPress={() => change(safeStep)} style={stepperStyles.btn}>
        <Ionicons name="add-outline" size={18} color={colors.textPrimary} />
      </Pressable>
      <Pressable onPress={() => change(safeStep * 5)} style={stepperStyles.btnLg}>
        <Ionicons name="add" size={20} color={colors.textPrimary} />
      </Pressable>
      {onClear ? (
        <Pressable
          onPress={onClear}
          accessibilityLabel={t('common.clear')}
          hitSlop={8}
          style={stepperStyles.clearBtn}
        >
          <Ionicons name="close" size={16} color={colors.textMuted} />
        </Pressable>
      ) : null}
    </View>
  );
}

function ConfidenceSlider({ value, onChange, tint }: {
  value: number | null | undefined;
  onChange: (n: number) => void;
  tint: string;
}) {
  const v = value ?? 7;
  return (
    <View style={confStyles.wrap}>
      <View style={confStyles.bar}>
        {[1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map((n) => (
          <Pressable
            key={n}
            onPress={() => onChange(n)}
            style={[
              confStyles.dot,
              { backgroundColor: n <= v ? tint : 'rgba(255,255,255,0.12)' },
            ]}
          />
        ))}
      </View>
      <Text style={confStyles.value}>{v}/10</Text>
    </View>
  );
}

// ─── Main component ──────────────────────────────────────────────────────

const ACCENT = colors.primary;

export function ProfileSetupScreen({ visible, mode, onFinish, onClose }: Props) {
  const insets = useSafeAreaInsets();
  const isEdit = mode === 'edit';
  const { t } = useTranslation();
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [profile, setProfile] = useState<Partial<UserProfile>>({});
  const [stepIdx, setStepIdx] = useState(0);

  const baseSteps = isEdit ? ALL_STEPS_EDIT : ALL_STEPS_SETUP;
  const visibleSteps = useMemo(
    () => baseSteps.filter((s) => s !== 'female' || femaleSlideRelevant(profile)),
    [baseSteps, profile.sex_at_birth, profile.age_band], // eslint-disable-line react-hooks/exhaustive-deps
  );
  const step = visibleSteps[Math.min(stepIdx, visibleSteps.length - 1)] ?? 'review';
  const isFirst = stepIdx === 0;
  const isLast = stepIdx >= visibleSteps.length - 1;

  // Pre-compute step flags into locals so the JSX below doesn't have
  // `step === 'foo'` comparisons inline — the react-native/no-raw-text
  // linter flags string literals inside JSX expressions even when they
  // are pure JS booleans.
  const onLanguage = step === 'language';
  const onWelcome  = step === 'welcome';
  const onAbout    = step === 'about';
  const onHeritage = step === 'heritage';
  const onBody     = step === 'body';
  const onDay      = step === 'day';
  const onSleep    = step === 'sleep';
  const onHabits   = step === 'habits';
  const onEating   = step === 'eating';
  const onHealth   = step === 'health';
  const onLife     = step === 'life';
  const onFemale   = step === 'female';
  const onGoals    = step === 'goals';
  const onReview   = step === 'review';

  useEffect(() => {
    if (!visible) return;
    setLoading(true);
    fetchProfile()
      .then((p) => setProfile(p))
      .catch((e) => Alert.alert("Couldn't load profile", (e as Error).message))
      .finally(() => setLoading(false));
    setStepIdx(0);
  }, [visible]);

  const set = <K extends keyof UserProfile>(key: K, value: UserProfile[K]) => {
    setProfile((prev) => ({ ...prev, [key]: value }));
  };

  const goNext = () => setStepIdx((i) => Math.min(i + 1, visibleSteps.length - 1));
  const goBack = () => setStepIdx((i) => Math.max(i - 1, 0));

  const handleFinish = async () => {
    setSaving(true);
    try {
      await updateProfile(profile);
      onFinish();
    } catch (e) {
      // Surface as much diagnostic info as the API gave us. ApiError
      // carries the parsed JSON body which on a 500 includes the
      // exception class name in production. Helps us debug remote
      // failures without enabling app.debug=true.
      const err = e as Error & { status?: number; body?: { exception_class?: string } };
      const cls = err.body?.exception_class ? ` [${err.body.exception_class}]` : '';
      const status = err.status ? ` (HTTP ${err.status})` : '';
      const detail = `${err.message ?? 'Unknown error'}${status}${cls}`;
      console.warn('Profile save failed:', detail, err);
      Alert.alert("Couldn't save", detail);
    } finally {
      setSaving(false);
    }
  };

  const canAdvanceFromAbout =
    !!profile.display_name?.trim() && !!profile.sex_at_birth && !!profile.age_band;
  const canAdvance = onAbout ? canAdvanceFromAbout : true;

  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose}>
      <StatusBar style="light" />
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
        style={[styles.container, { paddingTop: insets.top, paddingBottom: insets.bottom }]}
      >
        {/* Top bar */}
        <View style={styles.topBar}>
          {!isFirst ? (
            <Pressable onPress={goBack} hitSlop={8} style={styles.backBtn}>
              <Ionicons name="chevron-back" size={22} color={colors.textPrimary} />
            </Pressable>
          ) : <View style={styles.backBtn} />}
          <View style={styles.dots}>
            {visibleSteps.map((_, i) => (
              <View
                key={i}
                style={[
                  styles.dot,
                  i === stepIdx && styles.dotActive,
                  i < stepIdx && styles.dotDone,
                ]}
              />
            ))}
          </View>
          {isEdit && onClose ? (
            <Pressable onPress={onClose} hitSlop={8} style={styles.backBtn}>
              <Ionicons name="close" size={22} color={colors.textPrimary} />
            </Pressable>
          ) : <View style={styles.backBtn} />}
        </View>

        {loading ? (
          <View style={styles.loading}>
            <ActivityIndicator color={colors.primary} />
          </View>
        ) : (
          <ScrollView
            style={styles.body}
            contentContainerStyle={styles.bodyContent}
            keyboardShouldPersistTaps="handled"
            showsVerticalScrollIndicator={false}
          >
            {onLanguage && <LanguageStep profile={profile} set={set} />}
            {onWelcome && <WelcomeStep />}
            {onAbout && <AboutStep profile={profile} set={set} />}
            {onHeritage && <HeritageStep profile={profile} set={set} />}
            {onBody && <BodyStep profile={profile} set={set} />}
            {onDay && <DayStep profile={profile} set={set} />}
            {onSleep && <SleepStep profile={profile} set={set} />}
            {onHabits && <HabitsStep profile={profile} set={set} />}
            {onEating && <EatingStep profile={profile} set={set} />}
            {onHealth && <HealthStep profile={profile} set={set} />}
            {onLife && <LifeStep profile={profile} set={set} />}
            {onFemale && <FemaleStep profile={profile} set={set} />}
            {onGoals && <GoalsStep profile={profile} set={set} />}
            {onReview && <ReviewStep profile={profile} jumpTo={setStepIdx} steps={visibleSteps} />}
          </ScrollView>
        )}

        {/* Bottom CTA */}
        <View style={styles.cta}>
          {onWelcome ? (
            <Pressable onPress={goNext} style={[styles.primary, { backgroundColor: ACCENT }]}>
              <Text style={styles.primaryText}>{t('common.letsGo')}</Text>
              <Ionicons name="arrow-forward" size={18} color={colors.textPrimary} />
            </Pressable>
          ) : isLast ? (
            <Pressable
              onPress={handleFinish}
              disabled={saving}
              style={[styles.primary, { backgroundColor: ACCENT }, saving && { opacity: 0.6 }]}
            >
              {saving ? <ActivityIndicator color={colors.textPrimary} /> : (
                <>
                  <Ionicons name="checkmark" size={18} color={colors.textPrimary} />
                  <Text style={styles.primaryText}>{t('common.saveAndStart')}</Text>
                </>
              )}
            </Pressable>
          ) : (
            <Pressable
              onPress={goNext}
              disabled={!canAdvance}
              style={[
                styles.primary,
                { backgroundColor: ACCENT },
                !canAdvance && { opacity: 0.5 },
              ]}
            >
              <Text style={styles.primaryText}>{t('common.next')}</Text>
              <Ionicons name="arrow-forward" size={18} color={colors.textPrimary} />
            </Pressable>
          )}
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

// ─── Step components ─────────────────────────────────────────────────────

type StepProps = {
  profile: Partial<UserProfile>;
  set: <K extends keyof UserProfile>(key: K, value: UserProfile[K]) => void;
};

function LanguageStep({ profile, set }: StepProps) {
  const { t } = useTranslation();
  const current = profile.preferred_language ?? null;

  // Picking a language must (a) write it onto the profile so it
  // persists when we save, (b) flip the i18n runtime so the rest of
  // the wizard already shows in the chosen language as you click. The
  // SecureStore persistence inside setLanguage() means even if the
  // user backs out and re-enters, their pick stays.
  const pick = (code: LanguageCode) => {
    set('preferred_language', code);
    void setLanguage(code);
  };

  return (
    <>
      <StepHero
        icon="language-outline"
        tint={ACCENT}
        title={t('languagePicker.title')}
        subtitle={t('languagePicker.subtitle')}
      />
      <View style={chipStyles.grid}>
        {SUPPORTED_LANGUAGES.map((lang) => {
          const selected = current === lang.code;
          return (
            <Pressable
              key={lang.code}
              onPress={() => pick(lang.code as LanguageCode)}
              style={({ pressed }) => [
                chipStyles.card, chipStyles.cardHalf,
                selected && chipStyles.cardSelected,
                pressed && { opacity: 0.85 },
              ]}
            >
              <Text style={langStyles.native}>{lang.native}</Text>
              <Text style={langStyles.english}>{lang.name}</Text>
            </Pressable>
          );
        })}
      </View>
    </>
  );
}

function WelcomeStep() {
  const { t } = useTranslation();
  return (
    <>
      <StepHero
        icon="sparkles-outline"
        tint={ACCENT}
        title={t('profileSetup.welcomeTitle')}
        subtitle={t('profileSetup.welcomeSubtitle')}
      />
      <View style={privacyStyles.card}>
        <Ionicons name="shield-checkmark-outline" size={20} color={ACCENT} />
        <Text style={privacyStyles.text}>
          {t('profileSetup.privacyText')}
        </Text>
      </View>
    </>
  );
}

function AboutStep({ profile, set }: StepProps) {
  const { t } = useTranslation();
  return (
    <>
      <StepHero icon="person-outline" tint={ACCENT} title={t('profileSetup.aboutTitle')} />

      <FieldLabel>{t('profileSetup.fieldName')}</FieldLabel>
      <TextInput
        style={styles.text}
        placeholder={t('profileSetup.fieldNamePlaceholder')}
        placeholderTextColor={colors.textMuted}
        value={profile.display_name ?? ''}
        onChangeText={(v) => set('display_name', v)}
        autoFocus
      />

      <FieldLabel>{t('profileSetup.fieldAge')}</FieldLabel>
      <ChipChoice
        options={ageOptions(t)}
        value={profile.age_band ?? null}
        onChange={(v) => set('age_band', v)}
        columns={3}
      />

      <FieldLabel>{t('profileSetup.fieldSex')}</FieldLabel>
      <ChipChoice
        options={sexOptions(t)}
        value={profile.sex_at_birth ?? null}
        onChange={(v) => set('sex_at_birth', v)}
        columns={3}
      />

      <FieldLabel optional>{t('profileSetup.fieldPronouns')}</FieldLabel>
      <View style={multiStyles.wrap}>
        {PRONOUN_OPTIONS.map((p) => (
          <Pressable
            key={p}
            onPress={() => set('pronouns', p)}
            style={[multiStyles.chip, profile.pronouns === p && multiStyles.chipSelected]}
          >
            <Text style={[multiStyles.chipText, profile.pronouns === p && multiStyles.chipTextSelected]}>
              {p}
            </Text>
          </Pressable>
        ))}
      </View>
    </>
  );
}

function HeritageStep({ profile, set }: StepProps) {
  const { t } = useTranslation();
  return (
    <>
      <StepHero icon="globe-outline" tint={ACCENT} title={t('profileSetup.heritageTitle')} subtitle={t('profileSetup.heritageSubtitle')} />
      <FieldLabel optional>{t('profileSetup.heritageHelper')}</FieldLabel>
      <MultiChip
        options={ETHNICITY_OPTIONS}
        keys={ETHNICITY_KEYS}
        values={profile.ethnicity ?? []}
        onChange={(vs) => set('ethnicity', vs)}
      />
    </>
  );
}

function BodyStep({ profile, set }: StepProps) {
  const { t } = useTranslation();
  return (
    <>
      <StepHero icon="resize-outline" tint={ACCENT} title={t('profileSetup.bodyTitle')} />

      <FieldLabel>{t('profileSetup.fieldHeight')}</FieldLabel>
      <Stepper
        value={profile.height_cm}
        onChange={(n) => set('height_cm', n)}
        onClear={() => set('height_cm', null)}
        min={120} max={220} unit="cm" icon="resize-outline" tint={ACCENT}
      />

      <FieldLabel>{t('profileSetup.fieldWeight')}</FieldLabel>
      <Stepper
        value={profile.weight_kg}
        onChange={(n) => set('weight_kg', n)}
        onClear={() => set('weight_kg', null)}
        min={30} max={250} unit="kg" icon="barbell-outline" tint={ACCENT}
      />

      <FieldLabel optional>{t('profileSetup.fieldWaist')}</FieldLabel>
      <Stepper
        value={profile.waist_cm}
        onChange={(n) => set('waist_cm', n)}
        onClear={() => set('waist_cm', null)}
        min={50} max={160} unit="cm" icon="ellipse-outline" tint={ACCENT}
      />
    </>
  );
}

function DayStep({ profile, set }: StepProps) {
  const { t } = useTranslation();
  return (
    <>
      <StepHero icon="sunny-outline" tint={ACCENT} title={t('profileSetup.dayTitle')} />

      <FieldLabel>{t('profileSetup.fieldActivity')}</FieldLabel>
      <ChipChoice
        options={activityOptions(t)}
        value={profile.activity_level ?? null}
        onChange={(v) => set('activity_level', v)}
      />

      <FieldLabel>{t('profileSetup.fieldJob')}</FieldLabel>
      <ChipChoice
        options={jobOptions(t)}
        value={profile.job_type ?? null}
        onChange={(v) => set('job_type', v)}
        columns={3}
      />

      <FieldLabel>{t('profileSetup.fieldOutdoor')}</FieldLabel>
      <ChipChoice
        options={timeBandOutdoor(t)}
        value={profile.outdoor_minutes_band ?? null}
        onChange={(v) => set('outdoor_minutes_band', v)}
        columns={2}
      />

      <FieldLabel>{t('profileSetup.fieldWellnessTime')}</FieldLabel>
      <ChipChoice
        options={timeBandWellness(t)}
        value={profile.wellness_time_band ?? null}
        onChange={(v) => set('wellness_time_band', v)}
        columns={2}
      />
    </>
  );
}

function SleepStep({ profile, set }: StepProps) {
  const { t } = useTranslation();
  return (
    <>
      <StepHero icon="moon-outline" tint={ACCENT} title={t('profileSetup.sleepTitle')} />

      <FieldLabel>{t('profileSetup.fieldSleepHours')}</FieldLabel>
      <Stepper
        value={profile.sleep_hours_target}
        onChange={(n) => set('sleep_hours_target', n)}
        min={3} max={14} unit="h" icon="moon-outline" tint={ACCENT}
      />

      <FieldLabel>{t('profileSetup.fieldSleepQuality')}</FieldLabel>
      <ChipChoice
        options={sleepQualityOptions(t)}
        value={profile.sleep_quality ?? null}
        onChange={(v) => set('sleep_quality', v)}
        columns={3}
      />

      <FieldLabel>{t('profileSetup.fieldChronotype')}</FieldLabel>
      <ChipChoice
        options={chronotypeOptions(t)}
        value={profile.chronotype ?? null}
        onChange={(v) => set('chronotype', v)}
        columns={1}
      />
    </>
  );
}

function HabitsStep({ profile, set }: StepProps) {
  const { t } = useTranslation();
  return (
    <>
      <StepHero icon="cafe-outline" tint={ACCENT} title={t('profileSetup.habitsTitle')} />

      <FieldLabel>{t('profileSetup.fieldSmoking')}</FieldLabel>
      <ChipChoice options={smokingOptions(t)} value={profile.smoking_status ?? null} onChange={(v) => set('smoking_status', v)} columns={2} />

      <FieldLabel>{t('profileSetup.fieldAlcohol')}</FieldLabel>
      <ChipChoice options={alcoholOptions(t)} value={profile.alcohol_freq ?? null} onChange={(v) => set('alcohol_freq', v)} columns={2} />

      <FieldLabel>{t('profileSetup.fieldCaffeine')}</FieldLabel>
      <ChipChoice options={caffeineOptions(t)} value={profile.caffeine_freq ?? null} onChange={(v) => set('caffeine_freq', v)} columns={2} />

      <FieldLabel>{t('profileSetup.fieldStress')}</FieldLabel>
      <ChipChoice options={stressOptions(t)} value={profile.stress_level ?? null} onChange={(v) => set('stress_level', v)} columns={3} />
    </>
  );
}

function EatingStep({ profile, set }: StepProps) {
  const { t } = useTranslation();
  return (
    <>
      <StepHero icon="restaurant-outline" tint={ACCENT} title={t('profileSetup.eatingTitle')} />

      <FieldLabel>{t('profileSetup.fieldEatingPattern')}</FieldLabel>
      <ChipChoice
        options={eatingPatternOptions(t)}
        value={profile.eating_pattern ?? null}
        onChange={(v) => set('eating_pattern', v)}
        columns={2}
      />

      <FieldLabel>{t('profileSetup.fieldEatingSchedule')}</FieldLabel>
      <ChipChoice
        options={eatingScheduleOptions(t)}
        value={profile.eating_schedule ?? null}
        onChange={(v) => set('eating_schedule', v)}
        columns={2}
      />

      <FieldLabel optional>{t('profileSetup.fieldAllergies')}</FieldLabel>
      <MultiChip
        options={ALLERGY_OPTIONS}
        keys={ALLERGY_KEYS}
        values={profile.allergies ?? []}
        onChange={(vs) => set('allergies', vs)}
      />

      <FieldLabel optional>{t('profileSetup.fieldIntolerances')}</FieldLabel>
      <MultiChip
        options={INTOLERANCE_OPTIONS}
        keys={INTOLERANCE_KEYS}
        values={profile.intolerances ?? []}
        onChange={(vs) => set('intolerances', vs)}
      />
    </>
  );
}

function HealthStep({ profile, set }: StepProps) {
  const { t } = useTranslation();
  const [medsInput, setMedsInput] = useState('');
  const meds = profile.medications ?? [];
  const addMed = (name: string) => {
    const trimmed = name.trim();
    if (!trimmed || meds.includes(trimmed)) return;
    set('medications', [...meds, trimmed]);
    setMedsInput('');
  };
  const removeMed = (name: string) => {
    set('medications', meds.filter((m) => m !== name));
  };

  return (
    <>
      <StepHero icon="medical-outline" tint={ACCENT} title={t('profileSetup.healthTitle')} subtitle={t('profileSetup.healthSubtitle')} />

      <FieldLabel optional>{t('profileSetup.fieldConditions')}</FieldLabel>
      <MultiChip
        options={CONDITION_OPTIONS}
        keys={CONDITION_KEYS}
        values={profile.conditions ?? []}
        onChange={(vs) => set('conditions', vs)}
      />

      <FieldLabel optional>{t('profileSetup.fieldMedications')}</FieldLabel>
      <View style={medsStyles.row}>
        <TextInput
          style={medsStyles.input}
          placeholder={t('profileSetup.medsAddPlaceholder')}
          placeholderTextColor={colors.textMuted}
          value={medsInput}
          onChangeText={setMedsInput}
          onSubmitEditing={() => addMed(medsInput)}
          returnKeyType="done"
        />
        <Pressable onPress={() => addMed(medsInput)} style={medsStyles.add}>
          <Ionicons name="add" size={18} color={colors.textPrimary} />
        </Pressable>
      </View>
      <View style={multiStyles.wrap}>
        {COMMON_MEDS.map((m) => (
          <Pressable
            key={m}
            onPress={() => addMed(m)}
            style={[multiStyles.chip, meds.includes(m) && multiStyles.chipSelected]}
          >
            <Text style={[multiStyles.chipText, meds.includes(m) && multiStyles.chipTextSelected]}>
              + {m}
            </Text>
          </Pressable>
        ))}
      </View>
      {meds.length > 0 ? (
        <View style={[multiStyles.wrap, { marginTop: spacing.sm }]}>
          {meds.map((m) => (
            <Pressable key={m} onPress={() => removeMed(m)} style={[multiStyles.chip, multiStyles.chipSelected]}>
              <Ionicons name="close" size={12} color={colors.primary} />
              <Text style={[multiStyles.chipText, multiStyles.chipTextSelected]}>{m}</Text>
            </Pressable>
          ))}
        </View>
      ) : null}

      <FieldLabel optional>{t('profileSetup.fieldFamilyHistory')}</FieldLabel>
      <MultiChip
        options={FAMILY_HISTORY_OPTIONS}
        keys={FAMILY_HISTORY_KEYS}
        values={profile.family_history ?? []}
        onChange={(vs) => set('family_history', vs)}
      />

      <FieldLabel optional>{t('profileSetup.fieldInjuries')}</FieldLabel>
      <MultiChip
        options={INJURY_OPTIONS}
        keys={INJURY_KEYS}
        values={profile.past_injuries ?? []}
        onChange={(vs) => set('past_injuries', vs)}
      />

      <FieldLabel optional>{t('profileSetup.fieldMentalHealth')}</FieldLabel>
      <MultiChip
        options={MENTAL_HEALTH_OPTIONS}
        keys={MENTAL_HEALTH_KEYS}
        values={profile.mental_health ?? []}
        onChange={(vs) => set('mental_health', vs)}
      />
    </>
  );
}

function LifeStep({ profile, set }: StepProps) {
  const { t } = useTranslation();
  return (
    <>
      <StepHero icon="home-outline" tint={ACCENT} title={t('profileSetup.lifeTitle')} />

      <FieldLabel>{t('profileSetup.fieldLiving')}</FieldLabel>
      <ChipChoice
        options={livingOptions(t)}
        value={profile.living_situation ?? null}
        onChange={(v) => set('living_situation', v)}
        columns={2}
      />

      <FieldLabel>{t('profileSetup.fieldTravel')}</FieldLabel>
      <ChipChoice
        options={travelOptions(t)}
        value={profile.travel_frequency ?? null}
        onChange={(v) => set('travel_frequency', v)}
        columns={3}
      />

      <FieldLabel>{t('profileSetup.fieldBudget')}</FieldLabel>
      <View style={multiStyles.wrap}>
        <Pressable
          onPress={() => set('budget_conscious', true)}
          style={[multiStyles.chip, profile.budget_conscious === true && multiStyles.chipSelected]}
        >
          <Ionicons
            name="wallet-outline"
            size={14}
            color={profile.budget_conscious === true ? colors.primary : colors.textMuted}
          />
          <Text style={[multiStyles.chipText, profile.budget_conscious === true && multiStyles.chipTextSelected]}>
            {t('profileSetup.budgetYes')}
          </Text>
        </Pressable>
        <Pressable
          onPress={() => set('budget_conscious', false)}
          style={[multiStyles.chip, profile.budget_conscious === false && multiStyles.chipSelected]}
        >
          <Text style={[multiStyles.chipText, profile.budget_conscious === false && multiStyles.chipTextSelected]}>
            {t('profileSetup.budgetNo')}
          </Text>
        </Pressable>
      </View>
    </>
  );
}

function FemaleStep({ profile, set }: StepProps) {
  const { t } = useTranslation();
  const status = profile.female_status ?? null;
  return (
    <>
      <StepHero icon="female-outline" tint={ACCENT} title={t('profileSetup.femaleTitle')} subtitle={t('profileSetup.femaleSubtitle')} />

      <FieldLabel>{t('profileSetup.fieldFemaleStatus')}</FieldLabel>
      <ChipChoice
        options={femaleStatusOptions(t)}
        value={status}
        onChange={(v) => set('female_status', v)}
        columns={2}
      />

      {status === 'pregnant' ? (
        <>
          <FieldLabel>{t('profileSetup.fieldPregnancyWeeks')}</FieldLabel>
          <Stepper
            value={profile.pregnancy_weeks}
            onChange={(n) => set('pregnancy_weeks', n)}
            min={1} max={42} unit="wk" icon="female-outline" tint={ACCENT}
          />
        </>
      ) : null}

      {status === 'breastfeeding' ? (
        <>
          <FieldLabel>{t('profileSetup.fieldBreastfeedingMonths')}</FieldLabel>
          <Stepper
            value={profile.breastfeeding_months}
            onChange={(n) => set('breastfeeding_months', n)}
            min={0} max={36} unit="mo" icon="water-outline" tint={ACCENT}
          />
        </>
      ) : null}

      {(status === 'regular' || status === 'irregular') ? (
        <>
          <FieldLabel>{t('profileSetup.fieldCycleLength')}</FieldLabel>
          <Stepper
            value={profile.cycle_length_days}
            onChange={(n) => set('cycle_length_days', n)}
            min={14} max={60} unit="days" icon="refresh-circle-outline" tint={ACCENT}
          />
        </>
      ) : null}

      <FieldLabel optional>{t('profileSetup.fieldContraception')}</FieldLabel>
      <ChipChoice
        options={contraceptionOptions(t)}
        value={profile.contraception ?? null}
        onChange={(v) => set('contraception', v)}
        columns={2}
      />
    </>
  );
}

function GoalsStep({ profile, set }: StepProps) {
  const { t } = useTranslation();
  const goals = useMemo(() => goalOptions(t), [t]);
  const selected = (profile.goals ?? []) as string[];
  const toggle = (key: string) => {
    if (selected.includes(key)) {
      set('goals', selected.filter((g) => g !== key));
    } else if (selected.length < 3) {
      set('goals', [...selected, key]);
    }
  };
  const teamMembers = useMemo(() => {
    const names = new Set<string>();
    goals.forEach((g) => {
      if (selected.includes(g.key)) g.avatars.forEach((n) => names.add(n));
    });
    return Array.from(names);
  }, [selected, goals]);

  return (
    <>
      <StepHero icon="flag-outline" tint={ACCENT} title={t('profileSetup.goalsTitle')} subtitle={t('profileSetup.goalsSubtitle')} />

      <View style={chipStyles.grid}>
        {goals.map((g) => {
          const isSelected = selected.includes(g.key);
          const disabled = !isSelected && selected.length >= 3;
          return (
            <Pressable
              key={g.key}
              onPress={() => toggle(g.key)}
              disabled={disabled}
              style={({ pressed }) => [
                chipStyles.card, chipStyles.cardHalf,
                isSelected && chipStyles.cardSelected,
                disabled && { opacity: 0.4 },
                pressed && { opacity: 0.85 },
              ]}
            >
              <Ionicons name={g.icon} size={22} color={isSelected ? colors.primary : colors.textPrimary} />
              <Text style={[chipStyles.label, isSelected && chipStyles.labelSelected]}>{g.label}</Text>
              {isSelected ? <Text style={chipStyles.sub}>{g.avatars.join(' + ')}</Text> : null}
            </Pressable>
          );
        })}
      </View>

      {teamMembers.length > 0 ? (
        <View style={teamStyles.card}>
          <Ionicons name="people-circle-outline" size={20} color={ACCENT} />
          <Text style={teamStyles.text}>
            {t('profileSetup.teamPrefix')} {teamMembers.join(', ')}.
          </Text>
        </View>
      ) : null}

      <FieldLabel>{t('profileSetup.fieldTimeline')}</FieldLabel>
      <ChipChoice
        options={timelineOptions(t)}
        value={profile.goal_timeline ?? null}
        onChange={(v) => set('goal_timeline', v)}
        columns={2}
      />

      <FieldLabel>{t('profileSetup.fieldConfidence')}</FieldLabel>
      <ConfidenceSlider
        value={profile.goal_confidence}
        onChange={(n) => set('goal_confidence', n)}
        tint={ACCENT}
      />
    </>
  );
}

function ReviewStep({ profile, jumpTo, steps }: { profile: Partial<UserProfile>; jumpTo: (i: number) => void; steps: StepKey[] }) {
  const { t } = useTranslation();
  const summary = useMemo(() => buildSummary(profile, t), [profile, t]);
  return (
    <>
      <StepHero icon="checkmark-done-outline" tint={ACCENT} title={t('profileSetup.reviewTitle')} subtitle={t('profileSetup.reviewSubtitle')} />
      {summary.map((sec) => {
        const idx = steps.indexOf(sec.step);
        return (
          <Pressable
            key={sec.step}
            onPress={() => idx >= 0 && jumpTo(idx)}
            style={reviewStyles.card}
          >
            <View style={reviewStyles.head}>
              <Ionicons name={sec.icon} size={18} color={ACCENT} />
              <Text style={reviewStyles.title}>{sec.title}</Text>
              <Ionicons name="pencil" size={14} color={colors.textMuted} style={{ marginLeft: 'auto' }} />
            </View>
            <Text style={reviewStyles.body}>{sec.body || t('common.tapToSet')}</Text>
          </Pressable>
        );
      })}
    </>
  );
}

function buildSummary(
  p: Partial<UserProfile>,
  t: (key: string) => string,
): { step: StepKey; icon: IoniconName; title: string; body: string }[] {
  const arr = (a?: string[]) => (a && a.length ? a.join(', ') : '');
  return [
    { step: 'about',    icon: 'person-outline',    title: t('profileSetup.reviewSection.about'),  body: [p.display_name, p.age_band, p.sex_at_birth].filter(Boolean).join(' · ') },
    { step: 'body',     icon: 'resize-outline',    title: t('profileSetup.reviewSection.body'),   body: [p.height_cm && `${p.height_cm} cm`, p.weight_kg && `${p.weight_kg} kg`].filter(Boolean).join(' · ') },
    { step: 'day',      icon: 'sunny-outline',     title: t('profileSetup.reviewSection.day'),    body: [p.activity_level, p.job_type].filter(Boolean).join(' · ') },
    { step: 'sleep',    icon: 'moon-outline',      title: t('profileSetup.reviewSection.sleep'),  body: [p.sleep_hours_target && `${p.sleep_hours_target}h`, p.sleep_quality, p.chronotype].filter(Boolean).join(' · ') },
    { step: 'habits',   icon: 'cafe-outline',      title: t('profileSetup.reviewSection.habits'), body: [p.smoking_status, p.alcohol_freq, p.caffeine_freq, p.stress_level].filter(Boolean).join(' · ') },
    { step: 'eating',   icon: 'restaurant-outline', title: t('profileSetup.reviewSection.eating'), body: [p.eating_pattern, p.eating_schedule, arr(p.allergies)].filter(Boolean).join(' · ') },
    { step: 'health',   icon: 'medical-outline',   title: t('profileSetup.reviewSection.health'), body: [arr(p.conditions), arr(p.medications), arr(p.family_history)].filter(Boolean).join(' · ') },
    { step: 'life',     icon: 'home-outline',      title: t('profileSetup.reviewSection.life'),   body: [p.living_situation, p.travel_frequency].filter(Boolean).join(' · ') },
    { step: 'goals',    icon: 'flag-outline',      title: t('profileSetup.reviewSection.goals'),  body: [arr(p.goals), p.goal_timeline].filter(Boolean).join(' · ') },
  ];
}

// ─── Styles ──────────────────────────────────────────────────────────────

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.background },
  topBar: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    gap: spacing.sm,
  },
  backBtn: {
    width: 36, height: 36,
    borderRadius: radius.pill,
    alignItems: 'center', justifyContent: 'center',
  },
  dots: {
    flex: 1,
    flexDirection: 'row',
    justifyContent: 'center',
    gap: 4,
  },
  dot: {
    width: 6, height: 6, borderRadius: 3,
    backgroundColor: 'rgba(255,255,255,0.18)',
  },
  dotActive: { width: 18, backgroundColor: colors.primary },
  dotDone: { backgroundColor: 'rgba(124,92,255,0.6)' },
  body: { flex: 1 },
  bodyContent: { padding: spacing.md, paddingBottom: spacing.xl },
  loading: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  cta: {
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: 'rgba(255,255,255,0.08)',
  },
  primary: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.sm,
    paddingVertical: spacing.md,
    borderRadius: radius.pill,
  },
  primaryText: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
  },
  text: {
    backgroundColor: 'rgba(31,41,55,0.7)',
    color: colors.textPrimary,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm + 2,
    fontSize: fontSize.md,
    marginBottom: spacing.md,
  },
});

const heroStyles = StyleSheet.create({
  wrap: { alignItems: 'center', marginBottom: spacing.lg, gap: spacing.sm },
  iconCircle: {
    width: 64, height: 64, borderRadius: 32,
    borderWidth: 2,
    alignItems: 'center', justifyContent: 'center',
  },
  title: {
    color: colors.textPrimary,
    fontSize: fontSize.xl,
    fontWeight: '800',
    textAlign: 'center',
  },
  subtitle: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    textAlign: 'center',
    paddingHorizontal: spacing.md,
  },
});

const langStyles = StyleSheet.create({
  native: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
    textAlign: 'center',
  },
  english: {
    color: colors.textMuted,
    fontSize: fontSize.xs,
    textAlign: 'center',
  },
});

const fieldStyles = StyleSheet.create({
  label: {
    color: colors.textMuted,
    fontSize: fontSize.xs,
    fontWeight: '700',
    letterSpacing: 1,
    textTransform: 'uppercase',
    marginTop: spacing.md,
    marginBottom: spacing.sm,
  },
  optional: {
    color: 'rgba(255,255,255,0.35)',
    fontWeight: '500',
    textTransform: 'none',
    letterSpacing: 0,
  },
});

const chipStyles = StyleSheet.create({
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
  },
  card: {
    backgroundColor: 'rgba(31,41,55,0.6)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.08)',
    borderRadius: radius.md,
    padding: spacing.sm + 2,
    alignItems: 'center',
    gap: 6,
    minHeight: 70,
  },
  cardHalf: { flexBasis: '48%', flexGrow: 1 },
  cardThird: { flexBasis: '31%', flexGrow: 1 },
  cardFull: { flexBasis: '100%' },
  cardSelected: {
    borderColor: colors.primary,
    backgroundColor: 'rgba(124,92,255,0.18)',
  },
  emoji: { fontSize: 28, lineHeight: 32 },
  label: {
    color: colors.textPrimary,
    fontSize: fontSize.sm,
    fontWeight: '600',
    textAlign: 'center',
  },
  labelSelected: { color: colors.primary },
  sub: {
    color: colors.textMuted,
    fontSize: 11,
    textAlign: 'center',
  },
});

const multiStyles = StyleSheet.create({
  wrap: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 6,
  },
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    paddingHorizontal: spacing.sm + 2,
    paddingVertical: 8,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.12)',
    backgroundColor: 'rgba(31,41,55,0.5)',
  },
  chipSelected: {
    borderColor: colors.primary,
    backgroundColor: 'rgba(124,92,255,0.18)',
  },
  chipText: {
    color: colors.textPrimary,
    fontSize: fontSize.sm,
    fontWeight: '500',
  },
  chipTextSelected: {
    color: colors.primary,
    fontWeight: '700',
  },
});

const stepperStyles = StyleSheet.create({
  empty: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    paddingVertical: spacing.md,
    borderWidth: 1,
    borderRadius: radius.md,
    borderStyle: 'dashed',
  },
  emptyText: { fontSize: fontSize.sm, fontWeight: '600' },
  row: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 4 },
  btn: {
    width: 36, height: 36, borderRadius: 18,
    backgroundColor: 'rgba(255,255,255,0.08)',
    alignItems: 'center', justifyContent: 'center',
  },
  btnLg: {
    width: 40, height: 40, borderRadius: 20,
    backgroundColor: 'rgba(255,255,255,0.12)',
    alignItems: 'center', justifyContent: 'center',
  },
  value: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    borderWidth: 2,
    borderRadius: radius.md,
    paddingVertical: spacing.sm,
    backgroundColor: 'rgba(31,41,55,0.6)',
  },
  valueText: { color: colors.textPrimary, fontSize: fontSize.lg, fontWeight: '800' },
  unitText: { color: colors.textMuted, fontSize: fontSize.sm },
  clearBtn: {
    width: 28, height: 28, borderRadius: 14,
    backgroundColor: 'rgba(255,255,255,0.06)',
    alignItems: 'center', justifyContent: 'center',
    marginLeft: 4,
  },
});

const confStyles = StyleSheet.create({
  wrap: { gap: spacing.sm },
  bar: { flexDirection: 'row', justifyContent: 'space-between', gap: 4 },
  dot: { flex: 1, height: 14, borderRadius: 7 },
  value: { textAlign: 'center', color: colors.textPrimary, fontSize: fontSize.md, fontWeight: '700' },
});

const privacyStyles = StyleSheet.create({
  card: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    backgroundColor: 'rgba(124,92,255,0.08)',
    borderWidth: 1,
    borderColor: 'rgba(124,92,255,0.22)',
    borderRadius: radius.md,
    padding: spacing.md,
    marginTop: spacing.lg,
  },
  text: { flex: 1, color: colors.textSecondary, fontSize: fontSize.sm, lineHeight: 20 },
});

const teamStyles = StyleSheet.create({
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: 'rgba(124,92,255,0.10)',
    borderRadius: radius.md,
    padding: spacing.sm + 2,
    marginVertical: spacing.sm,
  },
  text: { flex: 1, color: colors.textPrimary, fontSize: fontSize.sm, fontWeight: '600' },
});

const reviewStyles = StyleSheet.create({
  card: {
    backgroundColor: 'rgba(31,41,55,0.6)',
    borderRadius: radius.md,
    padding: spacing.sm + 2,
    marginBottom: spacing.sm,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.08)',
  },
  head: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  title: { color: colors.textPrimary, fontSize: fontSize.sm, fontWeight: '700' },
  body: { color: colors.textMuted, fontSize: fontSize.sm, marginTop: 4 },
});

const medsStyles = StyleSheet.create({
  row: { flexDirection: 'row', gap: 6, marginBottom: spacing.sm },
  input: {
    flex: 1,
    backgroundColor: 'rgba(31,41,55,0.7)',
    color: colors.textPrimary,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    fontSize: fontSize.sm,
  },
  add: {
    width: 40, height: 40, borderRadius: 20,
    backgroundColor: colors.primary,
    alignItems: 'center', justifyContent: 'center',
  },
});
