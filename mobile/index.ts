import { registerRootComponent } from 'expo';

import App from './App';
import { registerLiveKitGlobals } from './src/voice/liveKitBootstrap';
// Importing the i18n module is enough to call its init() side-effect at
// module load time, before any screen renders. bootstrapLanguage() runs
// inside App on mount to apply the persisted choice.
import './src/i18n';

// LiveKit polyfills RTCPeerConnection / MediaStream / etc on global
// before livekit-client imports run. Must happen once, at the very
// top, before any other module reaches for those names.
registerLiveKitGlobals();

// registerRootComponent calls AppRegistry.registerComponent('main', () => App);
// It also ensures that whether you load the app in Expo Go or in a native build,
// the environment is set up appropriately
registerRootComponent(App);
