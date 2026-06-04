import template from './asbs-streamdeck-api-keys.html.twig';

const { Component } = Shopware;

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
    },
});
