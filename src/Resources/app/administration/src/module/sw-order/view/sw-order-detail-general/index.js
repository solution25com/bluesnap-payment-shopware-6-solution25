const { Component } = Shopware;
const { Criteria } = Shopware.Data;
import template from './sw-order-detail-general.html.twig';

Shopware.Component.override('sw-order-detail-general', {
    template,
    inject: ['repositoryFactory'],


    data() {
        return {
            orderTransactionId: null,
            isLoading: true,
            transactionFound: true,
        };
    },

    created() {
        this.loadTransaction();
    },

    methods: {
        loadTransaction() {
            const orderId = this.$route.params.id || this.order?.id;

            const repository = this.repositoryFactory.create('bluesnap_transaction');
            const criteria = new Criteria();
            criteria.addFilter(Criteria.equals('orderId', orderId));

            this.isLoading = true;
            this.transactionFound = true;

            repository.search(criteria, Shopware.Context.api)
                .then((response) => {
                    if (response.total > 0) {
                        console.log('response here:', response);
                        const transaction = response.first();
                        this.orderTransactionId = transaction.transactionId;
                    } else {
                        this.orderTransactionId = 'Not found';
                        this.transactionFound = false;
                    }
                })
                .catch(() => {
                    this.orderTransactionId = 'Error';
                    this.transactionFound = false;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        }
    }
});
