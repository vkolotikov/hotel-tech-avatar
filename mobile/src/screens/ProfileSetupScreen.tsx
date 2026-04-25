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

const ACTIVITY_OPTIONS: Array<{ value: NonNullable<UserProfile['activity_level']>; label: string; subtitle: string }> = [
  { value: 'sedentary', label: 'Sedentary', subtitle: 'Mostly sitting, little walking' },
  { value: 'light',     label: 'Light',     subtitle: 'A few short walks each week' },
  { value: 'moderate',  label: 'Moderate',  subtitle: 'Exercise 3–4 days a week' },
  { value: 'active',    label: 'Active',    subtitle: 'Hard training 5–6 days a week' },
  { value: 'athlete',   label: 'Athlete',   subtitle: 'Competitive / elite training' },
];

const SEX_OPTIONS: Array<{ value: NonNullable<UserProfile['sex_at_birth']>; label: string }> = [
  { value: 'F', label: 'Female' },
  { value: 'M', label: 'Male' },
  { value: 'I', label: 'Intersex' },
];

const GOAL_OPTIONS = [
  { value: 'better_sleep',     label: 'Sleep better' },
  { value: 'more_energy',      label: 'More energy' },
  { value: 'weight_loss',      label: 'Lose weight' },
  { value: 'muscle_gain',      label: 'Build muscle' },
  { value: 'gut_health',       label: 'Gut health' },
  { value: 'stress_management',label: 'Manage stress' },
  { value: 'longevity',        label: 'Longevity' },
  { value: 'general_wellbeing',label: 'General wellbeing' },
];

const DIETARY_OPTIONS = [
  { value: 'vegetarian',     label: 'Vegetarian' },
  { value: 'vegan',          label: 'Vegan' },
  { value: 'pescatarian',    label: 'Pescatarian' },
  { value: 'gluten_free',    label: 'Gluten-free' },
  { value: 'dairy_free',     label: 'Dairy-free' },
  { value: 'low_carb',       label: 'Low-carb' },
  { value: 'keto',           label: 'Keto' },
  { value: 'mediterranean',  label: 'Mediterranean' },
  { value: 'halal',          label: 'Halal' },
  { value: 'kosher',         label: 'Kosher' },
];

const ALLERGY_OPTIONS = [
  { value: 'peanuts',     label: 'Peanuts' },
  { value: 'tree_nuts',   label: 'Tree nuts' },
  { value: 'dairy',       label: 'Dairy' },
  { value: 'eggs',        label: 'Eggs' },
  { value: 'shellfish',   label: 'Shellfish' },
  { value: 'fish',        label: 'Fish' },
  { value: 'soy',         label: 'Soy' },
  { value: 'gluten',      label: 'Gluten' },
  { value: 'sesame',      label: 'Sesame' },
];

const CONDITION_OPTIONS = [
  { value: 'hypertension',     label: 'Hypertension' },
  { value: 'high_cholesterol', label: 'High cholesterol' },
  { value: 'type_2_diabetes',  label: 'Type 2 diabetes' },
  { value: 'pre_diabetes',     label: 'Pre-diabetes' },
  { value: 'thyroid_issue',    label: 'Thyroid issue' },
  { value: 'IBS',              label: 'IBS' },
  { value: 'PCOS',             label: 'PCOS' },
  { value: 'pregnancy',        label: 'Pregnancy' },
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
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
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

      <FieldLabel label="Name" />
      <TextInput
        style={styles.textInput}
        value={draft.display_name ?? ''}
        onChangeText={(v) => update({ display_name: v })}
        placeholder="First name or full name"
        placeholderTextColor={colors.textMuted}
        autoCapitalize="words"
      />

      <FieldLabel label="Pronouns" sublabel="Optional" />
      <ChipRow
        options={[
          { value: 'she/her', label: 'she/her' },
          { value: 'he/him', label: 'he/him' },
          { value: 'they/them', label: 'they/them' },
        ]}
        selected={draft.pronouns ? [draft.pronouns] : []}
        onToggle={(v) => update({ pronouns: draft.pronouns === v ? null : v })}
      />
      <TextInput
        style={[styles.textInput, { marginTop: spacing.sm }]}
        value={draft.pronouns && !['she/her', 'he/him', 'they/them'].includes(draft.pronouns) ? draft.pronouns : ''}
        onChangeText={(v) => update({ pronouns: v || null })}
        placeholder="…or type your own"
        placeholderTextColor={colors.textMuted}
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

      <FieldLabel label="Sex at birth" />
      <ChipRow
        options={SEX_OPTIONS.map((o) => ({ value: o.value, label: o.label }))}
        selected={draft.sex_at_birth ? [draft.sex_at_birth] : []}
        onToggle={(v) =>
          update({ sex_at_birth: draft.sex_at_birth === v ? null : (v as 'F' | 'M' | 'I') })
        }
      />

      <FieldLabel label="Height (cm)" />
      <NumberInput
        value={draft.height_cm ?? null}
        onChange={(n) => update({ height_cm: n })}
        min={50}
        max={260}
        placeholder="e.g. 178"
      />

      <FieldLabel label="Weight (kg)" />
      <NumberInput
        value={draft.weight_kg ?? null}
        onChange={(n) => update({ weight_kg: n })}
        min={20}
        max={400}
        placeholder="e.g. 75"
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

      <FieldLabel label="Activity level" />
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
              <Text style={[styles.activityLabel, selected && styles.activityLabelSelected]}>
                {opt.label}
              </Text>
              <Text style={styles.activitySubtitle}>{opt.subtitle}</Text>
            </Pressable>
          );
        })}
      </View>

      <FieldLabel label="Sleep target (hours per night)" sublabel="Optional" />
      <NumberInput
        value={draft.sleep_hours_target ?? null}
        onChange={(n) => update({ sleep_hours_target: n })}
        min={3}
        max={14}
        placeholder="e.g. 8"
      />

      <FieldLabel label="Goals" sublabel="Pick any" />
      <ChipRow
        options={GOAL_OPTIONS}
        selected={draft.goals ?? []}
        onToggle={(v) => toggle('goals', v)}
        wrap
      />

      <FieldLabel label="Diet" sublabel="Optional" />
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
  update,
  toggle,
}: {
  draft: DraftProfile;
  update: (patch: DraftProfile) => void;
  toggle: (field: keyof DraftProfile, value: string) => void;
}) {
  // Local string state for the medications text input — committed
  // back to the draft as a parsed array on every change so saves
  // pick up whatever the user has typed at any step boundary.
  const [medsRaw, setMedsRaw] = useState(
    (draft.medications ?? []).join(', '),
  );

  useEffect(() => {
    // Pull from draft when the screen re-mounts with a freshly loaded
    // profile — without this the input goes blank after fetchProfile.
    setMedsRaw((draft.medications ?? []).join(', '));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [draft.medications?.length]);

  const handleMedsChange = (v: string) => {
    setMedsRaw(v);
    const list = v
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean);
    update({ medications: list });
  };

  return (
    <View>
      <Text style={styles.heading}>Anything we should know?</Text>
      <Text style={styles.lead}>
        Helps your team avoid suggesting things you can't or shouldn't take.
        Skip anything you'd rather not share — you can edit this later in Settings.
      </Text>

      <FieldLabel label="Conditions" sublabel="Optional" />
      <ChipRow
        options={CONDITION_OPTIONS}
        selected={draft.conditions ?? []}
        onToggle={(v) => toggle('conditions', v)}
        wrap
      />

      <FieldLabel label="Allergies" sublabel="We'll never recommend these" />
      <ChipRow
        options={ALLERGY_OPTIONS}
        selected={draft.allergies ?? []}
        onToggle={(v) => toggle('allergies', v)}
        wrap
      />

      <FieldLabel label="Medications" sublabel="Optional — comma-separated" />
      <TextInput
        style={[styles.textInput, { minHeight: 60 }]}
        value={medsRaw}
        onChangeText={handleMedsChange}
        placeholder="e.g. lisinopril, metformin"
        placeholderTextColor={colors.textMuted}
        multiline
        autoCorrect={false}
        autoCapitalize="none"
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

function FieldLabel({ label, sublabel }: { label: string; sublabel?: string }) {
  return (
    <View style={styles.fieldLabelRow}>
      <Text style={styles.fieldLabel}>{label}</Text>
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
  options: Array<{ value: string; label: string }>;
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
            <Text style={[styles.chipText, isSelected && styles.chipTextSelected]}>
              {opt.label}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

function NumberInput({
  value,
  onChange,
  min,
  max,
  placeholder,
}: {
  value: number | null;
  onChange: (n: number | null) => void;
  min: number;
  max: number;
  placeholder: string;
}) {
  const [raw, setRaw] = useState(value !== null ? String(value) : '');

  useEffect(() => {
    setRaw(value !== null ? String(value) : '');
  }, [value]);

  const commit = (s: string) => {
    setRaw(s);
    if (s.trim() === '') {
      onChange(null);
      return;
    }
    const n = parseInt(s, 10);
    if (Number.isNaN(n)) return;
    if (n < min || n > max) return;
    onChange(n);
  };

  return (
    <TextInput
      style={styles.textInput}
      value={raw}
      onChangeText={commit}
      placeholder={placeholder}
      placeholderTextColor={colors.textMuted}
      keyboardType="number-pad"
    />
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
    paddingBottom: spacing.xl,
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
    alignItems: 'baseline',
    justifyContent: 'space-between',
    marginTop: spacing.md,
    marginBottom: spacing.xs + 2,
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
  chipRow: {
    flexDirection: 'row',
    gap: spacing.xs + 2,
    flexWrap: 'wrap',
  },
  chipRowWrap: {},
  chip: {
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
  activityList: {
    gap: spacing.sm,
  },
  activityCard: {
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
