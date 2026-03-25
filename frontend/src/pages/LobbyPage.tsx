import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { getAgents, type Agent } from '../api/endpoints';
import { getAgentExpertProfile } from '../utils/agentAssets';
import { assetUrl } from '../api/client';
import '../styles/lobby.css';

export default function LobbyPage() {
  const [agents, setAgents] = useState<Agent[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;

    getAgents()
      .then((data) => {
        if (!active) {
          return;
        }

        setAgents(data);
        setError(null);
      })
      .catch((err) => {
        if (!active) {
          return;
        }

        setAgents([]);
        setError(err instanceof Error ? err.message : 'Failed to load agents');
      })
      .finally(() => {
        if (active) {
          setLoading(false);
        }
      });

    return () => {
      active = false;
    };
  }, []);

  return (
    <div className="lobby-page" style={{ '--lobby-bg': `url('${assetUrl('/assets/backgrounds/lobby-hd.png')}')` } as React.CSSProperties}>
      <div className="lobby-page__overlay">
        <header className="lobby-page__header">
          <h1>AvatarHub</h1>
          <p>Choose an agent to start a conversation.</p>
        </header>

        {loading && <div className="lobby-page__state">Loading experts...</div>}
        {error && (
          <div className="lobby-page__state lobby-page__state--error">
            Failed to load avatars.
          </div>
        )}
        {!loading && !error && agents.length === 0 && (
          <div className="lobby-page__state">No published avatars available.</div>
        )}

        <div className="lobby-grid">
          {agents.map((agent) => {
            const profile = getAgentExpertProfile(agent);
            const avatar = assetUrl(agent.avatar_image_url || profile?.avatar || '/assets/avatars/marketing-expert.png');
            const name = agent.name || profile?.name || 'Avatar';
            const role = agent.role || profile?.role || 'Assistant';

            return (
              <Link key={agent.id} to={`/chat/${agent.id}`} className="lobby-agent">
                <img
                  className="lobby-agent__avatar"
                  src={avatar}
                  alt={`${name} avatar`}
                />
                <div className="lobby-agent__name">{name}</div>
                <div className="lobby-agent__role">{role}</div>
              </Link>
            );
          })}
        </div>
      </div>
    </div>
  );
}
