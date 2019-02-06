<?php

namespace Riskified\Decider\Command;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\State;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Riskified\Decider\Api\Order\Helper;
use Riskified\Decider\Validator\CompositeValidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Riskified\Common\Riskified;
use Riskified\Common\Validations;
use Riskified\Common\Signature;
use Riskified\OrderWebhook\Model;
use Riskified\OrderWebhook\Transport\CurlTransport;

class UploadHistoricalOrders extends Command
{
    const BATCH_SIZE = 10;
    const RISKIFIED_AUTH_KEY_CONFIG_PATH = 'riskified/riskified/key';
    const RISKIFIED_ENV_CONFIG_PATH = 'riskified/riskified/env';
    const RISKIFIED_DOMAIN_CONFIG_PATH = 'riskified/riskified/domain';
    const RISKIFIED_ENABLED_CONFIG_PATH = 'riskified/riskified_general/enabled';

    /**
     * @var int
     */
    private $totalUploaded = 0;

    /**
     * @var int
     */
    private $currentPage = 1;

    /**
     * @var CurlTransport
     */
    private $transport;

    /**
     * @var State
     */
    private $state;

    /**
     * @var Helper
     */
    private $orderHelper;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var OrderInterface[]
     */
    private $orders;

    /**
     * @var SearchCriteria
     */
    private $searchCriteria;

    /**
     * @var CompositeValidator
     */
    private $compositeValidator;

    /**
     * @param State $state
     * @param ScopeConfigInterface $scopeConfig
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param CompositeValidator $compositeValidator
     * @param string|null $name
     */
    public function __construct(
        State $state,
        ScopeConfigInterface $scopeConfig,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        CompositeValidator $compositeValidator,
        $name = null
    ) {
        $this->state = $state;
        $this->scopeConfig = $scopeConfig;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->transport = new CurlTransport(new Signature\HttpDataSignature());
        $this->transport->timeout = 15;
        $this->compositeValidator = $compositeValidator;

        parent::__construct($name);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('riskified:sync:historical-orders');
        $this->setDescription('Send your historical orders to riskified backed with specify date range');
        $this->addArgument('from', InputArgument::OPTIONAL, 'Start Date in format "Y-m-d"');
        $this->addArgument('to', InputArgument::OPTIONAL, 'End Date in format "Y-m-d"');

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->state->setAreaCode(Area::AREA_ADMINHTML);
        $objectManager = ObjectManager::getInstance();
        $this->orderHelper = $objectManager->get(Helper::class);

        $authToken = $this->scopeConfig->getValue(static::RISKIFIED_AUTH_KEY_CONFIG_PATH, ScopeInterface::SCOPE_STORE);
        $env = constant('\Riskified\Common\Env::' . $this->scopeConfig->getValue(static::RISKIFIED_ENV_CONFIG_PATH));
        $domain = $this->scopeConfig->getValue(static::RISKIFIED_DOMAIN_CONFIG_PATH);

        $output->writeln("Riskified auth token: $authToken \n");
        $output->writeln("Riskified shop domain: $domain \n");
        $output->writeln("Riskified target environment: $env \n");
        $output->writeln("*********** \n");

        Riskified::init($domain, $authToken, $env, Validations::SKIP);

        $from = $input->getArgument('from');
        $to = $input->getArgument('to');

        if (isset($from) || isset($to)) {
            $this->compositeValidator->validate([
                'from' => $from,
                'to' => $to
            ]);

            $this->setSearchCriteria($from, $to);
        } else {
            $this->searchCriteria = $this->searchCriteriaBuilder->create();
        }


        $fullOrderRepository = $this->getEntireCollection();
        $total_count = $fullOrderRepository->getSize();

        $output->writeln("Starting to upload orders, total_count: $total_count \n");
        $this->getCollection();
        while ($this->totalUploaded < $total_count) {
            try {
                $this->postOrders();
                $this->totalUploaded += count($this->orders);
                $this->currentPage++;
                $output->writeln("Uploaded " .
                    $this->totalUploaded .
                    " of " .
                    $total_count
                    ." orders\n");

                $this->getCollection();
            } catch (\Exception $e) {
                $output->writeln("<error>".$e->getMessage()."</error> \n");
                exit(1);
            }
        }
    }

    /**
     * Retrieve prepared order collection for counting values
     *
     * @return OrderSearchResultInterface
     */
    private function getEntireCollection(): OrderSearchResultInterface
    {
        $orderResult = $this
            ->orderRepository
            ->getList($this->searchCriteria);

        return $orderResult;
    }

    /**
     * Retrieve paginated collection
     */
    private function getCollection()
    {
        $searchCriteria = $this->searchCriteria
            ->setPageSize(self::BATCH_SIZE)
            ->setCurrentPage($this->currentPage);

        $orderResult = $this->orderRepository->getList($searchCriteria);
        $this->orders = $orderResult->getItems();
    }

    /**
     * Sends orders to endpoint
     *
     * @throws \Exception
     */
    private function postOrders()
    {
        if (!$this->scopeConfig->getValue(static::RISKIFIED_ENABLED_CONFIG_PATH)) {
            return;
        }
        $orders = [];

        foreach ($this->orders as $model) {
            $orders[] = $this->prepareOrder($model);
        }
        $this->transport->sendHistoricalOrders($orders);
    }

    /**
     * @param $model
     *
     * @return Model\Order
     * @throws \Exception
     */
    private function prepareOrder($model)
    {
        $gateway = 'unavailable';
        if ($model->getPayment()) {
            $gateway = $model->getPayment()->getMethod();
        }

        $this->orderHelper->setOrder($model);

        $order_array = [
            'id' => $this->orderHelper->getOrderOrigId(),
            'name' => $model->getIncrementId(),
            'email' => $model->getCustomerEmail(),
            'created_at' => $this->orderHelper->formatDateAsIso8601($model->getCreatedAt()),
            'currency' => $model->getOrderCurrencyCode(),
            'updated_at' => $this->orderHelper->formatDateAsIso8601($model->getUpdatedAt()),
            'gateway' => $gateway,
            'browser_ip' => $this->orderHelper->getRemoteIp(),
            'note' => $model->getCustomerNote(),
            'total_price' => $model->getGrandTotal(),
            'total_discounts' => $model->getDiscountAmount(),
            'subtotal_price' => $model->getBaseSubtotalInclTax(),
            'discount_codes' => $this->orderHelper->getDiscountCodes($model),
            'taxes_included' => true,
            'total_tax' => $model->getBaseTaxAmount(),
            'total_weight' => $model->getWeight(),
            'cancelled_at' => $this->orderHelper->formatDateAsIso8601($this->orderHelper->getCancelledAt()),
            'financial_status' => $model->getState(),
            'fulfillment_status' => $model->getStatus(),
            'vendor_id' => $model->getStoreId(),
            'vendor_name' => $model->getStoreName()
        ];

        $order = new Model\Order(array_filter($order_array, 'strlen'));
        $order->customer = $this->orderHelper->getCustomer();
        $order->shipping_address = $this->orderHelper->getShippingAddress();
        $order->billing_address = $this->orderHelper->getBillingAddress();
        $order->payment_details = $this->orderHelper->getPaymentDetails();
        $order->line_items = $this->orderHelper->getLineItems();
        $order->shipping_lines = $this->orderHelper->getShippingLines();

        return $order;
    }

    /**
     * @param string $from
     * @param string $to
     */
    private function setSearchCriteria(string $from, string $to)
    {
        $from = date('Y-m-d 00:00:00', strtotime($from));
        $to = date('Y-m-d 23:59:59', strtotime($to));

        $this->searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('created_at', $from, 'gt')
            ->addFilter('created_at', $to, 'lt')
            ->create();
    }
}