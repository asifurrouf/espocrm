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

namespace Espo\Core\Mail\Account\PersonalAccount;

use Espo\Core\Exceptions\Error;
use Espo\Core\InjectableFactory;
use Espo\Core\Binding\BindingContainerBuilder;

use Espo\Entities\EmailAccount;

use Espo\ORM\EntityManager;

class AccountFactory
{
    private InjectableFactory $injectableFactory;

    private EntityManager $entityManager;

    public function __construct(InjectableFactory $injectableFactory, EntityManager $entityManager)
    {
        $this->injectableFactory = $injectableFactory;
        $this->entityManager = $entityManager;
    }

    public function create(string $id): Account
    {
        $entity = $this->entityManager->getEntityById(EmailAccount::ENTITY_TYPE, $id);

        if (!$entity) {
            throw new Error("EmailAccount '{$id}' not found.");
        }

        $binding = BindingContainerBuilder::create()
            ->bindInstance(EmailAccount::class, $entity)
            ->build();

        return $this->injectableFactory->createWithBinding(Account::class, $binding);
    }
}
