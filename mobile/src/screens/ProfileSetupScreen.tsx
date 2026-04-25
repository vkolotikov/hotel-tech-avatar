import { useEffect, useMemo, useState } from 'react';
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
import { Ionicons } from '@expo/vector-icons';
import { StatusBar } from 'expo-status-bar';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { fetchProfile, updateProfile, type UserProfile } from '../api/profile';
import { colors, spacing, radius, fontSize } from '../theme';

type Mode = 'setup' | 'edit';

type Props = {
  visible: boolean;
  mode: Mode;
  onFinish: () => void;
  onClose?: () => void;
};

type StepKey = 'about' | 'body' | 'lifestyle' | 'health' | 'review';

const STEP_ORDER_SETUP: StepKey[] = ['about', 'body', 'lifestyle', 'health', 'review'];
const STEP_ORDER_EDIT: StepKey[] = ['about', 'body', 'lifestyle', 'health'];

type DraftProfile = Partial<UserProfile>;

type IconName = keyof typeof Ionicons.glyphMap;

type ChipOpt = { value: string; label: string; icon?: IconName };

const ACTIVITY_OPTIONS: Array<{
  value: NonNullable<UserProfile['activity_level']>;
  label: string;
  subtitle: string;
  icon: IconName;
}> = [
  { value: 'sedentary', label: 'Sedentary', subtitle: 'Mostly sitting, little walking',  icon: 'cafe-outline' },
  { value: 'light',     label: 'Light',     subtitle: 'A few short walks each week',     icon: 'walk-outline' },
  { value: 'moderate',  label: 'Moderate',  subtitle: 'Exercise 3–4 days a week',        icon: 'bicycle-outline' },
  { value: 'active',    label: 'Active',    subtitle: 'Hard training 5–6 days a week',   icon: 'barbell-outline' },
  { value: 'athlete',   label: 'Athlete',   subtitle: 'Competitive / elite training',    icon: 'trophy-outline' },
];

const SEX_OPTIONS: Array<{
  value: NonNullable<UserProfile['sex_at_birth']>;
  label: string;
  icon: IconName;
}> = [
  { value: 'F', label: 'Female',   icon: 'female-outline' },
  { value: 'M', label: 'Male',     icon: 'male-outline' },
  { value: 'I', label: 'Intersex', icon: 'transgender-outline' },
];

const GOAL_OPTIONS: ChipOpt[] = [
  { value: 'better_sleep',      label: 'Sleep better',       icon: 'moon-outline' },
  { value: 'more_energy',       label: 'More energy',        icon: 'flash-outline' },
  { value: 'weight_loss',       label: 'Lose weight',        icon: 'trending-down-outline' },
  { value: 'muscle_gain',       label: 'Build muscle',       icon: 'barbell-outline' },
  { value: 'gut_health',        label: 'Gut health',         icon: 'leaf-outline' },
  { value: 'stress_management', label: 'Manage stress',      icon: 'heart-outline' },
  { value: 'longevity',         label: 'Longevity',          icon: 'hourglass-outline' },
  { value: 'general_wellbeing', label: 'General wellbeing',  icon: 'sparkles-outline' },
];

const DIETARY_OPTIONS: ChipOpt[] = [
  { value: 'vegetarian',    label: 'Vegetarian',    icon: 'leaf-outline' },
  { value: 'vegan',         label: 'Vegan',         icon: 'flower-outline' },
  { value: 'pescatarian',   label: 'Pescatarian',   icon: 'fish-outline' },
  { value: 'gluten_free',   label: 'Gluten-free',   icon: 'ban-outline' },
  { value: 'dairy_free',    label: 'Dairy-free',    icon: 'water-outline' },
  { value: 'low_carb',      label: 'Low-carb',      icon: 'remove-circle-outline' },
  { value: 'keto',          label: 'Keto',          icon: 'flame-outline' },
  { value: 'mediterranean', label: 'Mediterranean', icon: 'sunny-outline' },
  { value: 'halal',         label: 'Halal',         icon: 'star-outline' },
  { value: 'kosher',        label: 'Kosher',        icon: 'star-outline' },
];

const ALLERGY_OPTIONS: ChipOpt[] = [
  { value: 'peanuts',   label: 'Peanuts',    icon: 'nutrition-outline' },
  { value: 'tree_nuts', label: 'Tree nuts',  icon: 'nutrition-outline' },
  { value: 'dairy',     label: 'Dairy',      icon: 'water-outline' },
  { value: 'eggs',      label: 'Eggs',       icon: 'egg-outline' },
  { value: 'shellfish', label: 'Shellfish',  icon: 'fish-outline' },
  { value: 'fish',      label: 'Fish',       icon: 'fish-outline' },
  { value: 'soy',       label: 'Soy',        icon: 'leaf-outline' },
  { value: 'gluten',    label: 'Gluten',     icon: 'ban-outline' },
  { value: 'sesame',    label: 'Sesame',     icon: 'ellipse-outline' },
];

const CONDITION_OPTIONS: ChipOpt[] = [
  { value: 'hypertension',     label: 'Hypertension',     icon: 'heart-outline' },
  { value: 'high_cholesterol', label: 'High cholesterol', icon: 'pulse-outline' },
  { value: 'type_2_diabetes',  label: 'Type 2 diabetes',  icon: 'medkit-outline' },
  { value: 'pre_diabetes',     label: 'Pre-diabetes',     icon: 'medkit-outline' },
  { value: 'thyroid_issue',    label: 'Thyroid issue',    icon: 'pulse-outline' },
  { value: 'IBS',              label: 'IBS',              icon: 'leaf-outline' },
  { value: 'PCOS',             label: 'PCOS',             icon: 'female-outline' },
  { value: 'pregnancy',        label: 'Pregnancy',        icon: 'heart-outline' },
];

const COMMON_MEDS: ChipOpt[] = [
  { value: 'lisinopril',     label: 'Lisinopril' },
  { value: 'metformin',      label: 'Metformin' },
  { value: 'atorvastatin',   label: 'Atorvastatin' },
  { value: 'omeprazole',     label: 'Omeprazole' },
  { value: 'levothyroxine',  label: 'Levothyroxine' },
  { value: 'sertraline',     label: 'Sertraline' },
  { value: 'birth_control',  label: 'Birth control' },
  { value: 'multivitamin',   label: 'Multivitamin' },
  { value: 'vitamin_d',      label: 'Vitamin D' },
  { value: 'omega_3',        label: 'Omega-3' },
];

const PRONOUN_OPTIONS: ChipOpt[] = [
  { value: 'she/her',   label: 'she/her' },
  { value: 'he/him',    label: 'he/him' },
  { value: 'they/them', label: 'they/them' },
];

export function ProfileSetupScreen({ visible, mode, onFinish, onClose }: Props) {
  const insets = useSafeAreaInsets();
  const stepOrder = mode === 'setup' ? STEP_ORDER_SETUP : STEP_ORDER_EDIT;
  const [stepIndex, setStepIndex] = useState(0);
  const [draft, setDraft] = useState<DraftProfile>({});
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);

  // Pre-load existing profile on open so edit mode (and a returning
  // setup user) sees their last saved values.
  useEffect(() => {
    if (!visible) return;
    let cancelled = false;
    setLoading(true);
    fetchProfile()
      .then((p) => {
        if (cancelled) return;
        setDraft(p);
      })
      .catch(() => {
        // No profile yet — start blank
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    setStepIndex(0);
    return () => {
      cancelled = true;
    };
  }, [visible]);

  const step = stepOrder[stepIndex];
  const totalSteps = stepOrder.length;
  const isLast = stepIndex === totalSteps - 1;
  const progress = (stepIndex + 1) / totalSteps;

  // Booleans for the render guards so react-native/no-raw-text stops
  // flagging string literals that sit inside `step === '...'` /
  // `mode === '...'` JS comparisons as bare JSX text.
  const isSetupMode = mode === 'setup';
  const isEditMode = mode === 'edit';
  const showSkip = isSetupMode && !isLast;
  const onAbout = step === 'about';
  const onBody = step === 'body';
  const onLifestyle = step === 'lifestyle';
  const onHealth = step === 'health';
  const onReview = step === 'review';

  const persistAndContinue = async (
    advance: boolean,
    extra?: DraftProfile,
  ) => {
    setSaving(true);
    try {
      const merged = extra ? { ...draft, ...extra } : draft;
      const next = await updateProfile(merged);
      setDraft(next);
      if (advance) {
        if (isLast) {
          onFinish();
        } else {
          setStepIndex((i) => Math.min(i + 1, totalSteps - 1));
        }
      } else if (mode === 'edit') {
        onClose?.();
      }
    } catch (err) {
      Alert.alert('Could not save', (err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  const skip = () => {
    if (isLast) {
      onFinish();
      return;
    }
    setStepIndex((i) => i + 1);
  };

  const back = () => {
    if (stepIndex === 0) {
      onClose?.();
      return;
    }
    setStepIndex((i) => i - 1);
  };

  const updateDraft = (patch: DraftProfile) => {
    setDraft((d) => ({ ...d, ...patch }));
  };

  const toggleArrayValue = (field: keyof DraftProfile, value: string) => {
    setDraft((d) => {
      const list = (d[field] as string[] | undefined) ?? [];
      return {
        ...d,
        [field]: list.includes(value) ? list.filter((v) => v !== value) : [...list, value],
      };
    });
  };

  return (
    <KeyboardAvoidingView
      behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
      keyboardVerticalOffset={Platform.OS === 'ios' ? insets.top : 0}
      style={[styles.container, { paddingTop: insets.top, display: visible ? 'flex' : 'none' }]}
    >
      <View style={styles.headerRow}>
        <Pressable
          onPress={back}
          accessibilityLabel={stepIndex === 0 ? 'Close' : 'Back'}
          hitSlop={8}
          style={({ pressed }) => [styles.iconBtn, pressed && { opacity: 0.7 }]}
        >
          <Ionicons
            name={stepIndex === 0 ? (isEditMode ? 'close' : 'arrow-back') : 'chevron-back'}
            size={22}
            color={colors.textPrimary}
          />
        </Pressable>
        <View style={styles.progressTrack}>
          <View style={[styles.progressFill, { width: `${Math.round(progress * 100)}%` }]} />
        </View>
        {showSkip ? (
          <Pressable onPress={skip} hitSlop={8} style={styles.skipBtn}>
            <Text style={styles.skipText}>Skip</Text>
          </Pressable>
        ) : (
          <View style={styles.skipBtn} />
        )}
      </View>

      {loading ? (
        <View style={styles.centered}>
          <ActivityIndicator color={colors.primary} size="large" />
        </View>
      ) : (
        <ScrollView
          style={styles.body}
          contentContainerStyle={styles.bodyContent}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
          {onAbout && <AboutStep draft={draft} update={updateDraft} />}
          {onBody && <BodyStep draft={draft} update={updateDraft} />}
          {onLifestyle && (
            <LifestyleStep draft={draft} update={updateDraft} toggle={toggleArrayValue} />
          )}
          {onHealth && (
            <HealthStep
              draft={draft}
              update={updateDraft}
              toggle={toggleArrayValue}
            />
          )}
          {onReview && <ReviewStep draft={draft} />}
        </ScrollView>
      )}

      <View style={[styles.footer, { paddingBottom: insets.bottom + spacing.md }]}>
        <Pressable
          onPress={() => persistAndContinue(true)}
          disabled={saving}
          style={({ pressed }) => [
            styles.primaryBtn,
            (pressed || saving) && { opacity: 0.85 },
          ]}
        >
          {saving ? (
            <ActivityIndicator color={colors.textPrimary} />
          ) : (
            <Text style={styles.primaryBtnText}>
              {isEditMode ? 'Save' : isLast ? 'All set' : 'Continue'}
            </Text>
          )}
        </Pressable>
      </View>
      <StatusBar style="light" />
    </KeyboardAvoidingView>
  );
}

function AboutStep({ draft, update }: { draft: DraftProfile; update: (patch: DraftProfile) => void }) {
  return (
    <View>
      <Text style={styles.heading}>What should we call you?</Text>
      <Text style={styles.lead}>
        The name your wellness team uses when greeting you and tailoring advice.
      </Text>

      <FieldLabel label="Name" icon="person-outline" />
      <View style={styles.textInputRow}>
        <Ionicons name="person-outline" size={18} color={colors.textMuted} />
        <TextInput
          style={styles.textInputInline}
          value={draft.display_name ?? ''}
          onChangeText={(v) => update({ display_name: v })}
          placeholder="First name or full name"
          placeholderTextColor={colors.textMuted}
          autoCapitalize="words"
          returnKeyType="done"
        />
      </View>

      <FieldLabel label="Pronouns" sublabel="Optional" icon="chatbubble-ellipses-outline" />
      <ChipRow
        options={PRONOUN_OPTIONS}
        selected={draft.pronouns ? [draft.pronouns] : []}
        onToggle={(v) => update({ pronouns: draft.pronouns === v ? null : v })}
      />
    </View>
  );
}

function BodyStep({ draft, update }: { draft: DraftProfile; update: (patch: DraftProfile) => void }) {
  return (
    <View>
      <Text style={styles.heading}>Your body baseline</Text>
      <Text style={styles.lead}>
        Helps your team scale advice — calorie ranges, recovery, lab references.
      </Text>

      <FieldLabel label="Sex at birth" icon="body-outline" />
      <ChipRow
        options={SEX_OPTIONS.map((o) => ({ value: o.value, label: o.label, icon: o.icon }))}
        selected={draft.sex_at_birth ? [draft.sex_at_birth] : []}
        onToggle={(v) =>
          update({ sex_at_birth: draft.sex_at_birth === v ? null : (v as 'F' | 'M' | 'I') })
        }
      />

      <FieldLabel label="Height" icon="resize-outline" />
      <Stepper
        value={draft.height_cm ?? null}
        defaultValue={170}
        onChange={(n) => update({ height_cm: n })}
        min={120}
        max={230}
        smallStep={1}
        largeStep={5}
        unit="cm"
        icon="resize-outline"
      />

      <FieldLabel label="Weight" icon="speedometer-outline" />
      <Stepper
        value={draft.weight_kg ?? null}
        defaultValue={70}
        onChange={(n) => update({ weight_kg: n })}
        min={30}
        max={250}
        smallStep={1}
        largeStep={5}
        unit="kg"
        icon="speedometer-outline"
      />
    </View>
  );
}

function LifestyleStep({
  draft,
  update,
  toggle,
}: {
  draft: DraftProfile;
  update: (patch: DraftProfile) => void;
  toggle: (field: keyof DraftProfile, value: string) => void;
}) {
  return (
    <View>
      <Text style={styles.heading}>How active is your week?</Text>
      <Text style={styles.lead}>Plus a quick read on sleep + diet — pick what fits.</Text>

      <FieldLabel label="Activity level" icon="bicycle-outline" />
      <View style={styles.activityList}>
        {ACTIVITY_OPTIONS.map((opt) => {
          const selected = draft.activity_level === opt.value;
          return (
            <Pressable
              key={opt.value}
              onPress={() =>
                update({ activity_level: selected ? null : opt.value })
              }
              style={({ pressed }) => [
                styles.activityCard,
                selected && styles.activityCardSelected,
                pressed && { opacity: 0.85 },
              ]}
            >
              <View style={[
                styles.activityIcon,
                selected && styles.activityIconSelected,
              ]}>
                <Ionicons
                  name={opt.icon}
                  size={20}
                  color={selected ? colors.primary : colors.textSecondary}
                />
              </View>
              <View style={styles.activityText}>
                <Text style={[styles.activityLabel, selected && styles.activityLabelSelected]}>
                  {opt.label}
                </Text>
                <Text style={styles.activitySubtitle}>{opt.subtitle}</Text>
              </View>
              {selected && (
                <Ionicons name="checkmark-circle" size={20} color={colors.primary} />
              )}
            </Pressable>
          );
        })}
      </View>

      <FieldLabel label="Sleep target" sublabel="Hours per night" icon="moon-outline" />
      <Stepper
        value={draft.sleep_hours_target ?? null}
        defaultValue={8}
        onChange={(n) => update({ sleep_hours_target: n })}
        min={4}
        max={12}
        smallStep={1}
        largeStep={1}
        unit="h"
        icon="moon-outline"
      />

      <FieldLabel label="Goals" sublabel="Pick any" icon="rocket-outline" />
      <ChipRow
        options={GOAL_OPTIONS}
        selected={draft.goals ?? []}
        onToggle={(v) => toggle('goals', v)}
        wrap
      />

      <FieldLabel label="Diet" sublabel="Optional" icon="restaurant-outline" />
      <ChipRow
        options={DIETARY_OPTIONS}
        selected={draft.dietary_flags ?? []}
        onToggle={(v) => toggle('dietary_flags', v)}
        wrap
      />
    </View>
  );
}

function HealthStep({
  draft,
  toggle,
}: {
  draft: DraftProfile;
  update: (patch: DraftProfile) => void;
  toggle: (field: keyof DraftProfile, value: string) => void;
}) {
  return (
    <View>
      <Text style={styles.heading}>Anything we should know?</Text>
      <Text style={styles.lead}>
        Helps your team avoid suggesting things you can't or shouldn't take.
        Skip anything you'd rather not share — you can edit this later in Settings.
      </Text>

      <FieldLabel label="Conditions" sublabel="Optional" icon="medkit-outline" />
      <ChipRow
        options={CONDITION_OPTIONS}
        selected={draft.conditions ?? []}
        onToggle={(v) => toggle('conditions', v)}
        wrap
      />

      <FieldLabel
        label="Allergies"
        sublabel="We'll never recommend these"
        icon="alert-circle-outline"
      />
      <ChipRow
        options={ALLERGY_OPTIONS}
        selected={draft.allergies ?? []}
        onToggle={(v) => toggle('allergies', v)}
        wrap
      />

      <FieldLabel
        label="Medications"
        sublabel="Tap any you take regularly"
        icon="medical-outline"
      />
      <ChipRow
        options={COMMON_MEDS}
        selected={draft.medications ?? []}
        onToggle={(v) => toggle('medications', v)}
        wrap
      />
    </View>
  );
}

function ReviewStep({ draft }: { draft: DraftProfile }) {
  const summary = useMemo(() => buildSummary(draft), [draft]);
  return (
    <View>
      <Text style={styles.heading}>Looking good</Text>
      <Text style={styles.lead}>
        Here's what we'll share with your wellness team. Edit any of this in Settings any time.
      </Text>
      <View style={styles.summaryCard}>
        {summary.length === 0 ? (
          <Text style={styles.summaryEmpty}>No details added yet — that's OK.</Text>
        ) : (
          summary.map((row, i) => (
            <View key={i} style={styles.summaryRow}>
              <Text style={styles.summaryLabel}>{row.label}</Text>
              <Text style={styles.summaryValue}>{row.value}</Text>
            </View>
          ))
        )}
      </View>
    </View>
  );
}

function buildSummary(draft: DraftProfile): Array<{ label: string; value: string }> {
  const out: Array<{ label: string; value: string }> = [];
  if (draft.display_name) out.push({ label: 'Name', value: draft.display_name });
  if (draft.pronouns) out.push({ label: 'Pronouns', value: draft.pronouns });
  if (draft.sex_at_birth) {
    out.push({
      label: 'Sex at birth',
      value: draft.sex_at_birth === 'F' ? 'Female' : draft.sex_at_birth === 'M' ? 'Male' : 'Intersex',
    });
  }
  if (draft.height_cm) out.push({ label: 'Height', value: `${draft.height_cm} cm` });
  if (draft.weight_kg) out.push({ label: 'Weight', value: `${draft.weight_kg} kg` });
  if (draft.activity_level) out.push({ label: 'Activity', value: draft.activity_level });
  if (draft.sleep_hours_target) out.push({ label: 'Sleep target', value: `${draft.sleep_hours_target} h` });
  if (draft.goals?.length) out.push({ label: 'Goals', value: draft.goals.join(', ') });
  if (draft.dietary_flags?.length) out.push({ label: 'Diet', value: draft.dietary_flags.join(', ') });
  if (draft.allergies?.length) out.push({ label: 'Allergies', value: draft.allergies.join(', ') });
  if (draft.conditions?.length) out.push({ label: 'Conditions', value: draft.conditions.join(', ') });
  if (draft.medications?.length) out.push({ label: 'Medications', value: draft.medications.join(', ') });
  return out;
}

function FieldLabel({
  label,
  sublabel,
  icon,
}: {
  label: string;
  sublabel?: string;
  icon?: IconName;
}) {
  return (
    <View style={styles.fieldLabelRow}>
      <View style={styles.fieldLabelLeft}>
        {icon && <Ionicons name={icon} size={14} color={colors.textMuted} />}
        <Text style={styles.fieldLabel}>{label}</Text>
      </View>
      {sublabel && <Text style={styles.fieldSublabel}>{sublabel}</Text>}
    </View>
  );
}

function ChipRow({
  options,
  selected,
  onToggle,
  wrap,
}: {
  options: ChipOpt[];
  selected: string[];
  onToggle: (value: string) => void;
  wrap?: boolean;
}) {
  return (
    <View style={[styles.chipRow, wrap && styles.chipRowWrap]}>
      {options.map((opt) => {
        const isSelected = selected.includes(opt.value);
        return (
          <Pressable
            key={opt.value}
            onPress={() => onToggle(opt.value)}
            style={({ pressed }) => [
              styles.chip,
              isSelected && styles.chipSelected,
              pressed && { opacity: 0.85 },
            ]}
          >
            {opt.icon && (
              <Ionicons
                name={opt.icon}
                size={14}
                color={isSelected ? colors.textPrimary : colors.textMuted}
              />
            )}
            <Text style={[styles.chipText, isSelected && styles.chipTextSelected]}>
              {opt.label}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

/**
 * Tap-only numeric stepper — replaces the old TextInput so the
 * keyboard doesn't pop up and overlap the form. Five touch targets:
 *   [-large] [-small] [VALUE + icon] [+small] [+large]
 *
 * Empty state: a "Tap to set" pill, taps to commit defaultValue and
 * switches to the stepper UI. Skip button on the page header lets
 * users still bypass the field entirely.
 */
function Stepper({
  value,
  defaultValue,
  onChange,
  min,
  max,
  smallStep,
  largeStep,
  unit,
  icon,
}: {
  value: number | null;
  defaultValue: number;
  onChange: (n: number) => void;
  min: number;
  max: number;
  smallStep: number;
  largeStep: number;
  unit: string;
  icon?: IconName;
}) {
  const clamp = (n: number) => Math.max(min, Math.min(max, n));

  if (value == null) {
    return (
      <Pressable
        onPress={() => onChange(defaultValue)}
        style={({ pressed }) => [
          styles.stepperEmpty,
          pressed && { opacity: 0.85 },
        ]}
      >
        {icon && <Ionicons name={icon} size={18} color={colors.primary} />}
        <Text style={styles.stepperEmptyText}>Tap to set</Text>
      </Pressable>
    );
  }

  return (
    <View style={styles.stepperRow}>
      <Pressable
        onPress={() => onChange(clamp(value - largeStep))}
        hitSlop={6}
        style={({ pressed }) => [
          styles.stepperBtnSm,
          pressed && { opacity: 0.7 },
        ]}
      >
        <Text style={styles.stepperBtnTextSm}>−{largeStep}</Text>
      </Pressable>
      <Pressable
        onPress={() => onChange(clamp(value - smallStep))}
        hitSlop={6}
        style={({ pressed }) => [
          styles.stepperBtn,
          pressed && { opacity: 0.7 },
        ]}
      >
        <Ionicons name="remove" size={20} color={colors.textPrimary} />
      </Pressable>
      <View style={styles.stepperValue}>
        {icon && <Ionicons name={icon} size={18} color={colors.primary} />}
        <Text style={styles.stepperValueText}>{value}</Text>
        <Text style={styles.stepperUnitText}>{unit}</Text>
      </View>
      <Pressable
        onPress={() => onChange(clamp(value + smallStep))}
        hitSlop={6}
        style={({ pressed }) => [
          styles.stepperBtn,
          pressed && { opacity: 0.7 },
        ]}
      >
        <Ionicons name="add" size={20} color={colors.textPrimary} />
      </Pressable>
      <Pressable
        onPress={() => onChange(clamp(value + largeStep))}
        hitSlop={6}
        style={({ pressed }) => [
          styles.stepperBtnSm,
          pressed && { opacity: 0.7 },
        ]}
      >
        <Text style={styles.stepperBtnTextSm}>+{largeStep}</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background,
  },
  headerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    gap: spacing.sm,
  },
  iconBtn: {
    width: 36,
    height: 36,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  skipBtn: {
    minWidth: 44,
    height: 36,
    alignItems: 'flex-end',
    justifyContent: 'center',
    paddingHorizontal: spacing.sm,
  },
  skipText: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    fontWeight: '600',
  },
  progressTrack: {
    flex: 1,
    height: 4,
    borderRadius: 2,
    backgroundColor: colors.surface,
    overflow: 'hidden',
  },
  progressFill: {
    height: 4,
    backgroundColor: colors.primary,
    borderRadius: 2,
  },
  body: { flex: 1 },
  bodyContent: {
    padding: spacing.lg,
    // Generous bottom padding so a focused text input can always
    // scroll above the on-screen keyboard on Android, where
    // KeyboardAvoidingView's "height" mode resizes the wrapper.
    paddingBottom: spacing.xxl + spacing.xl,
  },
  centered: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
  },
  heading: {
    color: colors.textPrimary,
    fontSize: 26,
    fontWeight: '800',
    letterSpacing: -0.5,
    marginBottom: spacing.sm,
  },
  lead: {
    color: colors.textSecondary,
    fontSize: fontSize.md,
    lineHeight: 22,
    marginBottom: spacing.lg,
  },
  fieldLabelRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginTop: spacing.md,
    marginBottom: spacing.xs + 2,
  },
  fieldLabelLeft: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs + 2,
  },
  fieldLabel: {
    color: colors.textPrimary,
    fontSize: fontSize.sm,
    fontWeight: '700',
    letterSpacing: 0.2,
  },
  fieldSublabel: {
    color: colors.textMuted,
    fontSize: fontSize.xs,
  },
  textInput: {
    backgroundColor: colors.surface,
    color: colors.textPrimary,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm + 4,
    fontSize: fontSize.md,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.08)',
  },
  textInputRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.surface,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.08)',
  },
  textInputInline: {
    flex: 1,
    color: colors.textPrimary,
    paddingVertical: spacing.sm + 4,
    fontSize: fontSize.md,
  },
  chipRow: {
    flexDirection: 'row',
    gap: spacing.xs + 2,
    flexWrap: 'wrap',
  },
  chipRowWrap: {},
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.1)',
  },
  chipSelected: {
    backgroundColor: colors.primary,
    borderColor: colors.primary,
  },
  chipText: {
    color: colors.textSecondary,
    fontSize: fontSize.sm,
    fontWeight: '600',
  },
  chipTextSelected: {
    color: colors.textPrimary,
  },
  stepperEmpty: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.sm,
    paddingVertical: spacing.md - 2,
    paddingHorizontal: spacing.lg,
    borderRadius: radius.md,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: 'rgba(124,92,255,0.4)',
    borderStyle: 'dashed',
  },
  stepperEmptyText: {
    color: colors.primary,
    fontSize: fontSize.md,
    fontWeight: '700',
  },
  stepperRow: {
    flexDirection: 'row',
    alignItems: 'stretch',
    gap: spacing.xs + 2,
  },
  stepperBtnSm: {
    minWidth: 48,
    height: 56,
    borderRadius: radius.md,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.08)',
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing.sm,
  },
  stepperBtnTextSm: {
    color: colors.textSecondary,
    fontSize: fontSize.sm,
    fontWeight: '700',
  },
  stepperBtn: {
    width: 48,
    height: 56,
    borderRadius: radius.md,
    backgroundColor: colors.surfaceElevated,
    alignItems: 'center',
    justifyContent: 'center',
  },
  stepperValue: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    height: 56,
    borderRadius: radius.md,
    backgroundColor: 'rgba(124,92,255,0.1)',
    borderWidth: 1,
    borderColor: 'rgba(124,92,255,0.3)',
  },
  stepperValueText: {
    color: colors.textPrimary,
    fontSize: 22,
    fontWeight: '800',
    letterSpacing: -0.5,
  },
  stepperUnitText: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    fontWeight: '600',
  },
  activityList: {
    gap: spacing.sm,
  },
  activityCard: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.surface,
    borderRadius: radius.md,
    padding: spacing.md,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.08)',
  },
  activityCardSelected: {
    borderColor: colors.primary,
    backgroundColor: 'rgba(124,92,255,0.12)',
  },
  activityIcon: {
    width: 40,
    height: 40,
    borderRadius: radius.pill,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,0.06)',
  },
  activityIconSelected: {
    backgroundColor: 'rgba(124,92,255,0.2)',
  },
  activityText: { flex: 1 },
  activityLabel: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
    marginBottom: 2,
  },
  activityLabelSelected: {
    color: colors.primary,
  },
  activitySubtitle: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
  },
  summaryCard: {
    backgroundColor: colors.surface,
    borderRadius: radius.md,
    padding: spacing.md,
    gap: spacing.sm,
  },
  summaryRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
  },
  summaryLabel: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    fontWeight: '600',
  },
  summaryValue: {
    color: colors.textPrimary,
    fontSize: fontSize.sm,
    flex: 1,
    textAlign: 'right',
    marginLeft: spacing.md,
  },
  summaryEmpty: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    textAlign: 'center',
  },
  footer: {
    paddingHorizontal: spacing.lg,
    paddingTop: spacing.md,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: 'rgba(255,255,255,0.08)',
  },
  primaryBtn: {
    backgroundColor: colors.primary,
    paddingVertical: spacing.md,
    borderRadius: radius.pill,
    alignItems: 'center',
  },
  primaryBtnText: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
    letterSpacing: 0.3,
  },
});
