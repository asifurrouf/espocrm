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

namespace Espo\Core\MassAction;

use Espo\ORM\EntityManager;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\ForbiddenSilent;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;

use Espo\Core\Acl;
use Espo\Core\MassAction\Jobs\Process;
use Espo\Core\Job\JobScheduler;
use Espo\Core\Job\Job\Data as JobData;

use Espo\Entities\User;

use Espo\Entities\MassAction as MassActionEntity;

use stdClass;

class Service
{
    private $factory;

    private $acl;

    private $jobScheduler;

    private $entityManager;

    private $user;

    public function __construct(
        MassActionFactory $factory,
        Acl $acl,
        JobScheduler $jobScheduler,
        EntityManager $entityManager,
        User $user
    ) {
        $this->factory = $factory;
        $this->acl = $acl;
        $this->jobScheduler = $jobScheduler;
        $this->entityManager = $entityManager;
        $this->user = $user;
    }

    /**
     * Perform a mass action.
     *
     * @throws Forbidden
     * @throws BadRequest
     */
    public function process(
        string $entityType,
        string $action,
        ServiceParams $serviceParams,
        stdClass $data
    ): ServiceResult {

        if (!$this->acl->checkScope($entityType)) {
            throw new ForbiddenSilent();
        }

        $params = $serviceParams->getParams();

        if ($serviceParams->isIdle()) {
            if ($this->user->isPortal()) {
                throw new Forbidden("Idle mass actions are not allowed for portal users.");
            }

            return $this->schedule($entityType, $action, $params, $data);
        }

        $massAction = $this->factory->create($action, $entityType);

        $result = $massAction->process(
            $params,
            Data::fromRaw($data)
        );

        if ($params->hasIds()) {
            return ServiceResult::createWithResult($result);
        }

        return ServiceResult::createWithResult(
            $result->withNoIds()
        );
    }

    public function getStatusData(string $id): stdClass
    {
        /** @var MassActionEntity|null $entity */
        $entity = $this->entityManager->getEntity(MassActionEntity::ENTITY_TYPE, $id);

        if (!$entity) {
            throw new NotFound();
        }

        if ($entity->getCreatedBy()->getId() !== $this->user->getId()) {
            throw new Forbidden();
        }

        return (object) [
            'status' => $entity->getStatus(),
            'processedCount' => $entity->getProcessedCount(),
        ];
    }

    public function subscribeToNotificationOnSuccess(string $id): void
    {
        /** @var MassActionEntity|null $entity */
        $entity = $this->entityManager->getEntity(MassActionEntity::ENTITY_TYPE, $id);

        if (!$entity) {
            throw new NotFound();
        }

        if ($entity->getCreatedBy()->getId() !== $this->user->getId()) {
            throw new Forbidden();
        }

        $entity->setNotifyOnFinish();

        $this->entityManager->saveEntity($entity);
    }

    private function schedule(string $entityType, string $action, Params $params, stdClass $data): ServiceResult
    {
        $entity = $this->entityManager->createEntity(MassActionEntity::ENTITY_TYPE, [
            'entityType' => $entityType,
            'action' => $action,
            'params' => serialize($params),
            'data' => $data,
        ]);

        $this->jobScheduler
            ->setClassName(Process::class)
            ->setData(
                JobData::create()
                    ->withTargetId($entity->getId())
                    ->withTargetType($entity->getEntityType())
            )
            ->schedule();

        return ServiceResult::createWithId($entity->getId());
    }
}
