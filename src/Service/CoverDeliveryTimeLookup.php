<?php

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Liest die artikelspezifische Lieferzeit aus Cover (C2WART.LIEFER_ZEIT) über die SKU.
 * Abschaltbar mit COVER_DELIVERY_TIME=0 (z.B. lokal ohne Cover-Zugang).
 */
class CoverDeliveryTimeLookup
{
    private ?string $lastError = null;

    public function __construct(
        private ManagerRegistry $registry,
        #[Autowire(env: 'bool:default:cover_delivery_time_default:COVER_DELIVERY_TIME')] private bool $enabled,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param string[] $skus
     * @return array<string, string> SKU => Lieferzeit (nur gefüllte Einträge)
     */
    public function lookup(array $skus): array
    {
        $this->lastError = null;
        if (!$this->enabled || !$skus) {
            return [];
        }

        $result = [];
        try {
            $connection = $this->registry->getConnection('cover');
            foreach (array_chunk(array_values(array_unique($skus)), 900) as $chunk) {
                $rows = $connection->executeQuery(
                    'SELECT TRIM(SKU), TRIM(LIEFER_ZEIT) FROM C2WART WHERE TRIM(SKU) IN (:skus) AND LIEFER_ZEIT IS NOT NULL',
                    ['skus' => $chunk],
                    ['skus' => ArrayParameterType::STRING]
                )->fetchAllNumeric();
                foreach ($rows as [$sku, $value]) {
                    $sku = trim((string) $sku);
                    $value = trim((string) $value);
                    if ($sku !== '' && $value !== '' && !isset($result[$sku])) {
                        $result[$sku] = mb_substr($value, 0, 100);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }

        return $result;
    }
}
