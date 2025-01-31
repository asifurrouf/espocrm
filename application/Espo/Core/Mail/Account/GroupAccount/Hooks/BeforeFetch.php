<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2021 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\Mail\Account\GroupAccount\Hooks;

use Espo\Core\Mail\Account\Hook\BeforeFetch as BeforeFetchInterface;
use Espo\Core\Mail\Account\Hook\BeforeFetchResult;

use Espo\Core\Mail\Account\Account;
use Espo\Core\Mail\Message;

use Espo\Core\Utils\Log;
use Espo\Core\InjectableFactory;

use Espo\ORM\EntityManager;

use Espo\Repositories\EmailAddress as EmailAddressRepository;
use Espo\Modules\Crm\Entities\MassEmail;
use Espo\Modules\Crm\Entities\EmailQueueItem;
use Espo\Modules\Crm\Services\Campaign as CampaignService;

use Throwable;

class BeforeFetch implements BeforeFetchInterface
{
    private Log $log;

    private EntityManager $entityManager;

    private InjectableFactory $injectableFactory;

    private ?CampaignService $campaignService = null;

    public function __construct(Log $log, EntityManager $entityManager, InjectableFactory $injectableFactory)
    {
        $this->log = $log;
        $this->entityManager = $entityManager;
        $this->injectableFactory = $injectableFactory;
    }

    public function process(Account $account, Message $message): BeforeFetchResult
    {
        if (
            $message->hasHeader('from') &&
            preg_match('/MAILER-DAEMON|POSTMASTER/i', $message->getHeader('from'))
        ) {
            try {
                $toSkip = $this->processBounced($message);
            }
            catch (Throwable $e) {
                $this->log->error(
                    'InboundEmail ' . $account->getId() . ' ' .
                    'Process Bounced Message; ' . $e->getCode() . ' ' . $e->getMessage()
                );

                return BeforeFetchResult::create()->withToSkip();
            }

            if ($toSkip) {
                return BeforeFetchResult::create()->withToSkip();
            }
        }

        return BeforeFetchResult::create()
            ->with('skipAutoReply', $this->checkMessageCannotBeAutoReplied($message))
            ->with('isAutoReply', $this->checkMessageIsAutoReply($message));
    }

    private function processBounced(Message $message): bool
    {
        $content = $message->getRawContent();

        $isHard = false;

        if (preg_match('/permanent[ ]*[error|failure]/', $content)) {
            $isHard = true;
        }

        $queueItemId = null;

        if (preg_match('/X-Queue-Item-Id: [a-z0-9\-]*/', $content, $m)) {
            $arr = preg_split('/X-Queue-Item-Id: /', $m[0], -1, \PREG_SPLIT_NO_EMPTY);

            $queueItemId = $arr[0];
        }
        else {
            $to = $message->getHeader('to');

            if (preg_match('/\+bounce-qid-[a-z0-9\-]*/', $to, $m)) {
                $arr = preg_split('/\+bounce-qid-/', $m[0], -1, \PREG_SPLIT_NO_EMPTY);

                $queueItemId = $arr[0];
            }
        }

        if (!$queueItemId) {
            return false;
        }

        $queueItem = $this->entityManager->getEntity(EmailQueueItem::ENTITY_TYPE, $queueItemId);

        if (!$queueItem) {
            return false;
        }

        $massEmailId = $queueItem->get('massEmailId');
        $massEmail = $this->entityManager->getEntity(MassEmail::ENTITY_TYPE, $massEmailId);

        $campaignId = null;

        if ($massEmail) {
            $campaignId = $massEmail->get('campaignId');
        }

        $targetType = $queueItem->get('targetType');
        $targetId = $queueItem->get('targetId');
        $target = $this->entityManager->getEntity($targetType, $targetId);

        $emailAddress = $queueItem->get('emailAddress');

        /** @var EmailAddressRepository $emailAddressRepository */
        $emailAddressRepository = $this->entityManager->getRepository('EmailAddress');

        if ($isHard && $emailAddress) {
            $emailAddressEntity = $emailAddressRepository->getByAddress($emailAddress);

            if ($emailAddressEntity) {
                $emailAddressEntity->set('invalid', true);

                $this->entityManager->saveEntity($emailAddressEntity);
            }
        }

        if (
            $campaignId &&
            $target &&
            $target->getId()
        ) {
            $this->getCampaignService()
                ->logBounced(
                    $campaignId,
                    $queueItemId,
                    $target,
                    $emailAddress,
                    $isHard,
                    null,
                    $queueItem->get('isTest')
                );
        }

        return true;
    }

    private function getCampaignService(): CampaignService
    {
        if (!$this->campaignService) {
            $this->campaignService = $this->injectableFactory->create(CampaignService::class);
        }

        return $this->campaignService;
    }

    private function checkMessageIsAutoReply(Message $message): bool
    {
        if ($message->getHeader('X-Autoreply')) {
            return true;
        }

        if ($message->getHeader('X-Autorespond')) {
            return true;
        }

        if (
            $message->getHeader('Auto-submitted') &&
            strtolower($message->getHeader('Auto-submitted')) !== 'no'
        ) {
            return true;
        }

        return false;
    }

    private function checkMessageCannotBeAutoReplied(Message $message): bool
    {
        if ($message->getHeader('X-Auto-Response-Suppress') === 'AutoReply') {
            return true;
        }

        if ($message->getHeader('X-Auto-Response-Suppress') === 'All') {
            return true;
        }

        if ($this->checkMessageIsAutoReply($message)) {
            return true;
        }

        return false;
    }
}
