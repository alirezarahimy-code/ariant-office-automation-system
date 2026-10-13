<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Asset;

class AssetService
{
    private Asset $assetModel;

    public function __construct()
    {
        $this->assetModel = new Asset();
    }

    public function getAsset(int $id): ?array
    {
        return $this->assetModel->find($id);
    }

    public function getAssetByTag(string $tag): ?array
    {
        return $this->assetModel->findByTag($tag);
    }

    public function getAssetByHostname(string $hostname): ?array
    {
        return $this->assetModel->findByHostname($hostname);
    }

    public function getAssetsByDepartment(int $departmentId): array
    {
        return $this->assetModel->findByDepartment($departmentId);
    }

    public function getAssetsByOwner(int $ownerId): array
    {
        return $this->assetModel->findByOwner($ownerId);
    }

    public function getOnlineAssets(): array
    {
        return $this->assetModel->findOnline();
    }

    public function createAsset(array $data): int
    {
        return $this->assetModel->create($data);
    }

    public function updateAsset(int $id, array $data): bool
    {
        return $this->assetModel->update($id, $data);
    }

    public function deleteAsset(int $id): bool
    {
        return $this->assetModel->delete($id);
    }

    public function searchAssets(string $keyword): array
    {
        return $this->assetModel->search($keyword);
    }

    public function getAssetProfile(int $assetId): ?array
    {
        return $this->assetModel->getProfile($assetId);
    }

    public function getAssetHistory(int $assetId): array
    {
        return $this->assetModel->getHistory($assetId);
    }

    public function getAssetInventoryHistory(int $assetId): array
    {
        return $this->assetModel->getInventoryHistory($assetId);
    }

    public function getAssetRelations(int $assetId): array
    {
        return $this->assetModel->getRelations($assetId);
    }

    public function getAssetStats(): array
    {
        return [
            'by_lifecycle' => $this->assetModel->countByLifecycleStatus(),
            'by_department' => $this->assetModel->countByDepartment(),
            'online' => count($this->getOnlineAssets()),
        ];
    }

    public function updateLifecycleStatus(int $assetId, string $status): bool
    {
        return $this->assetModel->update($assetId, ['lifecycle_status' => $status]);
    }
}
