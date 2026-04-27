import { request } from './index';

export type SexAtBirth = 'F' | 'M' | 'I' | null;

export type ActivityLevel =
  | 'sedentary'
  | 'light'
  | 'moderate'
  | 'active'
  | 'athlete'
  | null;

export type AgeBand =
  | 'under-18'
  | '18-24'
  | '25-34'
  | '35-44'
  | '45-54'
  | '55-64'
  | '65+'
  | null;

export type JobType = 'desk' | 'mixed' | 'feet' | 'physical' | 'shift' | 'none' | null;
export type TimeBand = 'under-15' | '15-30' | '15-60' | '30-60' | '60-120' | '60+' | '120+' | null;
export type SleepQuality = 'great' | 'okay' | 'poor' | null;
export type Chronotype = 'morning' | 'night' | 'shift' | null;
export type SmokingStatus = 'never' | 'quit' | 'occasional' | 'daily' | null;
export type AlcoholFreq = 'none' | 'light' | 'moderate' | 'heavy' | null;
export type CaffeineFreq = 'none' | '1-2' | '3-4' | '5+' | null;
export type StressLevel = 'low' | 'medium' | 'high' | null;
export type EatingPattern =
  | 'omnivore'
  | 'pescatarian'
  | 'vegetarian'
  | 'vegan'
  | 'mediterranean'
  | 'keto'
  | 'paleo'
  | 'no-specific'
  | null;
export type EatingSchedule = '3-meals' | '2-meals' | 'if' | 'snacky' | 'skip-breakfast' | null;
export type CookingSkill = 'none' | 'basic' | 'intermediate' | 'advanced' | null;
export type LivingSituation = 'alone' | 'partner' | 'family-kids' | 'parents' | 'roommates' | null;
export type TravelFrequency = 'rarely' | 'monthly' | 'weekly' | null;
export type FemaleStatus =
  | 'regular'
  | 'irregular'
  | 'trying'
  | 'pregnant'
  | 'breastfeeding'
  | 'perimenopause'
  | 'menopause'
  | 'post-menopause'
  | 'prefer-not-to-say'
  | null;
export type Contraception =
  | 'none'
  | 'pill'
  | 'iud-copper'
  | 'iud-hormonal'
  | 'implant'
  | 'patch'
  | 'ring'
  | 'injection'
  | 'natural'
  | 'prefer-not-to-say'
  | null;
export type MotivationTrigger =
  | 'health-scare'
  | 'event'
  | 'birthday'
  | 'doctor'
  | 'energy'
  | 'specific-goal'
  | 'ready'
  | 'other'
  | null;
export type GoalTimeline = 'weeks' | 'months' | 'year' | 'no-deadline' | null;

export type CoachingTone = 'friendly' | 'expert' | 'direct' | 'gentle' | null;
export type CoachingDetail = 'brief' | 'balanced' | 'thorough' | null;
export type CoachingPace = 'slow' | 'fast' | null;
export type CoachingStyle = 'routines' | 'variety' | null;
export type AccountabilityStyle = 'solo' | 'track' | 'coach' | 'compete' | null;

export type UserProfile = {
  // Identity
  display_name: string | null;
  pronouns: string | null;
  // Demographics
  birth_year: number | null;
  age_band: AgeBand;
  ethnicity: string[];
  // Body
  sex_at_birth: SexAtBirth;
  height_cm: number | null;
  weight_kg: number | null;
  waist_cm: number | null;
  // Day shape
  activity_level: ActivityLevel;
  job_type: JobType;
  tracks_steps: boolean | null;
  outdoor_minutes_band: TimeBand;
  wellness_time_band: TimeBand;
  // Sleep
  sleep_hours_target: number | null;
  sleep_quality: SleepQuality;
  chronotype: Chronotype;
  // Habits
  smoking_status: SmokingStatus;
  alcohol_freq: AlcoholFreq;
  caffeine_freq: CaffeineFreq;
  stress_level: StressLevel;
  // Eating
  eating_pattern: EatingPattern;
  eating_schedule: EatingSchedule;
  cooking_skill: CookingSkill;
  cooking_time_band: TimeBand;
  dietary_flags: string[];
  allergies: string[];
  intolerances: string[];
  // Health
  goals: string[];
  conditions: string[];
  medications: string[];
  family_history: string[];
  past_injuries: string[];
  mental_health: string[];
  // Life
  living_situation: LivingSituation;
  travel_frequency: TravelFrequency;
  budget_conscious: boolean | null;
  // Female health
  female_status: FemaleStatus;
  pregnancy_weeks: number | null;
  breastfeeding_months: number | null;
  cycle_length_days: number | null;
  contraception: Contraception;
  // Motivation
  motivation_trigger: MotivationTrigger;
  motivation_text: string | null;
  goal_timeline: GoalTimeline;
  goal_confidence: number | null;
  // Coaching style
  coaching_tone: CoachingTone;
  coaching_detail: CoachingDetail;
  coaching_pace: CoachingPace;
  coaching_style: CoachingStyle;
  accountability_style: AccountabilityStyle;
};

export type UpdateProfilePayload = Partial<UserProfile>;

export async function fetchProfile(): Promise<UserProfile> {
  const body = await request<{ profile: UserProfile }>('/api/v1/me/profile', {
    auth: true,
  });
  return body.profile;
}

export async function updateProfile(
  patch: UpdateProfilePayload,
): Promise<UserProfile> {
  const body = await request<{ profile: UserProfile }>('/api/v1/me/profile', {
    method: 'PATCH',
    auth: true,
    body: JSON.stringify(patch),
  });
  return body.profile;
}
