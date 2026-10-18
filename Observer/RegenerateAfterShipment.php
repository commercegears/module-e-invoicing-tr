<?php
/**
 * @copyright Copyright (c) CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\EInvoicingTr\Observer;

use CommerceGears\EInvoicingCore\Api\Data\DocumentInterface;
use CommerceGears\EInvoicingCore\Model\ResourceModel\Document as DocumentResource;
use CommerceGears\EInvoicingCore\Model\ResourceModel\Document\CollectionFactory;
use CommerceGears\EInvoicingCore\Model\ResourceModel\Event;
use CommerceGears\EInvoicingTr\Model\Format\UblTr;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Internet sales: a UBL-TR invoice generated before the order was shipped is invalid (no shipment date / carrier,
 * check TR-SHIP). When the shipment is saved, such invoices go back to "pending" so the cron generates them again
 * (and sends them when automatic sending is on). Cheap: one indexed query per shipment save.
 */
class RegenerateAfterShipment implements ObserverInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly DocumentResource $documentResource,
        private readonly Event $events,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $shipment = $observer->getEvent()->getData('shipment');
        $orderId = $shipment ? (int)$shipment->getOrderId() : 0;
        if ($orderId <= 0) {
            return;
        }
        try {
            $documents = $this->collectionFactory->create()
                ->addFieldToFilter('order_id', $orderId)
                ->addFieldToFilter('format', UblTr::CODE)
                ->addFieldToFilter('entity_type', DocumentInterface::ENTITY_INVOICE)
                ->addFieldToFilter('status', DocumentInterface::STATUS_INVALID)
                ->addFieldToFilter('sent_at', ['null' => true]);
            foreach ($documents as $document) {
                if (!str_contains((string)$document->getData('last_error'), 'TR-SHIP')) {
                    continue;
                }
                $this->documentResource->save($document->addData(['status' => DocumentInterface::STATUS_PENDING,
                    'next_attempt_at' => null]));
                $this->events->add((int)$document->getId(), 'queued',
                    (string)__('The order was shipped: the e-invoice is generated again with the shipment date and carrier.'), '', 'system');
            }
        } catch (\Throwable $e) {
            $this->logger->error('CommerceGears_EInvoicingTr: re-queueing invoices after shipment failed: ' . $e->getMessage());
        }
    }
}
