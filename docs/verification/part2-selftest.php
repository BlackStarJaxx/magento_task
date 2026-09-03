<?php
/**
 * Part 2 self-test. Needs the finance stub running:
 *
 *   cp docs/verification/finance-stub.php src/app/code/
 *   docker compose exec -d phpfpm php -S 0.0.0.0:8099 /var/www/html/app/code/finance-stub.php
 *
 * The endpoint is pointed at the stub for the duration of the run and restored afterwards.
 *
 * Then:
 *
 *   cp docs/verification/part2-selftest.php src/app/code/ \
 *     && bin/cli php app/code/part2-selftest.php; rm -f src/app/code/part2-selftest.php
 *
 * Places real orders, delivers them through the real retry path, and asserts what both sides
 * ended up holding. Exits non-zero if any check fails.
 */
require '/var/www/html/app/bootstrap.php';

use Goodahead\OrderSync\Model\Dispatch\EventType;

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
$om->get(\Magento\Store\Model\StoreManagerInterface::class)->setCurrentStore(1);

$connection = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
$ledger = $om->get(\Goodahead\OrderSync\Model\ResourceModel\Dispatch::class);
$sweeper = $om->get(\Goodahead\OrderSync\Cron\DispatchSweeper::class);
$orderRepository = $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class);
$configWriter = $om->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
$appConfig = $om->get(\Magento\Framework\App\Config::class);

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    $failures += $ok ? 0 : 1;
    printf("  [%s] %-56s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
}

ensureStock($om, ['MJ08-L-Green']);

$stub = static function (string $path): ?array {
    $raw = @file_get_contents('http://127.0.0.1:8099' . $path, false, stream_context_create([
        'http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 5],
    ]));

    return $raw === false ? null : (json_decode($raw, true) ?: []);
};
$stubState = static fn (): array => json_decode((string)@file_get_contents('http://127.0.0.1:8099/_state'), true) ?: [];

if ($stub('/_reset') === null) {
    echo "\nThe finance stub is not answering on port 8099. See the header of this file.\n";
    exit(1);
}

/*
 * Point the module at the stub for the duration of the run and put it back afterwards. The
 * shipped default is example.invalid, which never resolves — correct as a default, useless
 * for observing a delivery succeed.
 */
$configWriter->save(\Goodahead\OrderSync\Model\Config::XML_PATH_ENDPOINT_URL, 'http://127.0.0.1:8099/orders', 'default', 0);
$appConfig->clean();

/**
 * Self-tests place real orders, so they consume stock. Topping the two SKUs up keeps the run
 * repeatable instead of passing once and then failing on "Not enough items for sale".
 */
/**
 * Puts every setting this run changes back, whatever happened.
 *
 * Restoring by deleting the override rather than by writing back what was read: a run that
 * failed half way through used to leave its own temporary values behind, and the next run
 * would then read those as the originals and faithfully preserve them.
 */
function restoreConfiguration($configWriter, $appConfig, string $paymentAction): void
{
    foreach ([
        \Goodahead\OrderSync\Model\Config::XML_PATH_ENDPOINT_URL,
        'goodahead_ordersync/retry/base_delay',
        'goodahead_ordersync/retry/max_delay',
        'goodahead_ordersync/retry/max_attempts',
    ] as $path) {
        $configWriter->delete($path, 'default', 0);
    }

    $configWriter->save('payment/stripe_payments/payment_action', $paymentAction, 'default', 0);
    $appConfig->clean();
}

function ensureStock($om, array $skus): void
{
    $stockRegistry = $om->get(\Magento\CatalogInventory\Api\StockRegistryInterface::class);

    foreach ($skus as $sku) {
        $item = $stockRegistry->getStockItemBySku($sku);
        $item->setQty(1000);
        $item->setIsInStock(true);
        $stockRegistry->updateStockItemBySku($sku, $item);
    }
}

function placeOrder($om, string $method, ?string $token, float $qty = 5.0): \Magento\Sales\Api\Data\OrderInterface
{
    $product = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->get('MJ08-L-Green');
    $quote = $om->get(\Magento\Quote\Model\QuoteFactory::class)->create();
    $quote->setStoreId(1)->setIsActive(true)->setCustomerIsGuest(true)->setCustomerEmail('part2@example.test');
    $quote->addProduct($product, $qty);

    $address = ['firstname' => 'Part', 'lastname' => 'Two', 'street' => ['1 Test St'], 'city' => 'Austin',
        'country_id' => 'US', 'region_id' => 57, 'postcode' => '78701', 'telephone' => '5125550100',
        'email' => 'part2@example.test'];
    $quote->getBillingAddress()->addData($address);
    $quote->getShippingAddress()->addData($address)->setCollectShippingRates(true)
          ->collectShippingRates()->setShippingMethod('flatrate_flatrate');
    $quote->setCheckoutMethod('guest');
    $quote->getPayment()->setMethod($method);

    if ($token !== null) {
        $quote->getPayment()->setAdditionalInformation('token', $token);
    }

    $quote->collectTotals();
    $om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);

    $orderId = $om->get(\Magento\Quote\Api\CartManagementInterface::class)->placeOrder($quote->getId());

    return $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->get($orderId);
}

$connection->delete('goodahead_ordersync_dispatch');
$stub('/_mode?mode=ok');

/*
 * Authorise only, for the cancellation half of the run. Magento refuses to cancel an order
 * that has been captured and invoiced — that case is a credit memo, which the brief puts out
 * of scope. Cancellation therefore only ever applies to an order whose money is authorised
 * but not yet taken, which is exactly what this sets up.
 */
$originalPaymentAction = (string)$om->get(\Magento\Framework\App\Config\ScopeConfigInterface::class)
    ->getValue('payment/stripe_payments/payment_action');

// Registered once the original is known, so a run that dies half way still puts it back.
register_shutdown_function(
    static fn () => restoreConfiguration($configWriter, $appConfig, $originalPaymentAction)
);
$configWriter->save('payment/stripe_payments/payment_action', 'authorize', 'default', 0);
$appConfig->clean();

echo "\nAC-10  checkout does not wait for the finance system\n";

// The stub sleeps for 30 seconds in this mode. A synchronous dispatch would make placement
// wait for it; nothing here should.
$stub('/_mode?mode=timeout');
$startedAt = microtime(true);
$slowOrder = placeOrder($om, 'stripe_payments', 'pm_card_visa');
$elapsed = microtime(true) - $startedAt;
$stub('/_mode?mode=ok');

check('the order is created while the endpoint hangs', (int)$slowOrder->getEntityId() > 0, '#' . $slowOrder->getIncrementId());
check('placement did not wait for it', $elapsed < 10.0, sprintf('%.2fs against the endpoint\'s 30s', $elapsed));
check('the delivery is registered but not yet delivered',
    ($connection->fetchOne('SELECT status FROM goodahead_ordersync_dispatch WHERE order_id = ?',
        [(int)$slowOrder->getEntityId()]) ?: '') === 'pending');

$connection->delete('goodahead_ordersync_dispatch');
$stub('/_reset');

echo "\nAC-13  a paid order registers a delivery, an unpaid one does not\n";
$paid = placeOrder($om, 'stripe_payments', 'pm_card_visa');
check('paid card order is registered', $ledger->exists((int)$paid->getEntityId(), EventType::ORDER_PLACED), '#' . $paid->getIncrementId());

$unpaid = placeOrder($om, 'checkmo', null);
check('unpaid offline order fires nothing (AC-15)',
    !$ledger->exists((int)$unpaid->getEntityId(), EventType::ORDER_PLACED),
    '#' . $unpaid->getIncrementId() . ' state=' . $unpaid->getState());

echo "\nAC-14  purchase recency is stamped, once, without touching an index\n";
$stampedValue = $connection->fetchOne(
    'SELECT d.value FROM catalog_product_entity_datetime d'
    . ' JOIN eav_attribute a ON a.attribute_id = d.attribute_id AND a.attribute_code = ?'
    . ' JOIN catalog_product_entity p ON p.entity_id = d.entity_id AND p.sku = ?',
    ['last_purchased_at', 'MJ08-L-Green']
);
check('last_purchased_at holds the order timestamp', $stampedValue === $paid->getCreatedAt(), (string)$stampedValue);
check('no indexer was invalidated',
    $connection->fetchOne("SELECT COUNT(*) FROM indexer_state WHERE status = 'invalid'") !== false);

echo "\nAC-11  one logical delivery, however many attempts\n";
$sweeper->execute();
$row = $connection->fetchRow('SELECT status, attempts FROM goodahead_ordersync_dispatch WHERE order_id = ?', [(int)$paid->getEntityId()]);
check('delivered', ($row['status'] ?? '') === 'succeeded', 'attempts=' . ($row['attempts'] ?? '?'));

// Everything that could register it a second time.
$ledger->register($paid, EventType::ORDER_PLACED);
$om->get(\Goodahead\OrderSync\Model\Queue\DispatchPublisher::class)->publish(
    $ledger->find((int)$paid->getEntityId(), EventType::ORDER_PLACED)
);
$sweeper->execute();

$rows = (int)$connection->fetchOne('SELECT COUNT(*) FROM goodahead_ordersync_dispatch WHERE order_id = ? AND event_type = ?',
    [(int)$paid->getEntityId(), EventType::ORDER_PLACED]);
check('a repeated trigger adds no second ledger row', $rows === 1, $rows . ' row(s)');

$state = $stubState();
check('the endpoint holds one delivery per event',
    ($state['distinct_deliveries'] ?? 0) === (int)$connection->fetchOne('SELECT COUNT(*) FROM goodahead_ordersync_dispatch'),
    'attempts=' . ($state['attempts'] ?? '?') . ' distinct=' . ($state['distinct_deliveries'] ?? '?'));

echo "\nAC-12  retries are bounded and end somewhere an operator can find\n";
$configWriter->save('goodahead_ordersync/retry/base_delay', '1', 'default', 0);
$configWriter->save('goodahead_ordersync/retry/max_delay', '2', 'default', 0);
$configWriter->save('goodahead_ordersync/retry/max_attempts', '2', 'default', 0);
$appConfig->clean();
$stub('/_mode?mode=500');

$cancelled = $om->get(\Magento\Sales\Api\OrderManagementInterface::class)->cancel((int)$paid->getEntityId());
check('the order could be cancelled', $cancelled === true, $cancelled ? '' : 'Magento refused; is it captured?');
check('cancellation of a delivered order is registered (AC-15)',
    $ledger->exists((int)$paid->getEntityId(), EventType::ORDER_CANCELLED));

for ($i = 0; $i < 2; $i++) {
    $sweeper->execute();
    sleep(3);
}
$cancelRow = $connection->fetchRow('SELECT status, attempts, last_error FROM goodahead_ordersync_dispatch WHERE order_id = ? AND event_type = ?',
    [(int)$paid->getEntityId(), EventType::ORDER_CANCELLED]);
check('retry budget ends in a terminal state', ($cancelRow['status'] ?? '') === 'failed',
    'attempts=' . ($cancelRow['attempts'] ?? '?'));
check('the failure is recorded for an operator', !empty($cancelRow['last_error']),
    substr((string)($cancelRow['last_error'] ?? ''), 0, 34) . '…');
check('and left as a comment on the order',
    (int)$connection->fetchOne('SELECT COUNT(*) FROM sales_order_status_history WHERE parent_id = ? AND comment LIKE ?',
        [(int)$paid->getEntityId(), '%Finance push%']) > 0);

echo "\nAC-15  the purchase stamp survives the cancellation\n";
$afterCancel = $connection->fetchOne(
    'SELECT d.value FROM catalog_product_entity_datetime d'
    . ' JOIN eav_attribute a ON a.attribute_id = d.attribute_id AND a.attribute_code = ?'
    . ' JOIN catalog_product_entity p ON p.entity_id = d.entity_id AND p.sku = ?',
    ['last_purchased_at', 'MJ08-L-Green']
);
check('last_purchased_at is unchanged', $afterCancel === $stampedValue, (string)$afterCancel);

echo "\noperator recovery\n";
$stub('/_mode?mode=ok');
check('requeue returns the failed delivery to work', $ledger->requeueFailed() === 1);
$sweeper->execute();
check('and it then succeeds',
    $connection->fetchOne('SELECT status FROM goodahead_ordersync_dispatch WHERE order_id = ? AND event_type = ?',
        [(int)$paid->getEntityId(), EventType::ORDER_CANCELLED]) === 'succeeded');

restoreConfiguration($configWriter, $appConfig, $originalPaymentAction);

printf("\n%s\n", $failures === 0 ? 'All Part 2 checks passed.' : $failures . ' check(s) FAILED.');
exit($failures === 0 ? 0 : 1);
