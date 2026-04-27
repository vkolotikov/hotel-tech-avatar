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
  videoGallery: document.getElementById('video-gallery'),
  introVideoUrl: document.getElementById('intro_video_url'),
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
  verticalId: document.getElementById('vertical_id'),
  verticalFilter: document.getElementById('vertical-filter'),
  knowledgeSources: document.getElementById('knowledge_sources'),
  promptSuggestions: document.getElementById('prompt_suggestions'),
  redFlagList: document.getElementById('red_flag_rules'),
  scopeList: document.getElementById('scope_rules'),
  handoffList: document.getElementById('handoff_rules'),
  safetyPreviewMessage: document.getElementById('safety_preview_message'),
  safetyPreviewRun: document.getElementById('safety_preview_run'),
  safetyPreviewResult: document.getElementById('safety_preview_result'),
  snapshotVersion: document.getElementById('snapshot_version'),
  promptVersionsList: document.getElementById('prompt_versions_list'),
  bulkExport: document.getElementById('bulk-export'),
  bulkImport: document.getElementById('bulk-import'),
  bulkImportInput: document.getElementById('bulk-import-input'),
  voicePreview: document.getElementById('voice-preview'),
};

// --- Rule editor schema ---
// Each rules container is a list of simple { a, b } objects. The
// schema map below drives labels/placeholders per field type.
const RULE_SCHEMAS = {
  red_flag_rules: {
    element: 'redFlagList',
    left: {
      key: 'keywords',
      label: 'Keywords',
      placeholder: 'suicide, kill myself, end it all',
      multiline: false,
      splitList: true,
    },
    right: {
      key: 'response',
      label: 'Canned response',
      placeholder: 'If you are in crisis, please call ...',
      multiline: true,
      splitList: false,
    },
  },
  scope_rules: {
    element: 'scopeList',
    left: {
      key: 'topic',
      label: 'Refused topic',
      placeholder: 'prescription dosing',
      multiline: false,
      splitList: false,
    },
    right: {
      key: 'response',
      label: 'Redirect copy',
      placeholder: 'I can offer general info but dosing needs a clinician ...',
      multiline: true,
      splitList: false,
    },
  },
  handoff_rules: {
    element: 'handoffList',
    left: {
      key: 'trigger',
      label: 'Trigger',
      placeholder: 'user describes melanoma criteria',
      multiline: false,
      splitList: false,
    },
    right: {
      key: 'referral',
      label: 'Referral copy',
      placeholder: 'Please see a dermatologist — here is what to ask ...',
      multiline: true,
      splitList: false,
    },
  },
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

function renderVideoGallery() {
  const gallery = elements.videoGallery;
  const select = elements.introVideoUrl;
  if (!gallery || !select) return;
  const items = state.assets.videos || [];

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
    renderVideoGallery();
  });
  gallery.appendChild(noneButton);

  if (items.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'asset-gallery__empty';
    empty.textContent = 'No videos in public/assets/avatars/videos.';
    gallery.appendChild(empty);
    return;
  }

  items.forEach((path) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'asset-gallery__item asset-gallery__item--video';
    if (select.value === path) {
      button.classList.add('asset-gallery__item--selected');
    }

    button.innerHTML = `
      <div class="video-tile" aria-hidden="true">
        <span class="video-tile__play">▶</span>
      </div>
      <span class="asset-gallery__label">${basename(path)}</span>
    `;

    button.addEventListener('click', () => {
      select.value = path;
      renderVideoGallery();
    });

    gallery.appendChild(button);
  });
}

function renderAssetGalleries() {
  renderAssetGallery('avatar');
  renderAssetGallery('background');
  renderVideoGallery();
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

// --- Rule editor helpers ---

function escapeAttr(value) {
  return escapeHtml(value);
}

function renderRuleRow(schema, rule) {
  const row = document.createElement('div');
  row.className = 'rules-row';
  // Keep the original rule on the DOM node so unknown fields
  // (pattern_regex, category, handoff_target, severity, …) survive a save.
  row.__originalRule = rule && typeof rule === 'object' ? { ...rule } : {};

  const makeField = (side, value) => {
    const wrap = document.createElement('label');
    wrap.className = 'rules-row__field';
    const label = document.createElement('span');
    label.textContent = side.label;
    wrap.appendChild(label);

    const el = side.multiline
      ? document.createElement('textarea')
      : document.createElement('input');
    el.className = 'rules-row__input';
    if (!side.multiline) el.type = 'text';
    el.placeholder = side.placeholder || '';
    el.value = Array.isArray(value) ? value.join(', ') : (value ?? '');
    el.dataset.ruleField = side.key;
    if (side.splitList) el.dataset.splitList = '1';
    wrap.appendChild(el);
    return wrap;
  };

  row.appendChild(makeField(schema.left, rule[schema.left.key]));
  row.appendChild(makeField(schema.right, rule[schema.right.key]));

  const remove = document.createElement('button');
  remove.type = 'button';
  remove.className = 'rules-row__remove';
  remove.setAttribute('aria-label', 'Remove rule');
  remove.textContent = '×';
  remove.addEventListener('click', () => row.remove());
  row.appendChild(remove);

  return row;
}

function renderRulesInto(listEl, schemaKey, rules) {
  listEl.innerHTML = '';
  const schema = RULE_SCHEMAS[schemaKey];
  if (!rules || rules.length === 0) {
    const empty = document.createElement('div');
    empty.className = 'rules-empty';
    empty.textContent = 'No rules yet — tap "+ Add rule" to add one.';
    listEl.appendChild(empty);
    return;
  }
  rules.forEach((rule) => listEl.appendChild(renderRuleRow(schema, rule)));
}

function collectRulesFrom(listEl, schemaKey) {
  const schema = RULE_SCHEMAS[schemaKey];
  const rows = listEl.querySelectorAll('.rules-row');
  const result = [];
  rows.forEach((row) => {
    const inputs = row.querySelectorAll('[data-rule-field]');
    const edited = {};
    inputs.forEach((input) => {
      const key = input.dataset.ruleField;
      const raw = (input.value || '').trim();
      if (input.dataset.splitList === '1') {
        edited[key] = raw
          ? raw.split(',').map((s) => s.trim()).filter(Boolean)
          : [];
      } else {
        edited[key] = raw;
      }
    });
    const leftVal = edited[schema.left.key];
    const rightVal = edited[schema.right.key];
    const leftEmpty = Array.isArray(leftVal) ? leftVal.length === 0 : !leftVal;
    const rightEmpty = !rightVal;
    if (leftEmpty && rightEmpty) return; // skip fully-empty rows

    // Merge: start from the original (keeps pattern_regex, category, etc.)
    // and overwrite only the fields the form controls.
    const original = row.__originalRule || {};
    result.push({ ...original, ...edited });
  });
  return result;
}

function bindRulesAddButtons() {
  document.querySelectorAll('[data-rules-add]').forEach((btn) => {
    if (btn.dataset.bound === '1') return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', () => {
      const key = btn.dataset.rulesAdd;
      const schema = RULE_SCHEMAS[key];
      if (!schema) return;
      const listEl = elements[schema.element];
      // Remove empty-state if present
      const emptyNote = listEl.querySelector('.rules-empty');
      if (emptyNote) emptyNote.remove();
      listEl.appendChild(renderRuleRow(schema, {}));
    });
  });
}

function clearForm() {
  elements.form.reset();
  setModelValue(getDefaultModel());
  setVoiceValue(getDefaultVoice());
  elements.isPublished.checked = true;
  elements.useAdvancedAi.checked = false;
  elements.knowledgeFiles.value = '';
  elements.knowledgeUploadInput.value = '';
  if (elements.knowledgeSources) elements.knowledgeSources.value = '';
  if (elements.promptSuggestions) elements.promptSuggestions.value = '';
  if (elements.verticalId) elements.verticalId.value = '';
  if (elements.introVideoUrl) elements.introVideoUrl.value = '';
  renderRulesInto(elements.redFlagList, 'red_flag_rules', []);
  renderRulesInto(elements.scopeList, 'scope_rules', []);
  renderRulesInto(elements.handoffList, 'handoff_rules', []);
  if (elements.safetyPreviewResult) elements.safetyPreviewResult.hidden = true;
  if (elements.safetyPreviewMessage) elements.safetyPreviewMessage.value = '';
  renderPromptVersions({ versions: [], active_id: null, hint: 'Save the avatar first to enable versioning.' });
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
  if (elements.introVideoUrl) elements.introVideoUrl.value = agent.intro_video_url || '';
  elements.systemInstructions.value = agent.system_instructions || '';
  elements.knowledgeText.value = agent.knowledge_text || '';
  setModelValue(agent.openai_model || getDefaultModel());
  setVoiceValue(agent.openai_voice || getDefaultVoice());
  elements.knowledgeFiles.value = Array.isArray(agent.knowledge_files)
    ? agent.knowledge_files.join('\n')
    : '';
  if (elements.verticalId) {
    elements.verticalId.value = agent.vertical_id ? String(agent.vertical_id) : '';
  }
  if (elements.knowledgeSources) {
    elements.knowledgeSources.value = Array.isArray(agent.knowledge_sources_json)
      ? agent.knowledge_sources_json.join('\n')
      : (agent.knowledge_sources_json || '');
  }
  if (elements.promptSuggestions) {
    elements.promptSuggestions.value = Array.isArray(agent.prompt_suggestions_json)
      ? agent.prompt_suggestions_json.join('\n')
      : '';
  }
  renderRulesInto(elements.redFlagList, 'red_flag_rules', Array.isArray(agent.red_flag_rules_json) ? agent.red_flag_rules_json : []);
  renderRulesInto(elements.scopeList, 'scope_rules', Array.isArray(agent.scope_json) ? agent.scope_json : []);
  renderRulesInto(elements.handoffList, 'handoff_rules', Array.isArray(agent.handoff_rules_json) ? agent.handoff_rules_json : []);
  elements.title.textContent = `Edit Avatar #${agent.id}`;
  elements.deleteAgent.disabled = false;
  updateReindexButtonState();
  renderAssetGalleries();
  updatePreview();

  const meta = `${formatSyncDate(agent.knowledge_synced_at)} - files: --`;
  setKnowledgeSyncStatus(agent.knowledge_sync_status || 'idle', meta, agent.knowledge_last_error || '');

  void loadPromptVersions();
}

function renderAgentsList() {
  elements.list.innerHTML = '';

  const filterVal = elements.verticalFilter?.value || '';
  const agents = filterVal
    ? state.agents.filter((a) => String(a.vertical_id ?? '') === filterVal)
    : state.agents;

  if (agents.length === 0) {
    const empty = document.createElement('p');
    empty.textContent = state.agents.length === 0 ? 'No avatars yet.' : 'No avatars in this vertical.';
    elements.list.appendChild(empty);
    return;
  }

  agents.forEach((agent) => {
    const item = document.createElement('div');
    item.className = 'agents-list__item';
    item.dataset.agentId = String(agent.id);
    item.draggable = true;

    if (!isPublished(agent)) {
      item.classList.add('agents-list__item--unpublished');
    }

    if (agent.id === state.currentAgentId) {
      item.classList.add('agents-list__item--active');
    }

    const escName = escapeHtml(agent.name || '');
    const escRole = escapeHtml(agent.role || '');
    const escSlug = escapeHtml(agent.slug || '');

    item.innerHTML = `
      <span class="agents-list__handle" title="Drag to reorder" aria-hidden="true">&#x22EE;&#x22EE;</span>
      <span class="agents-list__body">
        <span class="agents-list__name">${escName}</span>
        <span class="agents-list__meta">${escRole} - ${escSlug} - ${!isPublished(agent) ? 'Hidden' : 'Published'}</span>
      </span>
    `;

    // Selecting an avatar: clicking anywhere on the row except the handle
    // opens the edit form for that agent. The handle is reserved for drag.
    item.addEventListener('click', (e) => {
      if (e.target instanceof HTMLElement && e.target.classList.contains('agents-list__handle')) {
        return;
      }
      state.currentAgentId = agent.id;
      fillForm(agent);
      renderAgentsList();
      void loadKnowledgeStatus(agent.id);
    });

    bindDragHandlers(item, agent.id);

    elements.list.appendChild(item);
  });
}

// --- Drag-and-drop reorder ---
// Tracks the agent ID currently being dragged. Module-scoped because the
// drop target needs to know what's coming in via dataTransfer (which is
// blocked from being read on `dragover` for security reasons).
let dragSourceAgentId = null;

function bindDragHandlers(item, agentId) {
  item.addEventListener('dragstart', (e) => {
    dragSourceAgentId = agentId;
    item.classList.add('agents-list__item--dragging');
    if (e.dataTransfer) {
      e.dataTransfer.effectAllowed = 'move';
      // Some browsers refuse to start a drag without dataTransfer.setData.
      e.dataTransfer.setData('text/plain', String(agentId));
    }
  });

  item.addEventListener('dragend', () => {
    dragSourceAgentId = null;
    item.classList.remove('agents-list__item--dragging');
    document.querySelectorAll('.agents-list__item--drop-before, .agents-list__item--drop-after')
      .forEach((el) => {
        el.classList.remove('agents-list__item--drop-before');
        el.classList.remove('agents-list__item--drop-after');
      });
  });

  item.addEventListener('dragover', (e) => {
    if (dragSourceAgentId === null || dragSourceAgentId === agentId) {
      return;
    }
    e.preventDefault();
    if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';

    const rect = item.getBoundingClientRect();
    const isAbove = (e.clientY - rect.top) < rect.height / 2;
    item.classList.toggle('agents-list__item--drop-before', isAbove);
    item.classList.toggle('agents-list__item--drop-after', !isAbove);
  });

  item.addEventListener('dragleave', () => {
    item.classList.remove('agents-list__item--drop-before');
    item.classList.remove('agents-list__item--drop-after');
  });

  item.addEventListener('drop', (e) => {
    e.preventDefault();
    if (dragSourceAgentId === null || dragSourceAgentId === agentId) {
      return;
    }
    const sourceId = dragSourceAgentId;
    const rect = item.getBoundingClientRect();
    const dropAbove = (e.clientY - rect.top) < rect.height / 2;
    item.classList.remove('agents-list__item--drop-before');
    item.classList.remove('agents-list__item--drop-after');
    void applyReorder(sourceId, agentId, dropAbove);
  });
}

/**
 * Reorder state.agents so that `sourceId` lands directly before/after
 * `targetId`, then persist the new ordering. We only move within the
 * currently-visible (filtered) subset — non-visible rows keep their
 * existing absolute order on the server. Optimistic: the UI re-renders
 * from local state immediately, server save happens in the background.
 */
async function applyReorder(sourceId, targetId, dropAbove) {
  const filterVal = elements.verticalFilter?.value || '';
  const isVisible = (a) => !filterVal || String(a.vertical_id ?? '') === filterVal;

  const visible = state.agents.filter(isVisible);
  const sourceIndex = visible.findIndex((a) => a.id === sourceId);
  let targetIndex = visible.findIndex((a) => a.id === targetId);
  if (sourceIndex === -1 || targetIndex === -1) return;

  const [moved] = visible.splice(sourceIndex, 1);
  if (sourceIndex < targetIndex) targetIndex -= 1;
  visible.splice(targetIndex + (dropAbove ? 0 : 1), 0, moved);

  // Splice the reordered visible items back into state.agents in the
  // visible slots, preserving non-visible rows' existing positions.
  const newVisibleQueue = [...visible];
  state.agents = state.agents.map((a) => (isVisible(a) ? newVisibleQueue.shift() : a));

  renderAgentsList();
  await persistAgentOrder();
}

async function persistAgentOrder() {
  const order = state.agents.map((a) => a.id);
  setStatus('Saving order...');
  try {
    await api('/api/v1/admin/agents-order', {
      method: 'PUT',
      body: JSON.stringify({ order }),
    });
    setStatus('Order saved');
  } catch (err) {
    setStatus(`Failed to save order: ${err.message || err}`, true);
    // Refresh from server to undo the optimistic local move.
    await refreshAgents();
    renderAgentsList();
  }
}


function buildPayload() {
  const sources = (elements.knowledgeSources?.value || '')
    .split('\n')
    .map((s) => s.trim())
    .filter(Boolean);

  const starterPrompts = (elements.promptSuggestions?.value || '')
    .split('\n')
    .map((s) => s.trim())
    .filter(Boolean);

  return {
    slug: elements.slug.value.trim(),
    name: elements.name.value.trim(),
    role: elements.role.value.trim(),
    description: elements.description.value.trim(),
    vertical_id: elements.verticalId?.value ? Number(elements.verticalId.value) : null,
    avatar_image_url: elements.avatarImageUrl.value.trim() || null,
    chat_background_url: elements.chatBackgroundUrl.value.trim() || null,
    intro_video_url: elements.introVideoUrl?.value?.trim() || null,
    system_instructions: elements.systemInstructions.value.trim(),
    knowledge_text: elements.knowledgeText.value.trim(),
    knowledge_files: normalizeKnowledgeFiles(elements.knowledgeFiles.value),
    knowledge_sources_json: sources,
    prompt_suggestions_json: starterPrompts,
    red_flag_rules_json: collectRulesFrom(elements.redFlagList, 'red_flag_rules'),
    scope_json: collectRulesFrom(elements.scopeList, 'scope_rules'),
    handoff_rules_json: collectRulesFrom(elements.handoffList, 'handoff_rules'),
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

async function loadVerticals() {
  try {
    const verticals = await api('/api/v1/admin/verticals');
    state.verticals = Array.isArray(verticals) ? verticals : [];
  } catch (error) {
    // Non-fatal; form still works, just without the vertical picker options
    state.verticals = [];
  }

  const populate = (selectEl, includeAll) => {
    if (!selectEl) return;
    const prev = selectEl.value;
    selectEl.innerHTML = '';
    if (includeAll) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'All verticals';
      selectEl.appendChild(opt);
    } else {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = '-- None --';
      selectEl.appendChild(opt);
    }
    state.verticals.forEach((v) => {
      const opt = document.createElement('option');
      opt.value = String(v.id);
      opt.textContent = v.name + (v.is_active === false ? ' (inactive)' : '');
      selectEl.appendChild(opt);
    });
    selectEl.value = prev;
  };

  populate(elements.verticalFilter, true);
  populate(elements.verticalId, false);
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

  bindRulesAddButtons();

  const [assets] = await Promise.all([
    api('/api/v1/admin/assets'),
    refreshAgents(),
    loadVerticals(),
  ]);

  state.assets = assets;
  fillModelSelect(elements.openAiModel, assets.models ?? assets.openai_models ?? []);
  setModelValue(getDefaultModel());
  fillVoiceSelect(elements.openAiVoice, assets.voices ?? assets.openai_voices ?? []);
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

if (elements.verticalFilter) {
  elements.verticalFilter.addEventListener('change', () => {
    renderAgentsList();
  });
}

if (elements.safetyPreviewRun) {
  elements.safetyPreviewRun.addEventListener('click', runSafetyPreview);
}

if (elements.snapshotVersion) {
  elements.snapshotVersion.addEventListener('click', snapshotCurrentVersion);
}

if (elements.bulkExport) {
  elements.bulkExport.addEventListener('click', async () => {
    try {
      setStatus('Exporting...');
      const vertical = elements.verticalFilter?.value || '';
      const path = vertical ? `/api/v1/admin/agents-bundle?vertical=${encodeURIComponent(vertical)}` : '/api/v1/admin/agents-bundle';
      const bundle = await api(path);
      const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
      const tag = vertical ? `${vertical}-` : '';
      const blob = new Blob([JSON.stringify(bundle, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `avatars-${tag}${stamp}.json`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      setStatus(`Exported ${bundle.count ?? 0} avatars`);
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Export failed', true);
    }
  });
}

if (elements.bulkImport && elements.bulkImportInput) {
  elements.bulkImport.addEventListener('click', () => elements.bulkImportInput.click());
  elements.bulkImportInput.addEventListener('change', async (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    if (!window.confirm(`Import "${file.name}"? Agents are matched on slug — existing ones are UPDATED in place.`)) {
      return;
    }
    try {
      setStatus('Importing...');
      const text = await file.text();
      const parsed = JSON.parse(text);
      const result = await api('/api/v1/admin/agents-bundle', {
        method: 'POST',
        body: JSON.stringify({ agents: parsed.agents ?? parsed }),
      });
      await refreshAgents();
      setStatus(`Import: ${result.created} created, ${result.updated} updated, ${result.skipped} skipped`);
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Import failed', true);
    }
  });
}

// --- Prompt versioning ---

async function loadPromptVersions() {
  if (!elements.promptVersionsList) return;
  if (state.currentAgentId === null) {
    renderPromptVersions({ versions: [], active_id: null, hint: 'Save the avatar first to enable versioning.' });
    return;
  }
  try {
    const payload = await api(`/api/v1/admin/agents/${state.currentAgentId}/prompt-versions`);
    renderPromptVersions(payload);
  } catch (error) {
    const msg = error instanceof Error ? error.message : 'Failed to load versions';
    renderPromptVersions({ versions: [], active_id: null, hint: msg });
  }
}

function renderPromptVersions(payload) {
  const el = elements.promptVersionsList;
  if (!el) return;
  el.innerHTML = '';

  const versions = Array.isArray(payload.versions) ? payload.versions : [];
  if (versions.length === 0) {
    const empty = document.createElement('div');
    empty.className = 'versions-empty';
    empty.textContent = payload.hint || 'No versions yet — "Save current as new version" to make one.';
    el.appendChild(empty);
    return;
  }

  versions.forEach((version) => {
    const row = document.createElement('div');
    row.className = 'version-row' + (version.is_active ? ' version-row--active' : '');

    const number = document.createElement('div');
    number.className = 'version-row__number';
    number.textContent = '#' + version.version_number;
    row.appendChild(number);

    const body = document.createElement('div');
    body.className = 'version-row__body';
    const note = document.createElement('div');
    note.className = 'version-row__note';
    note.textContent = version.note || '(no note)';
    body.appendChild(note);
    const meta = document.createElement('div');
    meta.className = 'version-row__meta';
    const when = version.created_at ? new Date(version.created_at).toLocaleString() : '';
    const author = version.created_by_user_id ? ' • by ' + version.created_by_user_id : '';
    meta.textContent = when + author;
    body.appendChild(meta);
    row.appendChild(body);

    if (version.is_active) {
      const badge = document.createElement('span');
      badge.className = 'version-row__badge';
      badge.textContent = 'Active';
      row.appendChild(badge);
    } else {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'version-row__action';
      btn.textContent = 'Activate';
      btn.addEventListener('click', () => activateVersion(version.id, version.version_number));
      row.appendChild(btn);
    }

    el.appendChild(row);
  });
}

async function snapshotCurrentVersion() {
  if (state.currentAgentId === null) {
    window.alert('Save the avatar before creating a version.');
    return;
  }
  const note = window.prompt('Optional note for this version (what changed?):', '');
  if (note === null) return;

  elements.snapshotVersion.disabled = true;
  try {
    setStatus('Snapshotting version...');
    await api(`/api/v1/admin/agents/${state.currentAgentId}/prompt-versions`, {
      method: 'POST',
      body: JSON.stringify({ note: note.trim() || null }),
    });
    await loadPromptVersions();
    setStatus('Version saved');
  } catch (error) {
    setStatus(error instanceof Error ? error.message : 'Snapshot failed', true);
  } finally {
    elements.snapshotVersion.disabled = false;
  }
}

async function activateVersion(versionId, number) {
  if (!window.confirm(`Activate version #${number}? This overwrites the current system instructions, persona, scope, red-flag, and handoff rules on this avatar with the snapshot's values.`)) {
    return;
  }
  try {
    setStatus('Activating version...');
    const payload = await api(
      `/api/v1/admin/agents/${state.currentAgentId}/prompt-versions/${versionId}/activate`,
      { method: 'POST' },
    );
    if (payload?.agent) {
      fillForm(payload.agent);
    }
    await loadPromptVersions();
    setStatus(`Version #${number} activated`);
  } catch (error) {
    setStatus(error instanceof Error ? error.message : 'Activation failed', true);
  }
}

async function runSafetyPreview() {
  if (state.currentAgentId === null) {
    renderSafetyPreview({ error: 'Save the avatar before running a preview.' });
    return;
  }
  const message = (elements.safetyPreviewMessage?.value || '').trim();
  if (!message) {
    renderSafetyPreview({ error: 'Type a test message first.' });
    return;
  }
  elements.safetyPreviewRun.disabled = true;
  try {
    const payload = await api(
      `/api/v1/admin/agents/${state.currentAgentId}/safety-preview`,
      { method: 'POST', body: JSON.stringify({ message }) },
    );
    renderSafetyPreview(payload);
  } catch (error) {
    renderSafetyPreview({ error: error instanceof Error ? error.message : 'Preview failed' });
  } finally {
    elements.safetyPreviewRun.disabled = false;
  }
}

function renderSafetyPreview(payload) {
  const el = elements.safetyPreviewResult;
  if (!el) return;

  if (payload.error) {
    el.hidden = false;
    el.className = 'safety-preview__result';
    el.innerHTML = `<span class="safety-preview__hit">Error: ${escapeHtml(payload.error)}</span>`;
    return;
  }

  el.hidden = false;
  if (payload.matched) {
    el.className = 'safety-preview__result safety-preview__result--matched';
    const category = payload.category || 'matched';
    const badge = `<span class="safety-preview__badge safety-preview__badge--${escapeAttr(category)}">${escapeHtml(category.replace('_', ' '))}</span>`;
    const response = payload.response
      ? `<div class="safety-preview__response">${escapeHtml(payload.response)}</div>`
      : '<span class="safety-preview__hit">No canned response is defined for this rule.</span>';
    const note = payload.note
      ? `<span class="safety-preview__hit">${escapeHtml(payload.note)}</span>`
      : '';
    el.innerHTML = badge + response + note;
  } else {
    el.className = 'safety-preview__result safety-preview__result--clean';
    el.innerHTML = `
      <span class="safety-preview__badge safety-preview__badge--clean">clean</span>
      <span class="safety-preview__hit">${escapeHtml(payload.note || 'No rule matched.')}</span>
    `;
  }
}

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

if (elements.voicePreview) {
  elements.voicePreview.addEventListener('click', () => {
    void playVoicePreview();
  });
}

// --- Voice preview ---
// Single shared <audio> element. Re-using it lets a second click stop a
// playback in progress (toggle-style) and avoids overlapping streams.
let voicePreviewAudio = null;

async function playVoicePreview() {
  const voice = (elements.openAiVoice?.value || '').trim();
  if (!voice) {
    setStatus('Pick a voice first', true);
    return;
  }

  // Toggle: clicking again while playing stops playback.
  if (voicePreviewAudio && !voicePreviewAudio.paused) {
    voicePreviewAudio.pause();
    voicePreviewAudio.currentTime = 0;
    elements.voicePreview.classList.remove('voice-preview-btn--playing');
    return;
  }

  elements.voicePreview.disabled = true;
  setStatus(`Generating preview for "${voice}"...`);
  try {
    const sample = sampleSentenceForAgent();
    const payload = await api('/api/v1/admin/voices/preview', {
      method: 'POST',
      body: JSON.stringify({ voice, text: sample }),
    });

    if (!payload?.data_url) {
      throw new Error('No audio returned');
    }

    if (!voicePreviewAudio) {
      voicePreviewAudio = new Audio();
      voicePreviewAudio.addEventListener('ended', () => {
        elements.voicePreview.classList.remove('voice-preview-btn--playing');
      });
      voicePreviewAudio.addEventListener('pause', () => {
        elements.voicePreview.classList.remove('voice-preview-btn--playing');
      });
    }
    voicePreviewAudio.src = payload.data_url;
    elements.voicePreview.classList.add('voice-preview-btn--playing');
    await voicePreviewAudio.play();
    setStatus(payload.cached ? `Playing (cached) - ${voice}` : `Playing - ${voice}`);
  } catch (err) {
    setStatus(`Voice preview failed: ${err.message || err}`, true);
    elements.voicePreview.classList.remove('voice-preview-btn--playing');
  } finally {
    elements.voicePreview.disabled = false;
  }
}

/**
 * Build a short sample line that personalises the preview if the editor
 * has filled in a name, so they can hear how the voice says the avatar's
 * actual name. Falls back to a generic line otherwise.
 */
function sampleSentenceForAgent() {
  const name = (elements.name?.value || '').trim();
  if (name) {
    return `Hi, I'm ${name}. I'm here to help — ask me anything you'd like to learn.`;
  }
  return "Hi there, I'm here to help. Ask me anything you'd like to learn about.";
}

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

// ─────────────────────────────────────────────────────────────────────
//  TOP-LEVEL VIEW SWITCHING (Avatars / Users / Usage)
// ─────────────────────────────────────────────────────────────────────

const VIEW_NODES = {
  agents: document.getElementById('view-agents'),
  users:  document.getElementById('view-users'),
  usage:  document.getElementById('view-usage'),
};

function switchView(name) {
  Object.entries(VIEW_NODES).forEach(([n, node]) => {
    if (node) node.hidden = (n !== name);
  });
  document.querySelectorAll('.admin-tab').forEach((btn) => {
    btn.classList.toggle('admin-tab--active', btn.dataset.view === name);
  });
  if (name === 'users')  void loadUsers();
  if (name === 'usage')  void loadUsageOverview();
}

document.querySelectorAll('.admin-tab').forEach((btn) => {
  btn.addEventListener('click', () => switchView(btn.dataset.view));
});

// ─────────────────────────────────────────────────────────────────────
//  USERS VIEW
//  Search + paginated table + detail modal with subscription override.
// ─────────────────────────────────────────────────────────────────────

const usersState = { page: 1, perPage: 50, q: '', total: 0, lastPage: 1 };
const usersListEl = document.getElementById('users-list');
const usersPagerEl = document.getElementById('users-pager');
const usersSearchEl = document.getElementById('users-search');
const usersRefreshEl = document.getElementById('users-refresh');

function tokensCompact(n) {
  const v = Number(n) || 0;
  if (v >= 1_000_000) return (v / 1_000_000).toFixed(v % 1_000_000 === 0 ? 0 : 1) + 'M';
  if (v >= 1_000) return (v / 1_000).toFixed(v % 1_000 === 0 ? 0 : 1) + 'K';
  return String(v);
}

async function loadUsers() {
  if (!usersListEl) return;
  usersListEl.innerHTML = '<p style="color:#94a3b8;padding:14px">Loading...</p>';
  try {
    const params = new URLSearchParams({
      q: usersState.q,
      page: String(usersState.page),
      per_page: String(usersState.perPage),
    });
    const data = await api(`/api/v1/admin/users?${params.toString()}`);
    usersState.total = data.total;
    usersState.lastPage = data.last_page;
    renderUsersList(data.data);
    renderUsersPager();
  } catch (err) {
    usersListEl.innerHTML = `<p style="color:#ef4444;padding:14px">${escapeHtml(err.message || 'Failed to load')}</p>`;
  }
}

function renderUsersList(rows) {
  if (!rows || rows.length === 0) {
    usersListEl.innerHTML = '<p style="color:#94a3b8;padding:14px">No users match this search.</p>';
    return;
  }
  const head = `
    <div class="users-row users-row--head">
      <span>ID</span>
      <span>Name</span>
      <span>Email</span>
      <span>Plan</span>
      <span>Tokens 30d</span>
      <span>Joined</span>
    </div>`;
  const body = rows.map((u) => {
    const planClass = u.plan === 'free' ? 'users-row__plan--free' : '';
    const joined = u.created_at ? new Date(u.created_at).toISOString().slice(0, 10) : '—';
    return `
      <div class="users-row" data-user-id="${u.id}">
        <span class="users-row__id">#${u.id}</span>
        <span class="users-row__name">${escapeHtml(u.name || '—')}</span>
        <span class="users-row__email">${escapeHtml(u.email || '—')}</span>
        <span><span class="users-row__plan ${planClass}">${escapeHtml(u.plan_name || u.plan || 'Free')}</span></span>
        <span class="users-row__tokens">${tokensCompact(u.tokens_used_period)}</span>
        <span class="users-row__id">${joined}</span>
      </div>`;
  }).join('');
  usersListEl.innerHTML = head + body;
  usersListEl.querySelectorAll('.users-row[data-user-id]').forEach((row) => {
    row.addEventListener('click', () => openUserDetail(Number(row.dataset.userId)));
  });
}

function renderUsersPager() {
  const { page, lastPage, total } = usersState;
  usersPagerEl.innerHTML = `
    <button id="users-prev" ${page <= 1 ? 'disabled' : ''}>← Prev</button>
    <span>Page ${page} / ${lastPage} · ${total} total</span>
    <button id="users-next" ${page >= lastPage ? 'disabled' : ''}>Next →</button>
  `;
  const prev = document.getElementById('users-prev');
  const next = document.getElementById('users-next');
  if (prev) prev.addEventListener('click', () => { usersState.page--; void loadUsers(); });
  if (next) next.addEventListener('click', () => { usersState.page++; void loadUsers(); });
}

let usersSearchTimer = null;
if (usersSearchEl) {
  usersSearchEl.addEventListener('input', (e) => {
    clearTimeout(usersSearchTimer);
    usersSearchTimer = setTimeout(() => {
      usersState.q = e.target.value.trim();
      usersState.page = 1;
      void loadUsers();
    }, 250);
  });
}
if (usersRefreshEl) {
  usersRefreshEl.addEventListener('click', () => { void loadUsers(); });
}

// ─── User detail modal ───────────────────────────────────────────────
const userModalEl = document.getElementById('user-modal');
const userModalNameEl = document.getElementById('user-modal-name');
const userModalBodyEl = document.getElementById('user-modal-body');
const userModalCloseEl = document.getElementById('user-modal-close');

function closeUserModal() {
  userModalEl.hidden = true;
  userModalBodyEl.innerHTML = '';
}
if (userModalCloseEl) userModalCloseEl.addEventListener('click', closeUserModal);
document.querySelectorAll('[data-user-close]').forEach((el) => {
  el.addEventListener('click', closeUserModal);
});

async function openUserDetail(userId) {
  userModalEl.hidden = false;
  userModalNameEl.textContent = `User #${userId}`;
  userModalBodyEl.innerHTML = 'Loading...';
  try {
    const data = await api(`/api/v1/admin/users/${userId}`);
    renderUserDetail(data);
  } catch (err) {
    userModalBodyEl.innerHTML = `<p style="color:#ef4444">${escapeHtml(err.message || 'Failed')}</p>`;
  }
}

function renderUserDetail(data) {
  const u = data.user;
  const s = data.subscription;
  const p = data.profile;
  userModalNameEl.textContent = `${u.name} · ${u.email}`;

  const tokensRemainingLine = s.monthly_token_limit === null
    ? 'Unlimited'
    : `${tokensCompact(s.tokens_used_period)} / ${tokensCompact(s.monthly_token_limit)} used`;

  const profileBlock = p ? `
    <div class="user-modal__section">
      <h4>Profile</h4>
      <div class="user-modal__kv">
        <span>Display name</span><span>${escapeHtml(p.display_name || '—')}</span>
        <span>Language</span><span>${escapeHtml(p.preferred_language || '—')}</span>
        <span>Age band</span><span>${escapeHtml(p.age_band || '—')}</span>
        <span>Sex at birth</span><span>${escapeHtml(p.sex_at_birth || '—')}</span>
        <span>Goals</span><span>${escapeHtml((p.goals || []).join(', ') || '—')}</span>
        <span>Conditions</span><span>${escapeHtml((p.conditions || []).join(', ') || '—')}</span>
        <span>Allergies</span><span>${escapeHtml((p.allergies || []).join(', ') || '—')}</span>
      </div>
    </div>` : '';

  const conversationsBlock = (data.recent_conversations || []).length === 0
    ? '<p style="color:#94a3b8;font-size:13px">No conversations yet.</p>'
    : data.recent_conversations.map((c) => `
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.06);font-size:13px">
          <span><strong>${escapeHtml(c.agent_name || '—')}</strong> · ${c.message_count} msgs</span>
          <span style="color:#94a3b8">${c.last_message_at ? new Date(c.last_message_at).toLocaleString() : '—'}</span>
        </div>`).join('');

  userModalBodyEl.innerHTML = `
    <div class="user-modal__section">
      <h4>Subscription</h4>
      <div class="user-modal__kv">
        <span>Plan</span><span><strong>${escapeHtml(s.plan_name || s.plan || 'Free')}</strong> (${escapeHtml(s.status)})</span>
        <span>Tokens period</span><span>${tokensRemainingLine}</span>
        <span>Daily messages</span><span>${s.daily_limit === null ? 'Unlimited' : `${s.used_today} / ${s.daily_limit}`}</span>
        <span>Renews at</span><span>${s.renews_at ? new Date(s.renews_at).toLocaleString() : '—'}</span>
        <span>Trial ends</span><span>${s.trial_ends_at ? new Date(s.trial_ends_at).toLocaleString() : '—'}</span>
        <span>Provider</span><span>${escapeHtml(s.billing_provider || '—')}</span>
      </div>
      <div style="margin-top:12px">
        <h4>Manual override</h4>
        <div class="user-modal__sub-controls">
          <select id="user-modal-plan">
            <option value="free">Free</option>
            <option value="basic">Basic</option>
            <option value="pro">Pro</option>
            <option value="ultimate">Ultimate</option>
          </select>
          <select id="user-modal-status">
            <option value="active">Active</option>
            <option value="trialing">Trialing</option>
            <option value="cancelled">Cancelled</option>
            <option value="expired">Expired</option>
          </select>
          <input id="user-modal-note" type="text" placeholder="Note (optional)">
          <button id="user-modal-grant" type="button">Apply</button>
        </div>
        <p style="color:#94a3b8;font-size:11px;margin-top:6px">
          Overrides the user's plan + status immediately. Logged in
          billing_metadata.admin_overrides for audit. Use this for
          comps, refund recovery, or to revoke access — payment-side
          ops (Apple/Google refunds) still happen on RevenueCat.
        </p>
      </div>
    </div>
    ${profileBlock}
    <div class="user-modal__section">
      <h4>Recent conversations</h4>
      ${conversationsBlock}
    </div>
  `;

  // Pre-select current plan + status in the override form so a typo
  // can't accidentally downgrade someone with the wrong default.
  const planSel = document.getElementById('user-modal-plan');
  const statusSel = document.getElementById('user-modal-status');
  if (planSel && s.plan) planSel.value = s.plan;
  if (statusSel && s.status && s.status !== 'none') statusSel.value = s.status;

  const grantBtn = document.getElementById('user-modal-grant');
  if (grantBtn) {
    grantBtn.addEventListener('click', () => {
      void grantSubscription(u.id, planSel.value, statusSel.value, document.getElementById('user-modal-note').value);
    });
  }
}

async function grantSubscription(userId, planSlug, status, note) {
  const ok = window.confirm(`Set user #${userId} to ${planSlug} (${status})?`);
  if (!ok) return;
  try {
    await api(`/api/v1/admin/users/${userId}/subscription`, {
      method: 'POST',
      body: JSON.stringify({ plan_slug: planSlug, status, note }),
    });
    setStatus(`User #${userId} set to ${planSlug}/${status}`);
    await openUserDetail(userId);
    void loadUsers();
  } catch (err) {
    alert('Failed: ' + (err.message || 'unknown'));
  }
}

// ─────────────────────────────────────────────────────────────────────
//  USAGE OVERVIEW
// ─────────────────────────────────────────────────────────────────────

const usageOverviewEl = document.getElementById('usage-overview');
const usageOverviewRefreshEl = document.getElementById('usage-overview-refresh');

if (usageOverviewRefreshEl) {
  usageOverviewRefreshEl.addEventListener('click', () => { void loadUsageOverview(); });
}

async function loadUsageOverview() {
  if (!usageOverviewEl) return;
  usageOverviewEl.innerHTML = '<p style="color:#94a3b8;padding:14px">Loading...</p>';
  try {
    const d = await api('/api/v1/admin/usage-overview');
    const costUsd = ((d.cost_usd_cents_30d ?? 0) / 100).toFixed(2);
    const tokensTotal = (d.tokens_in_30d ?? 0) + (d.tokens_out_30d ?? 0);
    const planMix = (d.plan_mix || []).map((m) => `
      <span class="usage-card__planpill">${escapeHtml(m.name || m.slug)}: ${m.user_count}</span>
    `).join('');

    usageOverviewEl.innerHTML = `
      <div class="usage-card">
        <div class="usage-card__label">Total users</div>
        <div class="usage-card__value">${d.total_users.toLocaleString()}</div>
        <div class="usage-card__sub">${d.active_users_30d} active in 30d</div>
      </div>
      <div class="usage-card">
        <div class="usage-card__label">Messages sent · 30d</div>
        <div class="usage-card__value">${(d.messages_sent_30d || 0).toLocaleString()}</div>
        <div class="usage-card__sub">${d.llm_calls_30d.toLocaleString()} model calls</div>
      </div>
      <div class="usage-card">
        <div class="usage-card__label">Tokens · 30d</div>
        <div class="usage-card__value">${tokensCompact(tokensTotal)}</div>
        <div class="usage-card__sub">${tokensCompact(d.tokens_in_30d)} in · ${tokensCompact(d.tokens_out_30d)} out</div>
      </div>
      <div class="usage-card">
        <div class="usage-card__label">OpenAI cost · 30d</div>
        <div class="usage-card__value">$${costUsd}</div>
        <div class="usage-card__sub">All purposes (chat + verification + TTS + STT)</div>
      </div>
      ${planMix ? `
        <div class="usage-card usage-card--full">
          <div class="usage-card__label">Active plan mix</div>
          <div class="usage-card__planmix">${planMix}</div>
        </div>` : ''}
    `;
  } catch (err) {
    usageOverviewEl.innerHTML = `<p style="color:#ef4444;padding:14px">${escapeHtml(err.message || 'Failed')}</p>`;
  }
}
