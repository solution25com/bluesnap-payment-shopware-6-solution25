import template from './bluesnap-api-test.html.twig';

const {Component, Mixin} = Shopware;

Component.register('bluesnap-api-test', {
    template,

    props: ['label'],
    inject: ['bluesnapApiTestService'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
        };
    },

    computed: {
        pluginConfig() {
            let $parent = this.$parent;

            while ($parent.actualConfigData === undefined) {
                $parent = $parent.$parent;
            }

            return $parent.actualConfigData.null;
        },
    },

    methods: {
        saveFinish() {
            this.isSaveSuccessful = false;
        },

        getCurrentSalesChannelId() {
            let $parent = this.$parent;

            while ($parent.currentSalesChannelId === undefined) {
                $parent = $parent.$parent;
            }

            return $parent.currentSalesChannelId;
        },

        check() {
            this.isLoading = true;

            const payload = {
                ...this.pluginConfig,
                salesChannelId: this.getCurrentSalesChannelId()
            };

            this.bluesnapApiTestService
                .check(payload)

                .then((response) => {
                    if (response.success) {
                        this.isSaveSuccessful = true;
                        this.createNotificationSuccess({
                            title: this.$tc('Bluesnap.apiTest.success.title'),
                            message: this.$tc('Bluesnap.apiTest.success.message'),
                        });
                    } else {
                        this.createNotificationError({
                            title: this.$tc('Bluesnap.apiTest.error.title'),
                            message: this.$tc('Bluesnap.apiTest.error.message'),
                        });
                    }
                })
                .catch((error) => {
                    this.createNotificationError({
                        title: 'BlueSnap API Test',
                        message: error.response?.data?.errors?.[0]?.detail || error.message || 'Connection failed!',
                    });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
    },
});
