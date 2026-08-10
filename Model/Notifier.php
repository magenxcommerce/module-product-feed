<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model;

use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Emails the merchant when a feed fails.
 *
 * OUTGOING MAIL IS NOT CONFIGURED HERE. This goes through Magento's own
 * TransportBuilder, so it uses the store's Mail Sending Settings and whatever
 * SMTP module is already installed; the module only chooses the template and the
 * sender identity.
 *
 * A failure to send must never escalate. The feed has already failed and been
 * recorded; throwing from the notifier would replace a useful error with a
 * confusing one and, from cron, would mark the whole job failed.
 */
class Notifier
{
    private const XML_PATH_GENERAL_EMAIL = 'trans_email/ident_general/email';
    private const XML_PATH_GENERAL_NAME = 'trans_email/ident_general/name';

    public function __construct(
        private readonly Config $config,
        private readonly TransportBuilder $transportBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function notifyFailure(Feed $feed, string $error): void
    {
        $storeId = $feed->getStoreId();

        if (!$this->config->isNotificationEnabled($storeId)) {
            return;
        }

        $recipient = $this->config->getNotificationRecipient($storeId);
        if ($recipient === '') {
            $recipient = (string) $this->scopeConfig->getValue(
                self::XML_PATH_GENERAL_EMAIL,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }

        if ($recipient === '') {
            $this->logger->warning(
                'Magenx_ProductFeed: failure notifications are on but no recipient could be resolved.'
            );

            return;
        }

        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier($this->config->getNotificationTemplate($storeId))
                ->setTemplateOptions([
                    // ADMINHTML, matching the area declared in etc/email_templates.xml.
                    // A mismatch here makes Magento fail to resolve the template file
                    // at send time, long after everything looked correctly configured.
                    'area' => Area::AREA_ADMINHTML,
                    'store' => $storeId,
                ])
                ->setTemplateVars([
                    'feed_name' => (string) $feed->getData('name'),
                    'feed_code' => $feed->getCode(),
                    'store_id' => $storeId,
                    'error' => $error,
                ])
                ->setFromByScope(
                    [
                        'name' => (string) $this->scopeConfig->getValue(
                            self::XML_PATH_GENERAL_NAME,
                            ScopeInterface::SCOPE_STORE,
                            $storeId
                        ),
                        'email' => (string) $this->scopeConfig->getValue(
                            self::XML_PATH_GENERAL_EMAIL,
                            ScopeInterface::SCOPE_STORE,
                            $storeId
                        ),
                    ],
                    $storeId
                )
                ->addTo($recipient)
                ->getTransport();

            $transport->sendMessage();
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'Magenx_ProductFeed: could not send the failure notification for feed "%s": %s',
                    $feed->getCode(),
                    $e->getMessage()
                )
            );
        }
    }
}
