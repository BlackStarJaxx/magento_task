define([
    'ko',
    'uiComponent',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/action/get-totals'
], function (ko, Component, quote, getTotalsAction) {
    'use strict';

    /* Do not refetch totals more than once every few seconds when a tab regains focus. */
    var REFRESH_INTERVAL_MS = 5000,
        lastRefresh = 0;

    return Component.extend({
        defaults: {
            template: 'Goodahead_PaymentTiers/tier-message'
        },

        /** @returns {exports} */
        initialize: function () {
            this._super();

            this.tier = ko.computed(function () {
                var totals = quote.totals();

                return (totals &&
                    totals['extension_attributes'] &&
                    totals['extension_attributes']['goodahead_payment_tier']) || null;
            });

            this.message = ko.computed(function () {
                var tier = this.tier();

                return tier && tier['message'] ? tier['message'] : '';
            }, this);

            this.isVisible = ko.computed(function () {
                return this.message() !== '';
            }, this);

            this.isCardAvailable = ko.computed(function () {
                var tier = this.tier();

                return !tier || tier['card_available'] !== false;
            }, this);

            this.reconcileOnReturn();

            return this;
        },

        /**
         * AC-3 names a cart edited in another tab. The quote is shared server side, so the
         * data is already right; the open checkout simply has no reason to ask again. Asking
         * when the tab is looked at again closes that without polling.
         */
        reconcileOnReturn: function () {
            var refresh = function () {
                var now = Date.now();

                if (document.hidden || now - lastRefresh < REFRESH_INTERVAL_MS) {
                    return;
                }

                lastRefresh = now;
                getTotalsAction([]);
            };

            document.addEventListener('visibilitychange', refresh);
            window.addEventListener('focus', refresh);
        }
    });
});
