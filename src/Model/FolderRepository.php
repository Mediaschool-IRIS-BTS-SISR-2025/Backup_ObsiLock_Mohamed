<?php
namespace App\Model;
use Medoo\Medoo;

class FolderRepository
{
    private Medoo $db;

    public function __construct(Medoo $db)
    {
        $this->db = $db;
    }

    public function listByUser(int $userId): array
    {
        return $this->db->select('folders', '*', ['user_id' => $userId]);
    }

    public function find(int $id): ?array
    {
        return $this->db->get('folders', '*', ['id' => $id]) ?: null;
    }

    public function create(array $data): int
    {
        $this->db->insert('folders', $data);
        return (int)$this->db->id();
    }

    public function delete(int $id): void
    {
        $this->db->delete('folders', ['id' => $id]);
    }
}