import { registerRootComponent } from 'expo';

import App from './App';
import { registerLiveKitGlobals } from './src/voice/liveKitBootstrap';

// LiveKit polyfills RTCPeerConnection / MediaStream / etc on global
// before livekit-client imports run. Must happen once, at the very
// top, before any other module reaches for those names.
registerLiveKitGlobals();

// registerRootComponent calls AppRegistry.registerComponent('main', () => App);
// It also ensures that whether you load the app in Expo Go or in a native build,
// the environment is set up appropriately
registerRootComponent(App);
