<?php
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Device Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@7.x/css/materialdesignicons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/vuetify@3.5.2/dist/vuetify.min.css" rel="stylesheet">
</head>
<body>
    <!-- Auth guard check completed in PHP. Populating global user data: -->
    <script>
    window.__authUser = <?php echo json_encode([
        'id'    => $_SESSION['user_id'],
        'name'  => $_SESSION['user_name'],
        'email' => $_SESSION['user_email']
    ]); ?>;
    </script>

    <div id="app"></div>

    <script src="https://cdn.jsdelivr.net/npm/vue@3.4.15/dist/vue.global.prod.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/vuetify@3.5.2/dist/vuetify.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/vuex@4.1.0/dist/vuex.global.prod.js"></script>

    <script>
    const { createApp, ref, computed, onMounted, onUnmounted, watch } = Vue;
    const { createVuetify } = Vuetify;
    const { createStore }   = Vuex;

    // Use a path relative to this page so it works in any subdirectory
    const API = 'baileys-proxy.php';
    const MAX = 10;

    // -------------------------------------------------------------------------
    // Vuex Store
    // -------------------------------------------------------------------------
    const store = createStore({
        state() {
            return {
                sessions: [],          // [{ session_id, label, status, phone, qr, polling }]
                maxSessions: MAX,
                globalLoading: false,
                snackbar: { show: false, text: '', color: 'success' }
            };
        },
        mutations: {
            SET_SESSIONS(state, list) {
                // Merge incoming list with existing runtime QR / polling data
                state.sessions = list.map(incoming => {
                    const existing = state.sessions.find(s => s.session_id === incoming.session_id);
                    return {
                        ...incoming,
                        qr:      existing ? existing.qr      : null,
                        polling: existing ? existing.polling : null
                    };
                });
            },
            SET_MAX(state, val) { state.maxSessions = val; },
            UPDATE_SESSION(state, patch) {
                const idx = state.sessions.findIndex(s => s.session_id === patch.session_id);
                if (idx !== -1) Object.assign(state.sessions[idx], patch);
            },
            ADD_SESSION(state, session) {
                state.sessions.push({ ...session, qr: null, polling: null });
            },
            REMOVE_SESSION(state, sessionId) {
                state.sessions = state.sessions.filter(s => s.session_id !== sessionId);
            },
            SET_LOADING(state, val) { state.globalLoading = val; },
            SHOW_SNACK(state, { text, color }) {
                state.snackbar = { show: true, text, color: color || 'success' };
            },
            HIDE_SNACK(state) { state.snackbar.show = false; }
        },
        actions: {
            async loadSessions({ commit, dispatch }) {
                commit('SET_LOADING', true);
                try {
                    const res  = await fetch(`${API}?path=/api/sessions/list`);
                    const data = await res.json();
                    commit('SET_SESSIONS', data.sessions || []);
                    commit('SET_MAX', data.max || MAX);
                    // Resume polling for any non-settled sessions
                    for (const s of (data.sessions || [])) {
                        if (s.status === 'SCAN_QR' || s.status === 'CONNECTING') {
                            dispatch('startPolling', s.session_id);
                        }
                    }
                } catch(e) {
                    commit('SHOW_SNACK', { text: 'Failed to load sessions.', color: 'error' });
                } finally {
                    commit('SET_LOADING', false);
                }
            },

            async addSession({ state, commit, dispatch }, { sessionId, label }) {
                if (state.sessions.length >= state.maxSessions) {
                    commit('SHOW_SNACK', { text: `Max ${state.maxSessions} sessions reached.`, color: 'error' });
                    return false;
                }
                try {
                    const res  = await fetch(`${API}?path=/api/session/start`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ sessionId, label })
                    });
                    const data = await res.json();
                    if (data.error) throw new Error(data.error);

                    commit('ADD_SESSION', { session_id: sessionId, label: label || sessionId, status: 'CONNECTING', phone: null });
                    dispatch('startPolling', sessionId);
                    commit('SHOW_SNACK', { text: `Session "${label || sessionId}" added.` });
                    return true;
                } catch(e) {
                    commit('SHOW_SNACK', { text: e.message, color: 'error' });
                    return false;
                }
            },

            async reconnectSession({ commit, dispatch }, { session_id, label }) {
                commit('UPDATE_SESSION', { session_id, status: 'CONNECTING', qr: null });
                try {
                    await fetch(`${API}?path=/api/session/start`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ sessionId: session_id, label })
                    });
                    dispatch('startPolling', session_id);
                } catch(e) {
                    commit('UPDATE_SESSION', { session_id, status: 'DISCONNECTED' });
                }
            },

            async deleteSession({ state, commit, dispatch }, sessionId) {
                dispatch('stopPolling', sessionId);
                try {
                    await fetch(`${API}?path=/api/session/${sessionId}`, { method: 'DELETE' });
                    commit('REMOVE_SESSION', sessionId);
                    commit('SHOW_SNACK', { text: `Session "${sessionId}" removed.`, color: 'warning' });
                } catch(e) {
                    commit('SHOW_SNACK', { text: 'Delete failed: ' + e.message, color: 'error' });
                }
            },

            async pollStatus({ commit, dispatch, state }, sessionId) {
                try {
                    const res  = await fetch(`${API}?path=/api/session/status/${sessionId}`);
                    const data = await res.json();
                    commit('UPDATE_SESSION', { session_id: sessionId, status: data.status, qr: data.qr || null });

                    if (data.status !== 'SCAN_QR' && data.status !== 'CONNECTING') {
                        dispatch('stopPolling', sessionId);
                    }
                } catch(_) {}
            },

            startPolling({ state, commit, dispatch }, sessionId) {
                dispatch('stopPolling', sessionId);
                const interval = setInterval(() => dispatch('pollStatus', sessionId), 3000);
                const s = state.sessions.find(s => s.session_id === sessionId);
                if (s) s.polling = interval;
                // Kick once immediately
                dispatch('pollStatus', sessionId);
            },

            stopPolling({ state }, sessionId) {
                const s = state.sessions.find(s => s.session_id === sessionId);
                if (s && s.polling) { clearInterval(s.polling); s.polling = null; }
            },

            stopAllPolling({ state, dispatch }) {
                for (const s of state.sessions) dispatch('stopPolling', s.session_id);
            }
        }
    });

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
    function statusColor(status) {
        if (status === 'CONNECTED')   return 'success';
        if (status === 'SCAN_QR')     return 'warning';
        if (status === 'CONNECTING')  return 'info';
        return 'error';
    }
    function statusIcon(status) {
        if (status === 'CONNECTED')   return 'mdi-lan-connect';
        if (status === 'SCAN_QR')     return 'mdi-qrcode-scan';
        if (status === 'CONNECTING')  return 'mdi-sync';
        return 'mdi-lan-disconnect';
    }

    // -------------------------------------------------------------------------
    // App
    // -------------------------------------------------------------------------
    const vuetify = createVuetify({ theme: { defaultTheme: 'light' } });

    const app = createApp({
        template: `
<v-app theme="light">

  <!-- Top Bar -->
  <v-app-bar color="green-darken-2" elevation="2">
    <v-app-bar-title>
      <v-icon icon="mdi-whatsapp" class="mr-2"></v-icon>
      WhatsApp Device Manager
    </v-app-bar-title>
    <template v-slot:append>
      <v-chip color="white" variant="outlined" class="mr-3">
        {{ sessions.length }} / {{ maxSessions }} slots
      </v-chip>
      <v-btn icon="mdi-refresh" variant="text" color="white" @click="reload" :loading="loading" title="Refresh"></v-btn>
      <v-divider vertical class="mx-1" style="border-color:rgba(255,255,255,.25);"></v-divider>
      <v-chip color="white" variant="text" class="mx-1 text-caption" prepend-icon="mdi-account-circle">
        {{ authUser.name }}
      </v-chip>
      <v-btn
        id="btn-logout"
        variant="outlined"
        color="white"
        size="small"
        prepend-icon="mdi-logout"
        class="mr-2"
        :loading="logoutLoading"
        @click="doLogout"
      >Logout</v-btn>
    </template>
  </v-app-bar>

  <v-main class="bg-grey-lighten-4">
    <v-container class="py-6">

      <!-- Navigation Tabs -->
      <v-tabs v-model="activeTab" color="green-darken-2" align-tabs="start" class="mb-6 bg-white rounded-lg elevation-1">
        <v-tab value="sessions" prepend-icon="mdi-whatsapp">
          WhatsApp Sessions
        </v-tab>
        <v-tab value="tutorial" prepend-icon="mdi-code-json">
          API Tutorial &amp; Code Examples
        </v-tab>
      </v-tabs>

      <!-- Tabs Windows -->
      <v-window v-model="activeTab">

        <!-- Tab 1: Sessions List -->
        <v-window-item value="sessions">
          <!-- Empty state -->
          <div v-if="!loading && sessions.length === 0" class="text-center py-12">
            <v-icon icon="mdi-cellphone-off" size="72" color="grey-lighten-1" class="mb-4"></v-icon>
            <p class="text-h6 text-grey">No sessions registered yet.</p>
            <p class="text-body-2 text-grey-darken-1 mb-6">Add up to {{ maxSessions }} WhatsApp numbers.</p>
            <v-btn color="success" prepend-icon="mdi-plus" @click="openAddDialog">Add First Session</v-btn>
          </div>

          <!-- Session grid -->
          <v-row v-else>
            <v-col
              v-for="s in sessions"
              :key="s.session_id"
              cols="12" sm="6" md="4" lg="3"
            >
              <v-card class="rounded-lg" elevation="3" height="100%">

                <!-- Card header -->
                <v-card-item>
                  <template v-slot:prepend>
                    <v-icon icon="mdi-whatsapp" :color="statusColor(s.status)" size="32"></v-icon>
                  </template>
                  <v-card-title class="text-subtitle-1 font-weight-bold">{{ s.label || s.session_id }}</v-card-title>
                  <v-card-subtitle class="text-caption">{{ s.session_id }}</v-card-subtitle>
                  <template v-slot:append>
                    <v-menu>
                      <template v-slot:activator="{ props }">
                        <v-btn v-bind="props" icon="mdi-dots-vertical" size="small" variant="text"></v-btn>
                      </template>
                      <v-list density="compact">
                        <v-list-item prepend-icon="mdi-refresh" title="Reconnect" @click="reconnect(s)" :disabled="s.status === 'CONNECTED' || s.status === 'CONNECTING'"></v-list-item>
                        <v-list-item prepend-icon="mdi-content-copy" title="Copy Token" @click="copyToken(s.session_id)"></v-list-item>
                        <v-list-item prepend-icon="mdi-delete" title="Remove" base-color="error" @click="confirmDelete(s)"></v-list-item>
                      </v-list>
                    </v-menu>
                  </template>
                </v-card-item>

                <v-divider></v-divider>

                <!-- Status chip -->
                <div class="px-4 pt-3 pb-1">
                  <v-chip :color="statusColor(s.status)" size="small" class="font-weight-bold text-uppercase">
                    <v-icon start :icon="statusIcon(s.status)" size="14"></v-icon>
                    {{ s.status }}
                  </v-chip>
                  <span v-if="s.phone" class="text-caption text-grey-darken-2 ml-2">{{ s.phone }}</span>
                </div>

                <!-- Card body -->
                <v-card-text class="d-flex flex-column align-center justify-center" style="min-height:180px">

                  <!-- DISCONNECTED -->
                  <div v-if="s.status === 'DISCONNECTED'" class="text-center">
                    <v-icon icon="mdi-wifi-off" size="40" color="grey-lighten-1" class="mb-2"></v-icon>
                    <p class="text-caption text-grey mb-3">Not connected</p>
                    <v-btn size="small" color="success" variant="tonal" prepend-icon="mdi-link-variant" @click="reconnect(s)">Connect</v-btn>
                  </div>

                  <!-- CONNECTING -->
                  <div v-if="s.status === 'CONNECTING'" class="text-center">
                    <v-progress-circular indeterminate color="info" size="40" class="mb-2"></v-progress-circular>
                    <p class="text-caption text-grey-darken-1">Initialising...</p>
                  </div>

                  <!-- SCAN_QR -->
                  <div v-if="s.status === 'SCAN_QR'" class="text-center">
                    <div v-if="s.qr">
                      <p class="text-caption text-grey-darken-2 mb-2">Scan with WhatsApp → Linked Devices</p>
                      <v-img :src="s.qr" width="160" height="160" class="mx-auto elevation-1 rounded"></v-img>
                    </div>
                    <div v-else>
                      <v-progress-circular indeterminate color="warning" size="40" class="mb-2"></v-progress-circular>
                      <p class="text-caption text-grey-darken-1">Waiting for QR...</p>
                    </div>
                  </div>

                  <!-- CONNECTED -->
                  <div v-if="s.status === 'CONNECTED'" class="text-center">
                    <v-icon icon="mdi-check-circle" color="success" size="48" class="mb-2"></v-icon>
                    <p class="text-success text-caption font-weight-bold">Active &amp; listening</p>
                  </div>

                </v-card-text>

                <!-- Token row -->
                <v-divider></v-divider>
                <div class="px-3 py-2 d-flex align-center" style="background:#f9fbe7;border-radius:0 0 8px 8px">
                  <v-icon icon="mdi-key-variant" size="14" color="grey-darken-1" class="mr-1"></v-icon>
                  <span class="text-caption text-grey-darken-2 mr-1" style="font-family:monospace;font-size:10px;word-break:break-all;flex:1">{{ s.session_id }}</span>
                  <v-btn
                    icon="mdi-content-copy"
                    size="x-small"
                    variant="text"
                    color="grey-darken-1"
                    :title="'Copy token: ' + s.session_id"
                    @click="copyToken(s.session_id)"
                  ></v-btn>
                </div>
              </v-card>
            </v-col>

            <!-- Add slot card -->
            <v-col v-if="sessions.length < maxSessions" cols="12" sm="6" md="4" lg="3">
              <v-card
                class="rounded-lg d-flex align-center justify-center"
                elevation="1"
                height="100%"
                min-height="260"
                style="cursor:pointer; border: 2px dashed #aaa;"
                @click="openAddDialog"
              >
                <div class="text-center pa-6">
                  <v-icon icon="mdi-plus-circle-outline" size="48" color="grey-lighten-1"></v-icon>
                  <p class="text-body-2 text-grey mt-2">Add Session</p>
                </div>
              </v-card>
            </v-col>
          </v-row>
        </v-window-item>

        <!-- Tab 2: REST API Tutorial -->
        <v-window-item value="tutorial">
          <v-row>
            <v-col cols="12" md="4" lg="3">
              <v-card class="rounded-lg mb-4 pa-4" elevation="2">
                <div class="text-subtitle-1 font-weight-bold mb-3">
                  <v-icon icon="mdi-information-outline" class="mr-2" color="blue"></v-icon>API Info
                </div>
                <div class="text-body-2 mb-4">
                  All REST endpoints require authentication using the Session Token as a Bearer token.
                </div>
                
                <v-alert type="info" variant="tonal" class="mb-4 text-caption" density="compact">
                  <strong>Header Format:</strong><br>
                  <code>Authorization: Bearer &lt;token&gt;</code>
                </v-alert>

                <!-- Token Selector -->
                <v-select
                  v-model="selectedToken"
                  :items="sessions"
                  item-title="label"
                  item-value="session_id"
                  label="Select Active Token"
                  variant="outlined"
                  density="compact"
                  hint="Select a session to auto-fill snippets"
                  persistent-hint
                  no-data-text="No active sessions"
                  class="mb-4"
                ></v-select>

                <div class="text-caption text-grey-darken-2">
                  <strong>Base API URL:</strong><br>
                  <code class="text-blue-darken-2 break-all">{{ apiBaseUrl }}</code>
                </div>
              </v-card>
            </v-col>

            <v-col cols="12" md="8" lg="9">
              <v-card class="rounded-lg mb-6" elevation="2">
                <v-tabs v-model="subTab" color="green-darken-2">
                  <v-tab value="send-text">Send Text</v-tab>
                  <v-tab value="send-image">Send Image</v-tab>
                  <v-tab value="send-file">Send File</v-tab>
                  <v-tab value="status">Session Status</v-tab>
                </v-tabs>

                <v-divider></v-divider>

                <v-card-text>
                  <v-window v-model="subTab">
                    
                    <!-- Send Text -->
                    <v-window-item value="send-text">
                      <div class="text-subtitle-1 font-weight-bold mb-1">Send Text Message</div>
                      <div class="text-body-2 text-grey-darken-1 mb-4">
                        Sends a plain text message to a specific WhatsApp number. JID phone numbers automatically handle trailing <code>@s.whatsapp.net</code>.
                      </div>
                      
                      <v-chip class="mb-4 font-weight-bold" color="success" size="small">POST</v-chip>
                      <code class="text-body-2 font-weight-bold ml-2">{{ apiBaseUrl }}/send/text</code>

                      <v-tabs v-model="langText" density="compact" color="success" class="mt-4 border-bottom">
                        <v-tab value="curl">cURL</v-tab>
                        <v-tab value="php">PHP</v-tab>
                      </v-tabs>

                      <v-window v-model="langText" class="mt-2">
                        <v-window-item value="curl">
                          <v-sheet class="pa-3 rounded bg-grey-darken-4 text-white text-caption position-relative" style="font-family: monospace; white-space: pre-wrap; word-break: break-all;">
                            <v-btn icon="mdi-content-copy" size="x-small" variant="text" color="white" class="position-absolute" style="top: 8px; right: 8px;" @click="copyToClipboard(curlTextSnippet)"></v-btn>
                            {{ curlTextSnippet }}
                          </v-sheet>
                        </v-window-item>
                        <v-window-item value="php">
                          <v-sheet class="pa-3 rounded bg-grey-darken-4 text-white text-caption position-relative" style="font-family: monospace; white-space: pre-wrap; word-break: break-all;">
                            <v-btn icon="mdi-content-copy" size="x-small" variant="text" color="white" class="position-absolute" style="top: 8px; right: 8px;" @click="copyToClipboard(phpTextSnippet)"></v-btn>
                            {{ phpTextSnippet }}
                          </v-sheet>
                        </v-window-item>
                      </v-window>
                    </v-window-item>

                    <!-- Send Image -->
                    <v-window-item value="send-image">
                      <div class="text-subtitle-1 font-weight-bold mb-1">Send Image</div>
                      <div class="text-body-2 text-grey-darken-1 mb-4">
                        Send an image via a remote image URL (JSON body) or by uploading a file (multipart/form-data).
                      </div>

                      <v-chip class="mb-4 font-weight-bold" color="success" size="small">POST</v-chip>
                      <code class="text-body-2 font-weight-bold ml-2">{{ apiBaseUrl }}/send/image</code>

                      <v-tabs v-model="langImage" density="compact" color="success" class="mt-4 border-bottom">
                        <v-tab value="curl-url">cURL (URL)</v-tab>
                        <v-tab value="curl-file">cURL (Upload)</v-tab>
                        <v-tab value="php-url">PHP (URL)</v-tab>
                        <v-tab value="php-file">PHP (Upload)</v-tab>
                      </v-tabs>

                      <v-window v-model="langImage" class="mt-2">
                        <v-window-item value="curl-url">
                          <v-sheet class="pa-3 rounded bg-grey-darken-4 text-white text-caption position-relative" style="font-family: monospace; white-space: pre-wrap; word-break: break-all;">
                            <v-btn icon="mdi-content-copy" size="x-small" variant="text" color="white" class="position-absolute" style="top: 8px; right: 8px;" @click="copyToClipboard(curlImageUrlSnippet)"></v-btn>
                            {{ curlImageUrlSnippet }}
                          </v-sheet>
                        </v-window-item>
                        <v-window-item value="curl-file">
                          <v-sheet class="pa-3 rounded bg-grey-darken-4 text-white text-caption position-relative" style="font-family: monospace; white-space: pre-wrap; word-break: break-all;">
                            <v-btn icon="mdi-content-copy" size="x-small" variant="text" color="white" class="position-absolute" style="top: 8px; right: 8px;" @click="copyToClipboard(curlImageFileSnippet)"></v-btn>
                            {{ curlImageFileSnippet }}
                          </v-sheet>
                        </v-window-item>
                        <v-window-item value="php-url">
                          <v-sheet class="pa-3 rounded bg-grey-darken-4 text-white text-caption position-relative" style="font-family: monospace; white-space: pre-wrap; word-break: break-all;">
                            <v-btn icon="mdi-content-copy" size="x-small" variant="text" color="white" class="position-absolute" style="top: 8px; right: 8px;" @click="copyToClipboard(phpImageUrlSnippet)"></v-btn>
                            {{ phpImageUrlSnippet }}
                          </v-sheet>
                        </v-window-item>
                        <v-window-item value="php-file">
                          <v-sheet class="pa-3 rounded bg-grey-darken-4 text-white text-caption position-relative" style="font-family: monospace; white-space: pre-wrap; word-break: break-all;">
                            <v-btn icon="mdi-content-copy" size="x-small" variant="text" color="white" class="position-absolute" style="top: 8px; right: 8px;" @click="copyToClipboard(phpImageFileSnippet)"></v-btn>
                            {{ phpImageFileSnippet }}
                          </v-sheet>
                        </v-window-item>
                      </v-window>
                    </v-window-item>

                    <!-- Send File -->
                    <v-window-item value="send-file">
                      <div class="text-subtitle-1 font-weight-bold mb-1">Send File (Document)</div>
                      <div class="text-body-2 text-grey-darken-1 mb-4">
                        Send a document (PDF, Docx, etc.) via remote URL or direct file upload.
                      </div>

                      <v-chip class="mb-4 font-weight-bold" color="success" size="small">POST</v-chip>
                      <code class="text-body-2 font-weight-bold ml-2">{{ apiBaseUrl }}/send/file</code>

                      <v-tabs v-model="langFile" density="compact" color="success" class="mt-4 border-bottom">
                        <v-tab value="curl-url">cURL (URL)</v-tab>
                        <v-tab value="curl-file">cURL (Upload)</v-tab>
                        <v-tab value="php-url">PHP (URL)</v-tab>
                        <v-tab value="php-file">PHP (Upload)</v-tab>
                      </v-tabs>

                      <v-window v-model="langFile" class="mt-2">
                        <v-window-item value="curl-url">
                          <v-sheet class="pa-3 rounded bg-grey-darken-4 text-white text-caption position-relative" style="font-family: monospace; white-space: pre-wrap; word-break: break-all;">
                            <v-btn icon="mdi-content-copy" size="x-small" variant="text" color="white" class="position-absolute" style="top: 8px; right: 8px;" @click="copyToClipboard(curlFileUrlSnippet)"></v-btn>
                            {{ curlFileUrlSnippet }}
                          </v-sheet>
                        </v-window-item>
                        <v-window-item value="curl-file">
                          <v-sheet class="pa-3 rounded bg-grey-darken-4 text-white text-caption position-relative" style="font-family: monospace; white-space: pre-wrap; word-break: break-all;">
                            <v-btn icon="mdi-content-copy" size="x-small" variant="text" color="white" class="position-absolute" style="top: 8px; right: 8px;" @click="copyToClipboard(curlFileFileSnippet)"></v-btn>
                            {{ curlFileFileSnippet }}
                          </v-sheet>
                        </v-window-item>
                        <v-window-item value="php-url">
                          <v-sheet class="pa-3 rounded bg-grey-darken-4 text-white text-caption position-relative" style="font-family: monospace; white-space: pre-wrap; word-break: break-all;">
                            <v-btn icon="mdi-content-copy" size="x-small" variant="text" color="white" class="position-absolute" style="top: 8px; right: 8px;" @click="copyToClipboard(phpFileUrlSnippet)"></v-btn>
                            {{ phpFileUrlSnippet }}
                          </v-sheet>
                        </v-window-item>
                        <v-window-item value="php-file">
                          <v-sheet class="pa-3 rounded bg-grey-darken-4 text-white text-caption position-relative" style="font-family: monospace; white-space: pre-wrap; word-break: break-all;">
                            <v-btn icon="mdi-content-copy" size="x-small" variant="text" color="white" class="position-absolute" style="top: 8px; right: 8px;" @click="copyToClipboard(phpFileFileSnippet)"></v-btn>
                            {{ phpFileFileSnippet }}
                          </v-sheet>
                        </v-window-item>
                      </v-window>
                    </v-window-item>

                    <!-- Session Status -->
                    <v-window-item value="status">
                      <div class="text-subtitle-1 font-weight-bold mb-1">Check Session Status</div>
                      <div class="text-body-2 text-grey-darken-1 mb-4">
                        Retrieve connection status information about the session representing your current API Key/Token.
                      </div>

                      <v-chip class="mb-4 font-weight-bold" color="info" size="small">GET</v-chip>
                      <code class="text-body-2 font-weight-bold ml-2">{{ apiBaseUrl }}/sessions</code>

                      <v-tabs v-model="langStatus" density="compact" color="success" class="mt-4 border-bottom">
                        <v-tab value="curl">cURL</v-tab>
                        <v-tab value="php">PHP</v-tab>
                      </v-tabs>

                      <v-window v-model="langStatus" class="mt-2">
                        <v-window-item value="curl">
                          <v-sheet class="pa-3 rounded bg-grey-darken-4 text-white text-caption position-relative" style="font-family: monospace; white-space: pre-wrap; word-break: break-all;">
                            <v-btn icon="mdi-content-copy" size="x-small" variant="text" color="white" class="position-absolute" style="top: 8px; right: 8px;" @click="copyToClipboard(curlStatusSnippet)"></v-btn>
                            {{ curlStatusSnippet }}
                          </v-sheet>
                        </v-window-item>
                        <v-window-item value="php">
                          <v-sheet class="pa-3 rounded bg-grey-darken-4 text-white text-caption position-relative" style="font-family: monospace; white-space: pre-wrap; word-break: break-all;">
                            <v-btn icon="mdi-content-copy" size="x-small" variant="text" color="white" class="position-absolute" style="top: 8px; right: 8px;" @click="copyToClipboard(phpStatusSnippet)"></v-btn>
                            {{ phpStatusSnippet }}
                          </v-sheet>
                        </v-window-item>
                      </v-window>
                    </v-window-item>

                  </v-window>
                </v-card-text>
              </v-card>
            </v-col>
          </v-row>
        </v-window-item>

      </v-window>

    </v-container>
  </v-main>

  <!-- ── Add Session Dialog ── -->
  <v-dialog v-model="addDialog" max-width="460" persistent>
    <v-card class="pa-2 rounded-lg">
      <v-card-title class="text-h6 pt-4 px-4">
        <v-icon icon="mdi-plus" class="mr-2" color="success"></v-icon>Add New Session
      </v-card-title>
      <v-card-text>
        <v-text-field
          v-model="newLabel"
          label="Label (e.g. Sales Line 1)"
          variant="outlined"
          density="compact"
          class="mb-3"
          clearable
          autofocus
        ></v-text-field>
        <v-text-field
          v-model="newSessionId"
          label="Session Token (auto-generated)"
          variant="outlined"
          density="compact"
          readonly
          hint="This token is the API key to send messages via this session"
          persistent-hint
          :append-inner-icon="'mdi-refresh'"
          @click:append-inner="regenerateSessionId"
        >
          <template v-slot:prepend-inner>
            <v-icon icon="mdi-key-variant" color="grey" size="18"></v-icon>
          </template>
        </v-text-field>
      </v-card-text>
      <v-card-actions class="px-4 pb-4">
        <v-spacer></v-spacer>
        <v-btn variant="text" @click="addDialog = false">Cancel</v-btn>
        <v-btn color="success" variant="flat" :loading="addLoading" @click="submitAdd" :disabled="!newSessionId || !newLabel">Add</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- ── Delete Confirm Dialog ── -->
  <v-dialog v-model="deleteDialog" max-width="380">
    <v-card class="pa-2 rounded-lg">
      <v-card-title class="text-h6 pt-4 px-4">Remove Session?</v-card-title>
      <v-card-text>
        This will disconnect and permanently remove <strong>{{ deleteTarget?.label || deleteTarget?.session_id }}</strong>.
        The device will be logged out.
      </v-card-text>
      <v-card-actions class="px-4 pb-4">
        <v-spacer></v-spacer>
        <v-btn variant="text" @click="deleteDialog = false">Cancel</v-btn>
        <v-btn color="error" variant="flat" @click="doDelete">Remove</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- ── Snackbar ── -->
  <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3500" location="bottom right">
    {{ snackbar.text }}
    <template v-slot:actions>
      <v-btn variant="text" icon="mdi-close" @click="$store.commit('HIDE_SNACK')"></v-btn>
    </template>
  </v-snackbar>

</v-app>
        `,
        setup() {
            const sessions    = computed(() => store.state.sessions);
            const maxSessions = computed(() => store.state.maxSessions);
            const loading     = computed(() => store.state.globalLoading);
            const snackbar    = computed(() => store.state.snackbar);

            // Auth user info
            const authUser = ref(window.__authUser || { name: '...', email: '' });

            // Navigation tabs
            const activeTab = ref('sessions');
            const subTab = ref('send-text');
            const langText = ref('curl');
            const langImage = ref('curl-url');
            const langFile = ref('curl-url');
            const langStatus = ref('curl');
            const selectedToken = ref('');

            const apiBaseUrl = computed(() => {
                return window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '') + '/api.php';
            });

            const tokenToUse = computed(() => selectedToken.value || 'YOUR_SESSION_TOKEN');

            watch(sessions, (newVal) => {
                if (newVal && newVal.length > 0 && !selectedToken.value) {
                    selectedToken.value = newVal[0].session_id;
                }
            }, { immediate: true });

            // Code Snippets computed properties
            const curlTextSnippet = computed(() => `curl -X POST "${apiBaseUrl.value}/send/text" \\
  -H "Authorization: Bearer ${tokenToUse.value}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "to": "628123456789",
    "message": "Hello from API!"
  }'`);

            const phpTextSnippet = computed(() => `<?php
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "${apiBaseUrl.value}/send/text",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => json_encode([
        "to" => "628123456789",
        "message" => "Hello from API!"
    ]),
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer ${tokenToUse.value}",
        "Content-Type: application/json"
    ],
]);
$response = curl_exec($curl);
curl_close($curl);
echo $response;`);

            const curlImageUrlSnippet = computed(() => `curl -X POST "${apiBaseUrl.value}/send/image" \\
  -H "Authorization: Bearer ${tokenToUse.value}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "to": "628123456789",
    "caption": "Check this image",
    "url": "https://example.com/image.jpg"
  }'`);

            const curlImageFileSnippet = computed(() => `curl -X POST "${apiBaseUrl.value}/send/image" \\
  -H "Authorization: Bearer ${tokenToUse.value}" \\
  -F "to=628123456789" \\
  -F "caption=My Uploaded Image" \\
  -F "file=@/path/to/image.jpg"`);

            const phpImageUrlSnippet = computed(() => `<?php
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "${apiBaseUrl.value}/send/image",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => json_encode([
        "to" => "628123456789",
        "caption" => "Check this image",
        "url" => "https://example.com/image.jpg"
    ]),
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer ${tokenToUse.value}",
        "Content-Type: application/json"
    ],
]);
$response = curl_exec($curl);
curl_close($curl);
echo $response;`);

            const phpImageFileSnippet = computed(() => `<?php
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "${apiBaseUrl.value}/send/image",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => [
        "to" => "628123456789",
        "caption" => "My Uploaded Image",
        "file" => new CURLFile('/path/to/image.jpg', 'image/jpeg')
    ],
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer ${tokenToUse.value}"
    ],
]);
$response = curl_exec($curl);
curl_close($curl);
echo $response;`);

            const curlFileUrlSnippet = computed(() => `curl -X POST "${apiBaseUrl.value}/send/file" \\
  -H "Authorization: Bearer ${tokenToUse.value}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "to": "628123456789",
    "caption": "Receipt.pdf",
    "filename": "receipt.pdf",
    "url": "https://example.com/doc.pdf"
  }'`);

            const curlFileFileSnippet = computed(() => `curl -X POST "${apiBaseUrl.value}/send/file" \\
  -H "Authorization: Bearer ${tokenToUse.value}" \\
  -F "to=628123456789" \\
  -F "caption=Invoice PDF" \\
  -F "filename=invoice.pdf" \\
  -F "file=@/path/to/document.pdf"`);

            const phpFileUrlSnippet = computed(() => `<?php
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "${apiBaseUrl.value}/send/file",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => json_encode([
        "to" => "628123456789",
        "caption" => "Receipt.pdf",
        "filename" => "receipt.pdf",
        "url" => "https://example.com/doc.pdf"
    ]),
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer ${tokenToUse.value}",
        "Content-Type: application/json"
    ],
]);
$response = curl_exec($curl);
curl_close($curl);
echo $response;`);

            const phpFileFileSnippet = computed(() => `<?php
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "${apiBaseUrl.value}/send/file",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => [
        "to" => "628123456789",
        "caption" => "Invoice PDF",
        "filename" => "invoice.pdf",
        "file" => new CURLFile('/path/to/document.pdf', 'application/pdf')
    ],
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer ${tokenToUse.value}"
    ],
]);
$response = curl_exec($curl);
curl_close($curl);
echo $response;`);

            const curlStatusSnippet = computed(() => `curl -X GET "${apiBaseUrl.value}/sessions" \\
  -H "Authorization: Bearer ${tokenToUse.value}"`);

            const phpStatusSnippet = computed(() => `<?php
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "${apiBaseUrl.value}/sessions",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer ${tokenToUse.value}"
    ],
]);
$response = curl_exec($curl);
curl_close($curl);
echo $response;`);

            // Add dialog
            const addDialog    = ref(false);
            const newSessionId = ref('');
            const newLabel     = ref('');
            const addLoading   = ref(false);

            // Generate a random 32-char hex token (like MD5)
            const genSessionId = () => {
                const arr = new Uint8Array(16);
                crypto.getRandomValues(arr);
                return Array.from(arr).map(b => b.toString(16).padStart(2,'0')).join('');
            };
            const regenerateSessionId = () => { newSessionId.value = genSessionId(); };

            // Delete dialog
            const deleteDialog = ref(false);
            const deleteTarget = ref(null);

            // Logout
            const logoutLoading = ref(false);
            const doLogout = async () => {
                logoutLoading.value = true;
                try {
                    await fetch('auth.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'logout' })
                    });
                } catch (_) {}
                store.dispatch('stopAllPolling');
                window.location.href = 'login.html';
            };

            const openAddDialog = () => {
                newSessionId.value = genSessionId();
                newLabel.value = '';
                addDialog.value = true;
            };

            const submitAdd = async () => {
                addLoading.value = true;
                const ok = await store.dispatch('addSession', {
                    sessionId: newSessionId.value,
                    label: newLabel.value || newSessionId.value
                });
                addLoading.value = false;
                if (ok) addDialog.value = false;
            };

            const confirmDelete = (s) => { deleteTarget.value = s; deleteDialog.value = true; };
            const doDelete = () => {
                store.dispatch('deleteSession', deleteTarget.value.session_id);
                deleteDialog.value = false;
            };

            const reconnect = (s) => store.dispatch('reconnectSession', s);
            const reload    = () => store.dispatch('loadSessions');

            const copyToken = (token) => {
                navigator.clipboard.writeText(token).then(() => {
                    store.commit('SHOW_SNACK', { text: 'Token copied to clipboard!', color: 'info' });
                }).catch(() => {
                    store.commit('SHOW_SNACK', { text: 'Copy failed — select token manually.', color: 'error' });
                });
            };

            const copyToClipboard = (text) => {
                navigator.clipboard.writeText(text).then(() => {
                    store.commit('SHOW_SNACK', { text: 'Snippet copied to clipboard!', color: 'info' });
                }).catch(() => {
                    store.commit('SHOW_SNACK', { text: 'Copy failed.', color: 'error' });
                });
            };

            onMounted(() => store.dispatch('loadSessions'));
            onUnmounted(() => store.dispatch('stopAllPolling'));

            return {
                sessions, maxSessions, loading, snackbar,
                authUser, logoutLoading, doLogout,
                regenerateSessionId,
                activeTab, subTab, langText, langImage, langFile, langStatus, selectedToken, apiBaseUrl, tokenToUse,
                curlTextSnippet, phpTextSnippet,
                curlImageUrlSnippet, curlImageFileSnippet, phpImageUrlSnippet, phpImageFileSnippet,
                curlFileUrlSnippet, curlFileFileSnippet, phpFileUrlSnippet, phpFileFileSnippet,
                curlStatusSnippet, phpStatusSnippet, copyToClipboard,
                addDialog, newSessionId, newLabel, addLoading, openAddDialog, submitAdd,
                deleteDialog, deleteTarget, confirmDelete, doDelete,
                reconnect, reload, copyToken,
                statusColor, statusIcon
            };
        }
    });

    app.use(store);
    app.use(vuetify);
    app.mount('#app');
    </script>
</body>
</html>
