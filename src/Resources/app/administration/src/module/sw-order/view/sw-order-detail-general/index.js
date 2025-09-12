const {Criteria} = Shopware.Data;
import template from './sw-order-detail-general.html.twig';

Shopware.Component.override('sw-order-detail-general', {
  template,
  inject: ['repositoryFactory'],
  mixins: ['notification'],

  data() {
    return {
      orderTransactionId: 'Not found',
      isLoading: true,
      isSendingEmail: false,
      transactionFound: false,
      paymentMethod: '',
      paymentStatus: '',
      showConfirmModal: false,
      disabledStatuses: [
        'paid',
        'refunded',
        'partially_refunded',
        'cancelled',
        'in_progress',
      ]
    };
  },

  created() {
    this.loadTransaction();
    this.loadPaymentData();
  },
  methods: {
    onConfirmSendPaymentLink() {
      this.showConfirmModal = true;
    },
    onCloseModal() {
      this.showConfirmModal = false;
    },
    confirmSendPaymentLink() {
      this.showConfirmModal = false;
      this.sendPaymentLinkEmail();
    },
    async sendPaymentLinkEmail() {
      try {
        this.isSendingEmail = true;
        const response = await Shopware.Service('repositoryFactory').httpClient.post(
          '/re-send-payment-link',
          {
            orderId: this.orderId,
          },
          {
            headers: {
              'Content-Type': 'application/json',
              Authorization: `Bearer ${Shopware.Context.api.authToken.access}`,
            }
          }
        )
        this.createNotificationSuccess({
          title: 'Email Sent',
          message: 'The payment link email was successfully sent to the customer.'
        });
      } catch (err) {
        this.createNotificationError({
          title: 'Error',
          message: 'Failed to send payment link email.'
        });
      }
      this.isSendingEmail = false;
    },

    loadPaymentData() {
      if (!this.order || !this.order.transactions) {
        return;
      }
      const transaction = this.order.transactions.last();
      if (transaction && transaction.paymentMethod) {
        this.paymentMethod = transaction.paymentMethod.handlerIdentifier;
        this.paymentStatus = transaction.stateMachineState.technicalName;
      }
    },
    loadTransaction() {

      const orderId = this.$route.params.id || this.order?.id;

      const repository = this.repositoryFactory.create('solu1_bluesnap_transaction');
      const criteria = new Criteria();
      criteria.addFilter(Criteria.equals('orderId', orderId));

      repository.search(criteria, Shopware.Context.api)
        .then((response) => {
          if (response.total > 0) {
            const transaction = response.first();
            this.orderTransactionId = transaction.transactionId;
            this.transactionFound = true;
          }
        })
        .catch(() => {
          this.orderTransactionId = 'Error';
        })
        .finally(() => {
          this.isLoading = false;
        });
    }
  }
});
