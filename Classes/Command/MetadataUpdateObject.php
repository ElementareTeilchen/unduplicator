<?php

declare(strict_types=1);

namespace ElementareTeilchen\Unduplicator\Command;

class MetadataUpdateObject
{

    public function __construct(
        public readonly int $masterFileUid,
        public readonly array $masterMetadata,
        public readonly array $oldMetadata,
        public readonly array $fieldsToCheck
    ) {
    }

    public function getOldMetadata(): array
    {
        return $this->oldMetadata;
    }

    public function getMasterFileUid(): int
    {
        return $this->masterFileUid;
    }

    public function getMasterUid(): int|null
    {
        return $this->masterMetadata['uid'] ?? null;
    }

    public function getOldUid(): int
    {
        return $this->oldMetadata['uid'];
    }

    public function getLanguageUid(): int
    {
        return $this->oldMetadata['sys_language_uid'];
    }

    public function isOldEmtpyt(): bool
    {
        return empty($this->getOldClean());
    }

    public function isMasterEmpty(): bool
    {
        return empty($this->getMasterClean());
    }

    public function hasMaster(): bool
    {
        return $this->getMasterUid() !== null;
    }

    public function isOldSameAsMaster(): bool
    {
        return $this->getOldClean() === $this->getMasterClean();
    }

    public function getMasterClean(): array
    {
        return $this->clearMetadataRecord($this->masterMetadata);
    }

    public function getOldClean(): array
    {
        return $this->clearMetadataRecord($this->oldMetadata);
    }

    private function clearMetadataRecord(array $metadata): array
    {
        // unset all fields that are not in $this->fieldsToCheck
        foreach ($metadata as $key => $value) {
            if (!in_array($key, $this->fieldsToCheck) || empty($value)) {
                unset($metadata[$key]);
            }
        }
        return $metadata;
    }
}
