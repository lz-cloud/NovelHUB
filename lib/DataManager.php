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
        if (!is_writable($dir)) {
            @chmod($dir, 0775);
            if (!is_writable($dir)) {
                @chmod($dir, 0777);
            }
        }
        if (!file_exists($file)) {
            $this->writeJson($file, $default);
        }
        if (file_exists($file) && !is_writable($file)) {
            @chmod($file, 0664);
            if (!is_writable($file)) {
                @chmod($file, 0666);
            }
        }
    }

    public function readJson(string $file, $default = [])
    {
        $this->ensureFile($file, $default);
        $fp = @fopen($file, 'r');
        if (!$fp) {
            error_log("DataManager: Failed to open file for reading: {$file}");
            return $default;
        }
        try {
            if (!flock($fp, LOCK_SH)) {
                error_log("DataManager: Failed to acquire read lock: {$file}");
                return $default;
            }
            $size = filesize($file);
            if ($size === false || $size === 0) {
                return $default;
            }
            $raw = fread($fp, $size);
            if ($raw === false) {
                error_log("DataManager: Failed to read file: {$file}");
                return $default;
            }
            $data = json_decode($raw, true);
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                error_log("DataManager: JSON decode error in {$file}: " . json_last_error_msg());
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
        // ensure directory permissions
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0775);
            if (!is_writable($dir)) @chmod($dir, 0777);
        }
        $fp = @fopen($tmp, 'c+');
        if (!$fp) {
            error_log("DataManager: Failed to open temp file for writing: {$tmp}");
            return false;
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                error_log("DataManager: Failed to acquire write lock: {$file}");
                return false;
            }
            if (!ftruncate($fp, 0)) {
                error_log("DataManager: Failed to truncate temp file: {$tmp}");
                return false;
            }
            if (fwrite($fp, $json) === false) {
                error_log("DataManager: Failed to write JSON to temp file: {$tmp}");
                return false;
            }
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        $ok = @rename($tmp, $file);
        if ($ok) {
            @chmod($file, 0664);
        } else {
            error_log("DataManager: Failed to rename temp file {$tmp} to {$file}");
            @unlink($tmp);
        }
        return $ok;
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
