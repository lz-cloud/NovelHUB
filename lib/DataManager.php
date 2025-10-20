<?php
class DataManager
{
    private string $dataDir;

    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/');
    }

    private function ensureFile(string $file, $default)
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!file_exists($file)) {
            $this->writeJson($file, $default);
        }
    }

    public function readJson(string $file, $default = [])
    {
        $this->ensureFile($file, $default);
        $fp = fopen($file, 'r');
        if (!$fp) {
            return $default;
        }
        try {
            if (!flock($fp, LOCK_SH)) {
                // Could not lock for reading; return default to avoid blocking
                return $default;
            }
            $size = filesize($file);
            if ($size === 0) {
                return $default;
            }
            $raw = fread($fp, $size);
            $data = json_decode($raw, true);
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                return $default;
            }
            return $data;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function writeJson(string $file, $data): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $file . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $fp = fopen($tmp, 'c+');
        if (!$fp) return false;
        try {
            if (!flock($fp, LOCK_EX)) return false;
            ftruncate($fp, 0);
            fwrite($fp, $json);
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        return rename($tmp, $file);
    }

    // Atomically append an item with auto-increment id
    public function appendWithId(string $file, array $item, string $idField = 'id')
    {
        $this->ensureFile($file, []);
        $fp = fopen($file, 'c+');
        if (!$fp) return false;
        try {
            if (!flock($fp, LOCK_EX)) return false;
            $raw = stream_get_contents($fp);
            $arr = [];
            if ($raw && strlen($raw) > 0) {
                $arr = json_decode($raw, true);
                if (!is_array($arr)) $arr = [];
            }
            $max = 0;
            foreach ($arr as $row) {
                if (isset($row[$idField]) && $row[$idField] > $max) {
                    $max = (int)$row[$idField];
                }
            }
            $item[$idField] = $max + 1;
            $arr[] = $item;
            // rewind and truncate then write
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            fflush($fp);
            return $item[$idField];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function updateById(string $file, int $id, array $newData, string $idField = 'id'): bool
    {
        $this->ensureFile($file, []);
        $fp = fopen($file, 'c+');
        if (!$fp) return false;
        $updated = false;
        try {
            if (!flock($fp, LOCK_EX)) return false;
            $raw = stream_get_contents($fp);
            $arr = [];
            if ($raw && strlen($raw) > 0) {
                $arr = json_decode($raw, true);
                if (!is_array($arr)) $arr = [];
            }
            foreach ($arr as &$row) {
                if (isset($row[$idField]) && (int)$row[$idField] === $id) {
                    $row = array_merge($row, $newData);
                    $updated = true;
                    break;
                }
            }
            unset($row);
            if ($updated) {
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                fflush($fp);
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        return $updated;
    }

    public function findById(string $file, int $id, string $idField = 'id')
    {
        $arr = $this->readJson($file, []);
        foreach ($arr as $row) {
            if (isset($row[$idField]) && (int)$row[$idField] === $id) return $row;
        }
        return null;
    }

    public function filter(string $file, callable $predicate): array
    {
        $arr = $this->readJson($file, []);
        return array_values(array_filter($arr, $predicate));
    }

    public function deleteById(string $file, int $id, string $idField = 'id'): bool
    {
        $this->ensureFile($file, []);
        $fp = fopen($file, 'c+');
        if (!$fp) return false;
        $deleted = false;
        try {
            if (!flock($fp, LOCK_EX)) return false;
            $raw = stream_get_contents($fp);
            $arr = [];
            if ($raw && strlen($raw) > 0) {
                $arr = json_decode($raw, true);
                if (!is_array($arr)) $arr = [];
            }
            $before = count($arr);
            $arr = array_values(array_filter($arr, function($row) use ($id, $idField) {
                return !isset($row[$idField]) || (int)$row[$idField] !== $id;
            }));
            if (count($arr) !== $before) {
                $deleted = true;
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                fflush($fp);
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        return $deleted;
    }
}
