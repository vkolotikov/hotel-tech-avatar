// --- SaaS Auth ---
const AUTH_TOKEN_KEY = 'avatar_admin_token';
const AUTH_USER_KEY = 'avatar_admin_user';

const getAuthToken = () => sessionStorage.getItem(AUTH_TOKEN_KEY) || '';
const setAuthToken = (token, user) => {
  sessionStorage.setItem(AUTH_TOKEN_KEY, token);
  if (user) sessionStorage.setItem(AUTH_USER_KEY, JSON.stringify(user));
};
const clearAuth = () => {
  sessionStorage.removeItem(AUTH_TOKEN_KEY);
  sessionStorage.removeItem(AUTH_USER_KEY);
};

const state = {
  agents: [],
  assets: { avatars: [], backgrounds: [], openai_default_model: 'gpt-5.4', openai_default_voice: 'alloy' },
  currentAgentId: null,
  currentKnowledgeStatus: null,
};

const elements = {
  list: document.getElementById('agents-list'),
  newAgent: document.getElementById('new-agent'),
  form: document.getElementById('agent-form'),
  title: document.getElementById('form-title'),
  status: document.getElementById('status'),
  previewAvatar: document.getElementById('preview-avatar'),
  previewBackground: document.getElementById('preview-background'),
  previewName: document.getElementById('preview-name'),
  previewRole: document.getElementById('preview-role'),
  slug: document.getElementById('slug'),
  name: document.getElementById('name'),
  role: document.getElementById('role'),
  description: document.getElementById('description'),
  isPublished: document.getElementById('is_published'),
  avatarImageUrl: document.getElementById('avatar_image_url'),
  chatBackgroundUrl: document.getElementById('chat_background_url'),
  avatarGallery: document.getElementById('avatar-gallery'),
  backgroundGallery: document.getElementById('background-gallery'),
  systemInstructions: document.getElementById('system_instructions'),
  knowledgeText: document.getElementById('knowledge_text'),
  knowledgeFiles: document.getElementById('knowledge_files'),
  openAiModel: document.getElementById('openai_model'),
  openAiVoice: document.getElementById('openai_voice'),
  useAdvancedAi: document.getElementById('use_advanced_ai'),
  deleteAgent: document.getElementById('delete-agent'),
  knowledgeDropzone: document.getElementById('knowledge-dropzone'),
  knowledgeUploadInput: document.getElementById('knowledge_upload_input'),
  knowledgeUploadButton: document.getElementById('knowledge-upload-button'),
  reindexKnowledge: document.getElementById('reindex-knowledge'),
  knowledgeSyncChip: document.getElementById('knowledge-sync-chip'),
  knowledgeSyncMeta: document.getElementById('knowledge-sync-meta'),
  knowledgeSyncError: document.getElementById('knowledge-sync-error'),
  usageInfoButton: document.getElementById('usage-info-button'),
  usageModal: document.getElementById('usage-modal'),
  usageContent: document.getElementById('usage-content'),
  usageRangeDays: document.getElementById('usage-range-days'),
  usageRefreshButton: document.getElementById('usage-refresh-button'),
  usageCloseButton: document.getElementById('usage-close-button'),
  usageCloseTargets: document.querySelectorAll('[data-usage-close]'),
};

function setStatus(message, isError = false) {
  elements.status.textContent = message;
  elements.status.classList.toggle('status--error', isError);
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function formatNumber(value) {
  const numeric = Number(value);

  if (!Number.isFinite(numeric)) {
    return '0';
  }

  return numeric.toLocaleString();
}

function formatSyncDate(value) {
  if (!value) {
    return 'Not synced yet';
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  return date.toLocaleString();
}

function setKnowledgeSyncStatus(status, meta = '', error = '') {
  const normalized = typeof status === 'string' && status.trim() !== '' ? status.trim().toLowerCase() : 'idle';
  const supported = ['idle', 'syncing', 'synced', 'failed'];
  const mode = supported.includes(normalized) ? normalized : 'idle';

  elements.knowledgeSyncChip.textContent = mode.charAt(0).toUpperCase() + mode.slice(1);
  elements.knowledgeSyncChip.className = `knowledge-sync__chip knowledge-sync__chip--${mode}`;
  elements.knowledgeSyncMeta.textContent = meta || 'Not synced yet';

  if (error && String(error).trim() !== '') {
    elements.knowledgeSyncError.hidden = false;
    elements.knowledgeSyncError.textContent = String(error);
  } else {
    elements.knowledgeSyncError.hidden = true;
    elements.knowledgeSyncError.textContent = '';
  }
}

function updateReindexButtonState() {
  const canReindex = state.currentAgentId !== null
    && elements.useAdvancedAi.checked
    && !elements.useAdvancedAi.disabled;

  elements.reindexKnowledge.disabled = !canReindex;
}

// Detect API base path from current page location (works under subdirectory or standalone)
const API_BASE = (() => {
  const idx = window.location.pathname.lastIndexOf('/admin/');
  return idx > 0 ? window.location.pathname.substring(0, idx) : '';
})();

async function api(path, options = {}) {
  const isFormData = options.body instanceof FormData;
  const headers = { ...(options.headers || {}) };

  if (!isFormData) {
    headers['Content-Type'] = 'application/json';
  }

  // Inject auth token for admin routes
  const token = getAuthToken();
  if (token && path.includes('/admin/')) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const response = await fetch(API_BASE + path, {
    ...options,
    headers,
  });

  // If unauthorized, redirect to login
  if (response.status === 401 && path.includes('/admin/')) {
    clearAuth();
    showLoginScreen();
    throw new Error('Session expired. Please sign in again.');
  }

  const contentType = response.headers.get('content-type') || '';
  const payload = contentType.includes('application/json')
    ? await response.json()
    : await response.text();

  if (!response.ok) {
    let message = `Request failed (${response.status})`;

    if (payload && typeof payload === 'object' && 'error' in payload) {
      message = String(payload.error);

      if (
        payload.error === 'schema_not_ready'
        && Array.isArray(payload.missing_columns)
        && payload.missing_columns.length > 0
      ) {
        message = `Schema not ready. Run SQL migration for: ${payload.missing_columns.join(', ')}`;
      } else if (
        payload.error === 'schema_not_ready'
        && Array.isArray(payload.missing_tables)
        && payload.missing_tables.length > 0
      ) {
        message = `Schema not ready. Missing tables: ${payload.missing_tables.join(', ')}`;
      }
    }

    throw new Error(message);
  }

  return payload;
}

function normalizeKnowledgeFiles(value) {
  return value
    .split(/\r?\n|,/)
    .map((item) => item.trim())
    .filter((item) => item.length > 0);
}

function isPublished(agent) {
  if (typeof agent.is_published === 'boolean') {
    return agent.is_published;
  }

  if (typeof agent.is_published === 'number') {
    return agent.is_published !== 0;
  }

  if (typeof agent.is_published === 'string') {
    const normalized = agent.is_published.trim().toLowerCase();
    return normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on';
  }

  return true;
}

function fillModelSelect(select, models) {
  select.innerHTML = '';

  const noneOption = document.createElement('option');
  noneOption.value = '';
  noneOption.textContent = '-- None --';
  select.appendChild(noneOption);

  (models || []).forEach((model) => {
    const option = document.createElement('option');
    option.value = model;
    option.textContent = model;
    select.appendChild(option);
  });
}

function setModelValue(value) {
  const model = (value || '').trim();
  if (!model) {
    elements.openAiModel.value = '';
    return;
  }

  const optionExists = Array.from(elements.openAiModel.options).some(
    (option) => option.value === model
  );

  if (!optionExists) {
    const option = document.createElement('option');
    option.value = model;
    option.textContent = `${model} (custom)`;
    elements.openAiModel.appendChild(option);
  }

  elements.openAiModel.value = model;
}

function fillVoiceSelect(select, voices) {
  select.innerHTML = '';

  const noneOption = document.createElement('option');
  noneOption.value = '';
  noneOption.textContent = '-- Default --';
  select.appendChild(noneOption);

  (voices || []).forEach((voice) => {
    const option = document.createElement('option');
    option.value = voice;
    option.textContent = voice;
    select.appendChild(option);
  });
}

function setVoiceValue(value) {
  const voice = (value || '').trim();
  if (!voice) {
    elements.openAiVoice.value = '';
    return;
  }

  const optionExists = Array.from(elements.openAiVoice.options).some(
    (option) => option.value === voice
  );

  if (!optionExists) {
    const option = document.createElement('option');
    option.value = voice;
    option.textContent = `${voice} (custom)`;
    elements.openAiVoice.appendChild(option);
  }

  elements.openAiVoice.value = voice;
}

function getDefaultVoice() {
  return (state.assets.openai_default_voice || '').trim() || 'alloy';
}

function getDefaultModel() {
  return (state.assets.openai_default_model || '').trim() || 'gpt-5.4';
}

function basename(path) {
  const parts = path.split('/');
  return parts[parts.length - 1] || path;
}

function renderAssetGallery(type) {
  const isAvatar = type === 'avatar';
  const gallery = isAvatar ? elements.avatarGallery : elements.backgroundGallery;
  const select = isAvatar ? elements.avatarImageUrl : elements.chatBackgroundUrl;
  const items = isAvatar ? (state.assets.avatars || []) : (state.assets.backgrounds || []);

  gallery.innerHTML = '';

  const noneButton = document.createElement('button');
  noneButton.type = 'button';
  noneButton.className = 'asset-gallery__item';
  if (!select.value) {
    noneButton.classList.add('asset-gallery__item--selected');
  }
  noneButton.innerHTML = `
    <div class="asset-gallery__blank" aria-hidden="true"></div>
    <span class="asset-gallery__label">None</span>
  `;
  noneButton.addEventListener('click', () => {
    select.value = '';
    renderAssetGalleries();
    updatePreview();
  });
  gallery.appendChild(noneButton);

  if (items.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'asset-gallery__empty';
    empty.textContent = 'No assets found in folder.';
    gallery.appendChild(empty);
    return;
  }

  items.forEach((path) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'asset-gallery__item';
    if (select.value === path) {
      button.classList.add('asset-gallery__item--selected');
    }

    button.innerHTML = `
      <img src="${API_BASE}${path}" alt="${basename(path)}">
      <span class="asset-gallery__label">${basename(path)}</span>
    `;

    button.addEventListener('click', () => {
      select.value = path;
      renderAssetGalleries();
      updatePreview();
    });

    gallery.appendChild(button);
  });
}

function renderAssetGalleries() {
  renderAssetGallery('avatar');
  renderAssetGallery('background');
}

function updatePreview() {
  const avatarPath = elements.avatarImageUrl.value;
  const backgroundPath = elements.chatBackgroundUrl.value;
  const name = elements.name.value.trim();
  const role = elements.role.value.trim();

  elements.previewAvatar.src = avatarPath ? API_BASE + avatarPath : '';
  elements.previewAvatar.style.display = avatarPath ? 'block' : 'none';

  if (backgroundPath) {
    elements.previewBackground.style.backgroundImage = `url('${API_BASE}${backgroundPath}')`;
  } else {
    elements.previewBackground.style.backgroundImage = 'none';
  }

  elements.previewName.textContent = name || 'Avatar Preview';
  elements.previewRole.textContent = role || 'Role';
}

function clearForm() {
  elements.form.reset();
  setModelValue(getDefaultModel());
  setVoiceValue(getDefaultVoice());
  elements.isPublished.checked = true;
  elements.useAdvancedAi.checked = false;
  elements.knowledgeFiles.value = '';
  elements.knowledgeUploadInput.value = '';
  elements.title.textContent = 'Create Avatar';
  elements.deleteAgent.disabled = true;
  state.currentAgentId = null;
  state.currentKnowledgeStatus = null;
  updateReindexButtonState();
  renderAgentsList();
  renderAssetGalleries();
  updatePreview();
  setKnowledgeSyncStatus('idle', 'Not synced yet', '');
}

function fillForm(agent) {
  elements.slug.value = agent.slug || '';
  elements.name.value = agent.name || '';
  elements.role.value = agent.role || '';
  elements.description.value = agent.description || '';
  elements.isPublished.checked = isPublished(agent);
  elements.useAdvancedAi.checked = Boolean(agent.use_advanced_ai);
  elements.avatarImageUrl.value = agent.avatar_image_url || '';
  elements.chatBackgroundUrl.value = agent.chat_background_url || '';
  elements.systemInstructions.value = agent.system_instructions || '';
  elements.knowledgeText.value = agent.knowledge_text || '';
  setModelValue(agent.openai_model || getDefaultModel());
  setVoiceValue(agent.openai_voice || getDefaultVoice());
  elements.knowledgeFiles.value = Array.isArray(agent.knowledge_files)
    ? agent.knowledge_files.join('\n')
    : '';
  elements.title.textContent = `Edit Avatar #${agent.id}`;
  elements.deleteAgent.disabled = false;
  updateReindexButtonState();
  renderAssetGalleries();
  updatePreview();

  const meta = `${formatSyncDate(agent.knowledge_synced_at)} - files: --`;
  setKnowledgeSyncStatus(agent.knowledge_sync_status || 'idle', meta, agent.knowledge_last_error || '');
}

function renderAgentsList() {
  elements.list.innerHTML = '';

  if (state.agents.length === 0) {
    const empty = document.createElement('p');
    empty.textContent = 'No avatars yet.';
    elements.list.appendChild(empty);
    return;
  }

  state.agents.forEach((agent) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'agents-list__item';
    if (!isPublished(agent)) {
      button.classList.add('agents-list__item--unpublished');
    }

    if (agent.id === state.currentAgentId) {
      button.classList.add('agents-list__item--active');
    }

    button.innerHTML = `
      <span class="agents-list__name">${agent.name}</span>
      <span class="agents-list__meta">${agent.role} - ${agent.slug} - ${!isPublished(agent) ? 'Hidden' : 'Published'}</span>
    `;

    button.addEventListener('click', () => {
      state.currentAgentId = agent.id;
      fillForm(agent);
      renderAgentsList();
      void loadKnowledgeStatus(agent.id);
    });

    elements.list.appendChild(button);
  });
}

function buildPayload() {
  return {
    slug: elements.slug.value.trim(),
    name: elements.name.value.trim(),
    role: elements.role.value.trim(),
    description: elements.description.value.trim(),
    avatar_image_url: elements.avatarImageUrl.value.trim() || null,
    chat_background_url: elements.chatBackgroundUrl.value.trim() || null,
    system_instructions: elements.systemInstructions.value.trim(),
    knowledge_text: elements.knowledgeText.value.trim(),
    knowledge_files: normalizeKnowledgeFiles(elements.knowledgeFiles.value),
    openai_model: elements.openAiModel.value.trim() || null,
    openai_voice: elements.openAiVoice.value.trim() || null,
    use_advanced_ai: elements.useAdvancedAi.checked,
    is_published: elements.isPublished.checked,
  };
}

function mergeKnowledgeFiles(paths) {
  if (!Array.isArray(paths) || paths.length === 0) {
    return;
  }

  const existing = normalizeKnowledgeFiles(elements.knowledgeFiles.value);
  const merged = Array.from(new Set([...existing, ...paths]));
  elements.knowledgeFiles.value = merged.join('\n');
}

async function uploadKnowledgeFiles(fileList) {
  const files = Array.from(fileList || []);
  if (files.length === 0) {
    return;
  }

  const formData = new FormData();
  files.forEach((file) => formData.append('files[]', file));

  setStatus('Uploading knowledge files...');

  try {
    const payload = await api('/api/v1/admin/knowledge-files', {
      method: 'POST',
      body: formData,
    });

    const uploaded = Array.isArray(payload.files) ? payload.files : [];
    mergeKnowledgeFiles(uploaded);
    setStatus(`Uploaded ${uploaded.length} file${uploaded.length === 1 ? '' : 's'}`);
  } catch (error) {
    setStatus(error instanceof Error ? error.message : 'Knowledge upload failed', true);
  } finally {
    elements.knowledgeUploadInput.value = '';
    elements.knowledgeDropzone.classList.remove('knowledge-dropzone--active');
  }
}

async function loadKnowledgeStatus(agentId) {
  if (!agentId) {
    state.currentKnowledgeStatus = null;
    updateReindexButtonState();
    setKnowledgeSyncStatus('idle', 'Not synced yet', '');
    return;
  }

  try {
    const status = await api(`/api/v1/admin/agents/${agentId}/knowledge/status`);
    state.currentKnowledgeStatus = status;

    if (status.advanced_ai === false) {
      elements.useAdvancedAi.checked = false;
    }

    updateReindexButtonState();

    const meta = `${formatSyncDate(status.synced_at)} - files: ${formatNumber(status.file_count)}`;
    setKnowledgeSyncStatus(status.status || 'idle', meta, status.last_error || '');
  } catch (error) {
    state.currentKnowledgeStatus = null;
    updateReindexButtonState();
    setKnowledgeSyncStatus('failed', 'Unable to load sync status', error instanceof Error ? error.message : '');
  }
}

async function reindexKnowledge() {
  const agentId = state.currentAgentId;
  if (!agentId) {
    return;
  }

  elements.reindexKnowledge.disabled = true;
  setKnowledgeSyncStatus('syncing', 'Reindex in progress...', '');

  try {
    const result = await api(`/api/v1/admin/agents/${agentId}/knowledge/reindex`, {
      method: 'POST',
    });

    const meta = `${formatSyncDate(result.synced_at || new Date().toISOString())} - files: ${formatNumber(result.file_count)}`;
    setKnowledgeSyncStatus(result.status || 'synced', meta, result.last_error || '');
    setStatus(result.ok ? 'Knowledge reindexed' : 'Knowledge reindex completed with issues', !result.ok);
    await refreshAgents(agentId);
  } catch (error) {
    setKnowledgeSyncStatus('failed', 'Reindex failed', error instanceof Error ? error.message : '');
    setStatus(error instanceof Error ? error.message : 'Knowledge reindex failed', true);
  } finally {
    updateReindexButtonState();
  }
}

function openUsageModal() {
  elements.usageModal.hidden = false;
}

function closeUsageModal() {
  elements.usageModal.hidden = true;
}

function usageTable(title, headers, rows) {
  if (!Array.isArray(rows) || rows.length === 0) {
    return `
      <section class="usage-table-wrap">
        <h4 class="usage-table-title">${escapeHtml(title)}</h4>
        <p class="usage-empty">No data for this range.</p>
      </section>
    `;
  }

  const head = headers.map((label) => `<th>${escapeHtml(label)}</th>`).join('');
  const body = rows.map((cells) => {
    const values = cells.map((cell) => `<td>${escapeHtml(cell)}</td>`).join('');
    return `<tr>${values}</tr>`;
  }).join('');

  return `
    <section class="usage-table-wrap">
      <h4 class="usage-table-title">${escapeHtml(title)}</h4>
      <table class="usage-table">
        <thead><tr>${head}</tr></thead>
        <tbody>${body}</tbody>
      </table>
    </section>
  `;
}

function renderUsageSummary(payload) {
  const totals = payload?.totals && typeof payload.totals === 'object' ? payload.totals : {};
  const byAgent = Array.isArray(payload?.by_agent) ? payload.by_agent : [];
  const byModel = Array.isArray(payload?.by_model) ? payload.by_model : [];
  const daily = Array.isArray(payload?.daily) ? payload.daily : [];

  const summaryHtml = `
    <section class="usage-summary">
      <article class="usage-card">
        <p class="usage-card__label">Agent replies</p>
        <p class="usage-card__value">${formatNumber(totals.agent_messages)}</p>
      </article>
      <article class="usage-card">
        <p class="usage-card__label">Prompt tokens</p>
        <p class="usage-card__value">${formatNumber(totals.prompt_tokens)}</p>
      </article>
      <article class="usage-card">
        <p class="usage-card__label">Completion tokens</p>
        <p class="usage-card__value">${formatNumber(totals.completion_tokens)}</p>
      </article>
      <article class="usage-card">
        <p class="usage-card__label">Total tokens</p>
        <p class="usage-card__value">${formatNumber(totals.total_tokens)}</p>
      </article>
      <article class="usage-card">
        <p class="usage-card__label">Avg latency (ms)</p>
        <p class="usage-card__value">${formatNumber(totals.avg_latency_ms)}</p>
      </article>
    </section>
  `;

  const byAgentRows = byAgent.map((item) => [
    item.agent_name || `Agent #${item.agent_id}`,
    item.agent_role || '-',
    formatNumber(item.agent_messages),
    formatNumber(item.total_tokens),
    formatNumber(item.avg_latency_ms),
    item.last_message_at || '-',
  ]);

  const byModelRows = byModel.map((item) => [
    item.model || 'unknown',
    formatNumber(item.agent_messages),
    formatNumber(item.prompt_tokens),
    formatNumber(item.completion_tokens),
    formatNumber(item.total_tokens),
  ]);

  const dailyRows = daily.map((item) => [
    item.day || '-',
    formatNumber(item.agent_messages),
    formatNumber(item.total_tokens),
  ]);

  elements.usageContent.innerHTML = `
    ${summaryHtml}
    ${usageTable('By Avatar', ['Avatar', 'Role', 'Replies', 'Total tokens', 'Avg latency', 'Last reply'], byAgentRows)}
    ${usageTable('By Model', ['Model', 'Replies', 'Prompt', 'Completion', 'Total'], byModelRows)}
    ${usageTable('Daily Trend', ['Day', 'Replies', 'Total tokens'], dailyRows)}
  `;
}

async function loadUsageSummary() {
  const rangeDays = Number.parseInt(elements.usageRangeDays.value, 10) || 30;
  elements.usageContent.innerHTML = '<p class="usage-empty">Loading usage...</p>';

  try {
    const payload = await api(`/api/v1/admin/usage?days=${rangeDays}`);
    renderUsageSummary(payload);
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Failed to load usage';
    elements.usageContent.innerHTML = `<p class="usage-error">${escapeHtml(message)}</p>`;
  }
}

async function refreshAgents(selectId = null) {
  state.agents = await api('/api/v1/admin/agents');

  if (selectId !== null) {
    state.currentAgentId = selectId;
  } else if (state.currentAgentId !== null) {
    const stillExists = state.agents.some((agent) => agent.id === state.currentAgentId);
    if (!stillExists) {
      state.currentAgentId = null;
    }
  }

  renderAgentsList();

  if (state.currentAgentId !== null) {
    const current = state.agents.find((agent) => agent.id === state.currentAgentId);
    if (current) {
      fillForm(current);
      await loadKnowledgeStatus(current.id);
      return;
    }
  }

  clearForm();
}

async function loadAdmin() {
  setStatus('Loading assets and avatars...');

  const [assets] = await Promise.all([
    api('/api/v1/admin/assets'),
    refreshAgents(),
  ]);

  state.assets = assets;
  fillModelSelect(elements.openAiModel, assets.openai_models || []);
  setModelValue(getDefaultModel());
  fillVoiceSelect(elements.openAiVoice, assets.openai_voices || []);
  setVoiceValue(getDefaultVoice());
  renderAssetGalleries();

  const advancedSupported = assets.advanced_ai_supported !== false;
  const fileSearchSupported = assets.file_search_supported !== false;
  elements.useAdvancedAi.disabled = !advancedSupported || !fileSearchSupported;
  updateReindexButtonState();

  if (state.agents.length > 0) {
    state.currentAgentId = state.agents[0].id;
    fillForm(state.agents[0]);
    renderAgentsList();
    await loadKnowledgeStatus(state.agents[0].id);
  } else {
    clearForm();
  }

  if (assets.publishing_supported === false) {
    setStatus('Publishing requires SQL migration: run backend/sql/006_add_agents_publish.sql', true);
  } else if (!advancedSupported) {
    setStatus('Advanced AI requires SQL migration: run backend/sql/008_add_agent_retrieval_config.sql and sql/009_create_agent_knowledge_files.sql', true);
  } else if (!fileSearchSupported) {
    setStatus('OpenAI File Search disabled. Set OPENAI_ENABLE_FILE_SEARCH=true and ensure OPENAI_API_KEY is set.', true);
  } else {
    setStatus('Ready');
  }
}

elements.newAgent.addEventListener('click', () => {
  clearForm();
  setStatus('Creating new avatar');
});

elements.form.addEventListener('submit', async (event) => {
  event.preventDefault();

  try {
    const payload = buildPayload();

    setStatus('Saving...');

    if (state.currentAgentId === null) {
      const created = await api('/api/v1/admin/agents', {
        method: 'POST',
        body: JSON.stringify(payload),
      });
      await refreshAgents(created.id);
    } else {
      const updated = await api(`/api/v1/admin/agents/${state.currentAgentId}`, {
        method: 'PUT',
        body: JSON.stringify(payload),
      });
      await refreshAgents(updated.id);
    }

    setStatus('Saved');
  } catch (error) {
    setStatus(error instanceof Error ? error.message : 'Save failed', true);
  }
});

elements.deleteAgent.addEventListener('click', async () => {
  if (state.currentAgentId === null) {
    return;
  }

  if (!window.confirm('Delete this avatar? This cannot be undone.')) {
    return;
  }

  try {
    setStatus('Deleting...');
    await api(`/api/v1/admin/agents/${state.currentAgentId}`, {
      method: 'DELETE',
    });
    await refreshAgents(null);
    setStatus('Deleted');
  } catch (error) {
    setStatus(error instanceof Error ? error.message : 'Delete failed', true);
  }
});

[
  elements.avatarImageUrl,
  elements.chatBackgroundUrl,
  elements.name,
  elements.role,
].forEach((element) => {
  element.addEventListener('change', updatePreview);
  element.addEventListener('input', updatePreview);
});

elements.avatarImageUrl.addEventListener('change', renderAssetGalleries);
elements.chatBackgroundUrl.addEventListener('change', renderAssetGalleries);
elements.knowledgeUploadButton.addEventListener('click', (event) => {
  event.stopPropagation();
  elements.knowledgeUploadInput.click();
});

elements.knowledgeUploadInput.addEventListener('change', () => {
  uploadKnowledgeFiles(elements.knowledgeUploadInput.files);
});

elements.knowledgeDropzone.addEventListener('click', () => {
  elements.knowledgeUploadInput.click();
});

elements.knowledgeDropzone.addEventListener('keydown', (event) => {
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault();
    elements.knowledgeUploadInput.click();
  }
});

['dragenter', 'dragover'].forEach((eventName) => {
  elements.knowledgeDropzone.addEventListener(eventName, (event) => {
    event.preventDefault();
    event.stopPropagation();
    elements.knowledgeDropzone.classList.add('knowledge-dropzone--active');
  });
});

['dragleave', 'dragend'].forEach((eventName) => {
  elements.knowledgeDropzone.addEventListener(eventName, (event) => {
    event.preventDefault();
    event.stopPropagation();
    elements.knowledgeDropzone.classList.remove('knowledge-dropzone--active');
  });
});

elements.knowledgeDropzone.addEventListener('drop', (event) => {
  event.preventDefault();
  event.stopPropagation();
  elements.knowledgeDropzone.classList.remove('knowledge-dropzone--active');
  uploadKnowledgeFiles(event.dataTransfer ? event.dataTransfer.files : []);
});

elements.useAdvancedAi.addEventListener('change', () => {
  updateReindexButtonState();

  if (!elements.useAdvancedAi.checked) {
    setKnowledgeSyncStatus('idle', 'Advanced AI disabled', '');
  }
});

elements.reindexKnowledge.addEventListener('click', () => {
  void reindexKnowledge();
});

elements.usageInfoButton.addEventListener('click', () => {
  openUsageModal();
  void loadUsageSummary();
});

elements.usageRefreshButton.addEventListener('click', () => {
  void loadUsageSummary();
});

elements.usageRangeDays.addEventListener('change', () => {
  void loadUsageSummary();
});

elements.usageCloseButton.addEventListener('click', closeUsageModal);

elements.usageCloseTargets.forEach((element) => {
  element.addEventListener('click', closeUsageModal);
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && !elements.usageModal.hidden) {
    closeUsageModal();
  }
});

// --- Login screen ---
function showLoginScreen() {
  const shell = document.querySelector('.admin-shell');
  if (shell) shell.style.display = 'none';
  let loginEl = document.getElementById('saas-login-screen');
  if (loginEl) { loginEl.style.display = ''; return; }

  loginEl = document.createElement('div');
  loginEl.id = 'saas-login-screen';
  loginEl.innerHTML = `
    <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0a0a0b;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif">
      <div style="width:100%;max-width:400px;padding:40px;background:#18181b;border:1px solid #27272a;border-radius:16px;color:#e4e4e7">
        <h1 style="font-size:24px;font-weight:700;margin:0 0 6px;color:#fafafa">AvatarHub Admin</h1>
        <p style="font-size:14px;color:#71717a;margin:0 0 32px">Sign in with your SaaS platform account</p>
        <div id="saas-login-error" style="display:none;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5;padding:10px 14px;border-radius:8px;font-size:14px;margin-bottom:20px"></div>
        <form id="saas-login-form">
          <div style="margin-bottom:20px">
            <label style="display:block;font-size:13px;color:#a1a1aa;margin-bottom:6px">Email</label>
            <input type="email" name="email" required autocomplete="email" placeholder="you@hotel.com" style="width:100%;padding:10px 14px;background:#09090b;border:1px solid #27272a;border-radius:8px;color:#fafafa;font-size:15px;outline:none">
          </div>
          <div style="margin-bottom:20px">
            <label style="display:block;font-size:13px;color:#a1a1aa;margin-bottom:6px">Password</label>
            <input type="password" name="password" required autocomplete="current-password" placeholder="Enter your password" style="width:100%;padding:10px 14px;background:#09090b;border:1px solid #27272a;border-radius:8px;color:#fafafa;font-size:15px;outline:none">
          </div>
          <button type="submit" style="width:100%;padding:12px;background:#6366f1;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer">Sign In</button>
        </form>
      </div>
    </div>`;
  document.body.appendChild(loginEl);

  document.getElementById('saas-login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errEl = document.getElementById('saas-login-error');
    errEl.style.display = 'none';
    const fd = new FormData(e.target);
    const email = fd.get('email'), password = fd.get('password');
    try {
      const platformUrl = window.SAAS_PLATFORM_URL || 'http://localhost:3000';
      const res = await fetch(`${platformUrl}/api/auth/token`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Authentication failed');
      setAuthToken(data.token, data.user);
      loginEl.style.display = 'none';
      const shell = document.querySelector('.admin-shell');
      if (shell) shell.style.display = '';
      loadAdmin().catch((err) => setStatus(err.message || 'Failed to load', true));
    } catch (err) {
      errEl.textContent = err.message || 'Sign in failed';
      errEl.style.display = '';
    }
  });
}

// --- App initialization ---
if (getAuthToken()) {
  loadAdmin().catch((error) => {
    if (error.message?.includes('401') || error.message?.includes('unauthorized') || error.message?.includes('Session expired')) {
      clearAuth();
      showLoginScreen();
    } else {
      setStatus(error instanceof Error ? error.message : 'Failed to load admin', true);
    }
  });
} else {
  showLoginScreen();
}
