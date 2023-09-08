class MultiSafepayPaymentComponent {

    payment_component = null;

    constructor(config, gateway) {
        this.payment_component = null;
        this.config = config;
        this.gateway = gateway;
        this.form_id = 'multisafepay-form';
        this.input_payload = document.querySelector('#' + this.form_id + ' input[name="payload"]');
        this.input_tokenize = document.querySelector('#' + this.form_id + ' input[name="tokenize"]');
        this.initializePaymentComponent();
    }

    getPaymentComponent() {
        if (!this.paymentComponent) {
            this.paymentComponent = this.getNewPaymentComponent();
        }
        return this.paymentComponent;
    };

    getNewPaymentComponent() {
        return new MultiSafepay(
            {
                env: this.config.env,
                apiToken: this.config.apiToken,
                recurring: this.config.recurring,
                order: this.config.orderData
            }
        );
    };

    insertPayloadAndTokenize(payload, tokenize) {
        if (payload !== null) {
            this.input_payload.value = payload;
        }
        if (tokenize !== null) {
            this.input_tokenize.value = tokenize;
        }
    }

    removePayloadAndTokenize() {
        this.input_payload.value = '';
        this.input_tokenize.value = '';
    }

    initializePaymentComponent() {
        this.getPaymentComponent().init('payment', {
            container: '#multisafepay-payment',
            gateway: this.gateway,
            onLoad: state => {
                this.logger('onLoad');
            },
            onError: state => {
                this.logger('onError');
            }
        });
    };

    onSubmitCheckoutForm(event) {
        this.removePayloadAndTokenize();
        if (this.getPaymentComponent().hasErrors()) {
            this.logger(this.getPaymentComponent().getErrors());
            event.preventDefault();
            event.stopPropagation();
            return;
        }

        const paymentData = this.getPaymentComponent().getPaymentData();
        if ((paymentData === null) || (typeof paymentData !== 'object')) {
            return;
        }
        // 'In' operator is part of ECMAScript 5 (ES5), and widely supported since around 2010
        // IE: v9 (2011). Firefox: v4 (2011). Chrome: v23 (2012). Safari: v6 (2012). Opera: v15 (2013)
        const payload = 'payload' in paymentData ? paymentData.payload : null;
        if ((payload === null) || (typeof payload !== 'string')) {
            return;
        }
        // Tokenize is optional
        const tokenize = 'tokenize' in paymentData ? paymentData.tokenize : null;
        this.insertPayloadAndTokenize(payload, tokenize);
        const msp_form = document.getElementById(this.form_id);
        msp_form ? (msp_form.removeEventListener('submit', this.onSubmitCheckoutForm), msp_form.submit()) : null;
    };

    logger(argument) {
        if (this.config.debug) {
            console.log(argument);
        }
    };
}
