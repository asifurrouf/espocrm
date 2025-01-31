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

namespace Espo\Core\Mail\Account\PersonalAccount\Hooks;

use Espo\Core\Mail\Account\Account;
use Espo\Core\Mail\Account\Hook\BeforeFetchResult;
use Espo\Core\Mail\Account\Hook\AfterFetch as AfterFetchInterface;

use Espo\Services\Stream as StreamService;
use Espo\Entities\Email;

use Espo\ORM\EntityManager;

class AfterFetch implements AfterFetchInterface
{
    private EntityManager $entityManager;

    private StreamService $streamService;

    public function __construct(
        EntityManager $entityManager,
        StreamService $streamService
    ) {
        $this->entityManager = $entityManager;
        $this->streamService = $streamService;
    }

    public function process(Account $account, Email $email, BeforeFetchResult $beforeFetchResult): void
    {
        if (!$email->isFetched()) {
            $this->noteAboutEmail($email);
        }
    }

    private function noteAboutEmail(Email $email): void
    {
        $parentLink = $email->getParent();

        if (!$parentLink) {
            return;
        }

        $parent = $this->entityManager->getEntity($parentLink->getEntityType(), $parentLink->getId());

        if (!$parent) {
            return;
        }

        $this->streamService->noteEmailReceived($parent, $email);
    }
}
