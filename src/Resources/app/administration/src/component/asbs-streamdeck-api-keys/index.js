import template from './asbs-streamdeck-api-keys.html.twig';

const { Component } = Shopware;

const CHECK_LABELS = {
    keyStore: 'API-Key-Speicher erreichbar',
    apiKey: 'Eingegebener API-Key gültig',
    metrics: 'Kennzahlen-Abfrage erfolgreich',
    live: 'Live-Abruf über die Companion-API',
    request: 'Anfrage an den Shop',
};

/**
 * Embedded in the plugin configuration via <component name="asbs-streamdeck-api-keys">.
 * Lets shop operators generate, view and revoke Stream-Deck API keys without the
 * CLI. The raw secret is shown only once (right after creation); the list endpoint
 * never returns it.
 */
Component.register('asbs-streamdeck-api-keys', {
    template,

    inject: ['loginService'],

    data() {
        return {
            keys: [],
            label: '',
            newSecret: null,
            isLoading: false,
            testKey: '',
            testResult: null,
            isTesting: false,
        };
    },

    computed: {
        // httpClient is not a registered service provider in Shopware admin (only
        // loginService etc. are injectable). It lives in the bootstrap "init"
        // container, which is set up before plugins load — so this is both the
        // idiomatic and the resilient way to reach it from a plugin component.
        httpClient() {
            return Shopware.Application.getContainer('init').httpClient;
        },

        apiHeaders() {
            return { Authorization: `Bearer ${this.loginService.getToken()}` };
        },

        keyColumns() {
            return [
                { property: 'label', label: 'Label' },
                { property: 'createdAt', label: 'Erstellt' },
                { property: 'lastUsedAt', label: 'Zuletzt verwendet' },
            ];
        },

        // sw-alert forwards its attrs to mt-banner in 6.7, so the variant has to
        // be one of mt-banner's names — "success"/"error" would silently fall
        // back to neutral and a failed test would look like a passing one.
        testVariant() {
            if (!this.testResult) {
                return 'info';
            }
            if (!this.testResult.success) {
                return 'critical';
            }

            return this.testResult.checks.some((c) => c.status === 'warning') ? 'attention' : 'positive';
        },

        testTitle() {
            if (!this.testResult) {
                return '';
            }
            if (!this.testResult.success) {
                return 'Verbindungstest fehlgeschlagen';
            }

            return `Verbindungstest erfolgreich — Companion ${this.testResult.version}`;
        },
    },

    created() {
        this.loadKeys();
    },

    methods: {
        loadKeys() {
            this.isLoading = true;
            return this.httpClient
                .get('_action/asbs-streamdeck/keys', { headers: this.apiHeaders })
                .then((res) => { this.keys = res.data; })
                .catch(() => { this.keys = []; })
                .finally(() => { this.isLoading = false; });
        },

        generateKey() {
            this.isLoading = true;
            return this.httpClient
                .post('_action/asbs-streamdeck/keys', { label: this.label }, { headers: this.apiHeaders })
                .then((res) => {
                    this.newSecret = res.data.secret;
                    // Prefill the test field so the key can be verified in one
                    // click while it is still on screen — it is never shown again.
                    this.testKey = res.data.secret;
                    this.label = '';
                    return this.loadKeys();
                })
                .finally(() => { this.isLoading = false; });
        },

        deleteKey(id) {
            this.isLoading = true;
            return this.httpClient
                .delete(`_action/asbs-streamdeck/keys/${id}`, { headers: this.apiHeaders })
                .then(() => this.loadKeys())
                .finally(() => { this.isLoading = false; });
        },

        dismissSecret() {
            this.newSecret = null;
        },

        /**
         * Server-side self-check, plus — when the entered key is valid — a real
         * round-trip over the public companion endpoint, exactly as the Stream
         * Deck plugin calls it. That second hop is what catches a reverse proxy
         * or WAF stripping the X-Asbs-Streamdeck-Key header.
         */
        runTest() {
            this.isTesting = true;
            this.testResult = null;
            const key = (this.testKey || '').trim();
            const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';

            return this.httpClient
                .post('_action/asbs-streamdeck/keys/test', { key, tz }, { headers: this.apiHeaders })
                .then((res) => (res.data.liveKey ? this.appendLiveCheck(res.data, key) : res.data))
                .then((result) => { this.testResult = result; })
                .catch((err) => {
                    this.testResult = {
                        success: false,
                        checks: [{
                            id: 'request',
                            status: 'error',
                            detail: { message: err?.message || 'unbekannter Fehler' },
                        }],
                    };
                })
                .finally(() => { this.isTesting = false; });
        },

        appendLiveCheck(result, key) {
            // Deliberately window.fetch and not the admin httpClient: the latter
            // has interceptors around 401 that would read a rejected key as an
            // expired admin session.
            const base = Shopware?.Context?.api?.apiPath || `${window.location.origin}/api`;

            return window
                .fetch(`${base}/_action/asbs-streamdeck/ping`, {
                    headers: { 'X-Asbs-Streamdeck-Key': key },
                })
                .then((res) => (res.ok
                    ? res.json().then((body) => ({ status: 'ok', detail: { version: body.version } }))
                    : { status: 'error', detail: { httpStatus: res.status } }))
                .catch((err) => ({ status: 'error', detail: { message: err?.message || 'nicht erreichbar' } }))
                .then((live) => ({
                    ...result,
                    success: result.success && live.status === 'ok',
                    checks: [...result.checks, { id: 'live', ...live }],
                }));
        },

        checkLabel(check) {
            return CHECK_LABELS[check.id] || check.id;
        },

        checkDetail(check) {
            const d = check.detail || {};
            if (check.status === 'skipped') {
                return 'übersprungen — kein Key eingegeben';
            }
            if (d.message) {
                return d.message;
            }
            if (check.id === 'keyStore') {
                return d.count > 0
                    ? `${d.count} Key(s) hinterlegt`
                    : 'noch kein API-Key angelegt';
            }
            if (check.id === 'apiKey') {
                return check.status === 'ok' ? 'Key erkannt' : 'Key unbekannt oder widerrufen';
            }
            if (check.id === 'metrics') {
                return `heutige Bestellungen: ${d.orders}`;
            }
            if (check.id === 'live') {
                return check.status === 'ok'
                    ? `Antwort der Companion-API: Version ${d.version}`
                    : `HTTP ${d.httpStatus || '?'} — Endpoint nicht erreichbar`;
            }

            return '';
        },

        checkIcon(status) {
            if (status === 'ok') {
                return '✓';
            }
            if (status === 'skipped') {
                return '–';
            }
            if (status === 'warning') {
                return '!';
            }

            return '✕';
        },
    },
});
