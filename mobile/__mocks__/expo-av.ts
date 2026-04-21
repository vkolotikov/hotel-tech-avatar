const requestPermissionsAsync = jest.fn().mockResolvedValue({ granted: true });
const setAudioModeAsync = jest.fn().mockResolvedValue(undefined);

class Recording {
  stopAndUnloadAsync = jest.fn().mockResolvedValue(undefined);
  getURI = jest.fn().mockReturnValue('file:///tmp/recording.m4a');
}

const createAsync = jest.fn().mockImplementation(async () => ({
  recording: new Recording(),
}));

export const Audio = {
  requestPermissionsAsync,
  setAudioModeAsync,
  Recording: Object.assign(Recording, { createAsync }),
  RecordingOptionsPresets: { HIGH_QUALITY: {} },
};
