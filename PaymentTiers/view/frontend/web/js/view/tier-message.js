define([
    'jquery',
    'ko',
    'uiComponent',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/action/get-payment-information',
    'Magento_Customer/js/customer-data'
], function ($, ko, Component, quote, getPaymentInformationAction, customerData) {
    'use strict';

    /*
     * Short enough that a refresh which raced ahead of another tab's cart update can be
     * retried on the next focus, long enough that returning to the tab does not fire twice.
     */
    var REFRESH_INTERVAL_MS = 1500,
        lastRefresh = 0,
        refreshing = false;

    /**
     * The response sets totals as well as methods, so the guard keeps a refresh triggered by
     * a tier change from starting another one.
     */
    function refreshPaymentInformation() {
        var deferred;

        if (refreshing) {
            return;
        }

        refreshing = true;
        deferred = $.Deferred();
        deferred.always(function () {
            refreshing = false;
        });

        getPaymentInformationAction(deferred);
    }

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

            this.reconcileOnTierChange();
            this.reconcileOnReturn();

            return this;
        },

        /**
         * Refresh the payment methods whenever the tier itself changes.
         *
         * Magento refreshes the method list only when shipping information is submitted, so a
         * coupon, a shipping change or an edit made elsewhere can move the total across a
         * threshold and leave the list as it was — the message would say cards are not
         * accepted while a card was still on offer. Watching the tier rather than the events
         * that might have changed it covers every route to the same state.
         */
        reconcileOnTierChange: function () {
            var signature = ko.computed(function () {
                var tier = this.tier();

                return tier ?
                    (tier['card_available'] ? '1' : '0') + ':' + (tier['allowed_brands'] || []).join(',') :
                    '';
            }, this);

            var previous = signature();

            signature.subscribe(function (current) {
                if (current === previous) {
                    return;
                }

                previous = current;
                refreshPaymentInformation();
            });
        },

        /**
         * AC-3 names a cart edited in another tab. The quote is shared server side, so the
         * data is already right; the open checkout simply has no reason to ask again. Asking
         * when the tab is looked at again closes that without polling.
         *
         * Payment information rather than totals alone: refreshing totals updates the message
         * but leaves the method list as it was, which would go on offering a card the tier no
         * longer allows. This endpoint returns both.
         */
        reconcileOnReturn: function () {
            var refresh = function (force) {
                var now = Date.now();

                if (!force && (document.hidden || now - lastRefresh < REFRESH_INTERVAL_MS)) {
                    return;
                }

                lastRefresh = now;
                refreshPaymentInformation();
            };

            document.addEventListener('visibilitychange', refresh.bind(null, false));
            window.addEventListener('focus', refresh.bind(null, false));

            // Magento already syncs the cart section across tabs, so this fires on the edit
            // itself rather than on the customer happening to look back at this tab. Focus
            // stays as the fallback for anything that changes the quote without touching it.
            customerData.get('cart').subscribe(function () {
                refresh(true);
            });
        }
    });
});
