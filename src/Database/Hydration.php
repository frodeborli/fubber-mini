<?php

namespace mini\Database;

/**
 * Combined interface for entities with both custom hydration and dehydration
 *
 * Use this when you need custom logic for both loading and saving.
 * If you only need one direction, implement Hydratable or Dehydratable instead.
 *
 * ```php
 * class User implements Hydration
 * {
 *     public int $id;
 *     public string $fullName;
 *     public \DateTimeImmutable $createdAt;
 *
 *     public static function fromSqlRow(object $row): static
 *     {
 *         $user = new static();
 *         $user->id = $row->id;
 *         $user->fullName = $row->first_name . ' ' . $row->last_name;
 *         $user->createdAt = new \DateTimeImmutable($row->created_at);
 *         return $user;
 *     }
 *
 *     public function toSqlRow(): array
 *     {
 *         $parts = explode(' ', $this->fullName, 2);
 *         return [
 *             'id' => $this->id,
 *             'first_name' => $parts[0],
 *             'last_name' => $parts[1] ?? '',
 *             'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
 *         ];
 *     }
 * }
 * ```
 */
interface Hydration extends Hydratable, Dehydratable
{
}
