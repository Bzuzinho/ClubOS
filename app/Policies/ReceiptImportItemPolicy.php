<?php

namespace App\Policies;

use App\Models\ReceiptImportItem;
use App\Models\User;
use App\Services\AccessControl\UserTypeAccessControlService;

class ReceiptImportItemPolicy
{
    public function __construct(
        private readonly UserTypeAccessControlService $accessControlService,
    ) {
    }

    public function view(User $user, ReceiptImportItem $item): bool
    {
        return $this->accessControlService->canAccessPermission($user, 'financeiro.importacao_recibos', 'view');
    }

    public function update(User $user, ReceiptImportItem $item): bool
    {
        return $this->accessControlService->canAccessPermission($user, 'financeiro.importacao_recibos', 'edit');
    }
}