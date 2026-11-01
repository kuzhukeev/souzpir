<?php

namespace App\Migration;

use InvalidArgumentException;
use SQLite3;
use Throwable;

/** Validate the minimum fields needed to import one organization and its brands. */
final class PreparedOrganization
{
    public function read(string $path, string $organizationId): array
    {
        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $organizationId) || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Нужны ULID организации и доступная подготовленная копия SQLite.');
        }
        try {
            $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
            $db->enableExceptions(true);
            try {
                $statement = $db->prepare('SELECT id, name FROM organizations WHERE id = :id');
                $statement->bindValue(':id', $organizationId, SQLITE3_TEXT);
                $organization = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                if (! $organization) {
                    throw new InvalidArgumentException('Организация отсутствует в копии.');
                }
                $this->validateRow($organization, 'организации');
                $statement = $db->prepare('SELECT id, name FROM brands WHERE organization_id = :id ORDER BY id');
                $statement->bindValue(':id', $organizationId, SQLITE3_TEXT);
                $result = $statement->execute();
                $brands = [];
                while ($brand = $result->fetchArray(SQLITE3_ASSOC)) {
                    $this->validateRow($brand, 'бренда');
                    $brands[] = $brand;
                }

                return ['organization' => $organization, 'brands' => $brands];
            } finally {
                $db->close();
            }
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable) {
            throw new InvalidArgumentException('Не удалось прочитать поля организации и брендов из копии.');
        }
    }

    private function validateRow(array $row, string $kind): void
    {
        if (! is_string($row['id']) || ! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $row['id'])
            || ! is_string($row['name']) || trim($row['name']) === '' || mb_strlen($row['name']) > 255) {
            throw new InvalidArgumentException('Некорректный идентификатор или название '.$kind.'.');
        }
    }
}
