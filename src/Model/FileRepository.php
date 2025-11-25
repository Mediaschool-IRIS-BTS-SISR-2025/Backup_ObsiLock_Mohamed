<?php
namespace App\Model;

use Medoo\Medoo;

class FileRepository
{
    private Medoo $db;

    public function __construct(Medoo $db)
    {
        $this->db = $db;
    }

    public function listFiles(): array
    {
        return $this->db->select('files', '*');
    }

    public function find(int $id): ?array
    {
        return $this->db->get('files', '*', ['id' => $id]) ?: null;
    }

    public function create(array $data): int
    {
        $this->db->insert('files', $data);
        return (int)$this->db->id();
    }

    public function delete(int $id): void
    {
        $this->db->delete('files', ['id' => $id]);
    }

    public function totalSize(): int
    {
        return (int)$this->db->sum('files', 'size') ?: 0;
    }

    public function quotaBytes(): int
    {
        return (int)$this->db->get('settings', 'value', ['name' => 'quota_bytes']);
    }
}