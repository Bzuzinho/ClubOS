<?php

namespace App\Policies;

use App\Models\ReceiptImportBatch;
use App\Models\User;
use App\Services\AccessControl\UserTypeAccessControlService;

class ReceiptImportBatchPolicy
{
    public function __construct(
        private readonly UserTypeAccessControlService $accessControlService,
    ) {
    }

    public function viewAny(User $user): bool
    {
        return $this->accessControlService->canAccessPermission($user, 'financeiro.importacao_recibos', 'view');
    }

    public function view(User $user, ReceiptImportBatch $batch): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->accessControlService->canAccessPermission($user, 'financeiro.importacao_recibos', 'edit');
    }

    public function commit(User $user, ReceiptImportBatch $batch): bool
    {
        return $this->accessControlService->canAccessPermission($user, 'financeiro.importacao_recibos', 'edit');
    }
}