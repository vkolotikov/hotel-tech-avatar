import { Routes, Route, Navigate } from 'react-router-dom';
import OfflineBanner from './components/OfflineBanner';
import LobbyPage from './pages/LobbyPage';
import ChatPage from './pages/ChatPage';

export default function App() {
  return (
    <div className="app">
      <OfflineBanner />
      <Routes>
        <Route path="/" element={<LobbyPage />} />
        <Route path="/chat/:id" element={<ChatPage />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </div>
  );
}
