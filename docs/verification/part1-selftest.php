<?php
/**
 * Part 1 self-test. Run from the repository root:
 *
 *   cp docs/verification/part1-selftest.php src/app/code/ \
 *     && bin/cli php app/code/part1-selftest.php; rm -f src/app/code/part1-selftest.php
 *
 * Places real orders in the Stripe sandbox using Stripe's shared test payment methods.
 * Exits non-zero if any check fails.
 */
require '/var/www/html/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
$om->get(\Magento\Store\Model\StoreManagerInterface::class)->setCurrentStore(1);

$productRepo = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
$quoteFactory = $om->get(\Magento\Quote\Model\QuoteFactory::class);
$quoteRepo = $om->get(\Magento\Quote\Api\CartRepositoryInterface::class);
$cartManagement = $om->get(\Magento\Quote\Api\CartManagementInterface::class);
$methodList = $om->get(\Magento\Payment\Model\MethodList::class);
$totalsRepo = $om->get(\Magento\Quote\Api\CartTotalRepositoryInterface::class);
$resolver = $om->get(\Goodahead\PaymentTiers\Model\TierResolver::class);
$minorUnits = $om->get(\Goodahead\PaymentTiers\Model\MinorUnits::class);
$connection = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    $failures += $ok ? 0 : 1;
    printf("  [%s] %-58s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
}

function buildQuote($om, $productRepo, $quoteFactory, $quoteRepo, float $qty, ?string $token = null)
{
    $quote = $quoteFactory->create();
    $quote->setStoreId(1)->setIsActive(true)->setCustomerIsGuest(true)
          ->setCustomerEmail('selftest@example.test');
    $quote->addProduct($productRepo->get('MJ08-L-Green'), $qty);

    $address = [
        'firstname' => 'Self', 'lastname' => 'Test', 'street' => ['1 Test St'],
        'city' => 'Austin', 'country_id' => 'US', 'region_id' => 57, 'postcode' => '78701',
        'telephone' => '5125550100', 'email' => 'selftest@example.test',
    ];
    $quote->getBillingAddress()->addData($address);
    $quote->getShippingAddress()->addData($address)->setCollectShippingRates(true)
          ->collectShippingRates()->setShippingMethod('flatrate_flatrate');

    if ($token !== null) {
        $quote->setCheckoutMethod('guest');
        $quote->getPayment()->setMethod('stripe_payments');
        $quote->getPayment()->setAdditionalInformation('token', $token);
    }

    $quote->collectTotals();
    $quoteRepo->save($quote);

    return $quote;
}

echo "\nAC-9  boundaries are exact\n";
foreach ([
    ['10000.00', ['visa', 'mastercard', 'amex', 'discover', 'diners', 'jcb', 'unionpay', 'cartes_bancaires']],
    ['10000.01', ['amex']],
    ['20000.00', ['amex']],
    ['20000.01', []],
] as [$amount, $expected]) {
    $actual = $resolver->resolve($minorUnits->fromAmount($amount))->getAllowedBrands();
    check('$' . $amount, $actual === $expected, implode(',', $actual) ?: 'no cards');
}

echo "\nAC-8  offline methods survive every tier, cards follow the tier\n";
$tiers = [];
foreach ([50 => 'all cards', 210 => 'amex only', 260 => 'no cards'] as $qty => $label) {
    $quote = buildQuote($om, $productRepo, $quoteFactory, $quoteRepo, (float)$qty);
    $codes = array_map(static fn ($m) => $m->getCode(), $methodList->getAvailableMethods($quote));
    $tiers[$label] = $codes;
    $total = '$' . number_format((float)$quote->getGrandTotal(), 2);
    check("$label ($total) keeps checkmo + banktransfer",
        in_array('checkmo', $codes, true) && in_array('banktransfer', $codes, true),
        implode(', ', $codes));
}
check('card method hidden only in the no-cards tier',
    in_array('stripe_payments', $tiers['all cards'], true)
    && in_array('stripe_payments', $tiers['amex only'], true)
    && !in_array('stripe_payments', $tiers['no cards'], true));

echo "\nAC-2  a restricted tier states why, an unrestricted one says nothing\n";
foreach ([50 => false, 210 => true, 260 => true] as $qty => $expectMessage) {
    $quote = buildQuote($om, $productRepo, $quoteFactory, $quoteRepo, (float)$qty);
    $tier = $totalsRepo->get($quote->getId())->getExtensionAttributes()->getGoodaheadPaymentTier();
    $message = $tier->getMessage();
    check(($expectMessage ? 'message present' : 'no message') . ' at $'
        . number_format((float)$quote->getGrandTotal(), 2),
        ($message !== '') === $expectMessage,
        $message !== '' ? '"' . substr($message, 0, 46) . '…"' : '');
}

echo "\nAC-1 / AC-4  enforcement against a client that never renders the checkout\n";
$orders = static fn () => (int)$connection->fetchOne('SELECT COUNT(*) FROM sales_order');
foreach ([
    [50, 'pm_card_visa', true, 'all cards: visa accepted'],
    [210, 'pm_card_visa', false, 'amex only: visa refused'],
    [210, 'pm_card_amex', true, 'amex only: amex accepted'],
    [260, 'pm_card_amex', false, 'no cards: amex refused'],
] as [$qty, $token, $shouldPlace, $label]) {
    $before = $orders();
    $placed = false;
    $detail = '';

    try {
        $quote = buildQuote($om, $productRepo, $quoteFactory, $quoteRepo, (float)$qty, $token);
        $orderId = $cartManagement->placeOrder($quote->getId());
        $placed = true;
        $detail = '#' . $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->get($orderId)->getIncrementId();
    } catch (\Throwable $e) {
        $detail = substr($e->getMessage(), 0, 44) . '…';
    }

    $rowsChanged = $orders() - $before;
    check($label, $placed === $shouldPlace && $rowsChanged === ($shouldPlace ? 1 : 0), $detail);
}

echo "\nThe decision is recorded on the order, not left to the Stripe API\n";
$recorded = $connection->fetchRow(
    'SELECT p.cc_type, p.cc_last_4, p.additional_information'
    . ' FROM sales_order o JOIN sales_order_payment p ON p.parent_id = o.entity_id'
    . ' ORDER BY o.entity_id DESC LIMIT 1'
);
$info = json_decode((string)($recorded['additional_information'] ?? '{}'), true) ?: [];
check('card brand and last four stored on the order',
    ($recorded['cc_type'] ?? null) === 'AE' && !empty($recorded['cc_last_4']),
    ($recorded['cc_type'] ?? '-') . ' ••••' . ($recorded['cc_last_4'] ?? '-'));
check('tier and accepted brand stored on the order',
    ($info['goodahead_accepted_brand'] ?? null) === 'amex'
    && ($info['goodahead_tier_allowed_brands'] ?? null) === 'amex',
    'bound ' . ($info['goodahead_tier_upper_bound'] ?? '-'));

echo "\nNo authorisation is taken when a payment is refused\n";
$client = $om->get(\StripeIntegration\Payments\Model\Config::class)->getStripeClient();
$unconfirmed = 0;
foreach ($client->paymentIntents->all(['limit' => 4])->data as $intent) {
    if ($intent->status === 'requires_payment_method' && (int)$intent->amount_received === 0) {
        $unconfirmed++;
    }
}
check('refused attempts left intents unconfirmed, nothing captured', $unconfirmed >= 2,
    $unconfirmed . ' of the last 4 intents');

printf("\n%s\n", $failures === 0 ? 'All Part 1 checks passed.' : $failures . ' check(s) FAILED.');
exit($failures === 0 ? 0 : 1);
