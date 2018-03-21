<?php
namespace Riskified\Decider\Controller\Response;

use \Riskified\DecisionNotification;
use \Magento\Framework\App\State as AppState;

class Get extends \Magento\Framework\App\Action\Action
{
    private $apiOrderLayer;
    private $api;
    private $apiLogger;
    private $state;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Riskified\Decider\Api\Api $api,
        \Riskified\Decider\Api\Order $apiOrder,
        \Riskified\Decider\Api\Log $apiLogger,
        AppState $state
    ) {
        parent::__construct($context);
        $this->api = $api;
        $this->apiLogger = $apiLogger;
        $this->apiOrderLayer = $apiOrder;
        $this->state = $state;
    }

    public function execute()
    {

        $request = $this->getRequest();
        $response = $this->getResponse();
        $logger = $this->apiLogger;
        $statusCode = 200;
        $id = null;
        $msg = null;

        try {
            $notification = $this->api->parseRequest($request);
            $id = $notification->id;
            if ($notification->status == 'test' && $id == 0) {
                $statusCode = 200;
                $msg = 'Test notification received successfully';
            } else {
                $order = $this->apiOrderLayer->loadOrderByOrigId($id);
                if (!$order || !$order->getId()) {
                    $statusCode = 400;
                    $msg = 'Could not find order to update.';
                } else {
                    $this->state->emulateAreaCode(
                        "adminhtml",
                        [$this->apiOrderLayer, "update"],
                        [
                            $order,
                            $notification->status,
                            $notification->oldStatus,
                            $notification->description
                        ]
                    );

                    $statusCode = 200;
                    $msg = 'Order-Update event triggered.';
                }
            }
        } catch (\Riskified\DecisionNotification\Exception\AuthorizationException $e) {
            $logger->logException($e);
            $statusCode = 401;
            $msg = 'Authentication Failed.';
        } catch (\Riskified\DecisionNotification\Exception\BadPostJsonException $e) {
            $logger->logException($e);
            $statusCode = 400;
            $msg = "JSON Parsing Error.";
        } catch (\Exception $e) {
            $logger->log("ERROR: while processing notification for order $id");
            $logger->logException($e);
            $statusCode = 500;
            $msg = "Internal Error";
        }
        $logger->log($msg);
        $response->setHttpResponseCode($statusCode);
        $response->setHeader('Content-Type', 'application/json');
        $response->setBody('{ "order" : { "id" : "' . $id . '", "description" : "' . $msg . '" } }');
        $response->sendResponse();
        exit;
    }
}
