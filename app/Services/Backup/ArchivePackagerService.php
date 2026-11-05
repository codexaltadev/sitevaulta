<?php

namespace App\Services\Backup;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class ArchivePackagerService
{
    public static function createEphemeralStagingDir(string $runUuid): string
    {
        $baseDir = sys_get_temp_dir() . '/sitevaulta/run-' . $runUuid;
        if (! is_dir($baseDir)) {
            mkdir($baseDir, 0700, true);
        }

        return $baseDir;
    }

    public static function wipeEphemeralStagingDir(string $stagingDir): void
    {
        if (! is_dir($stagingDir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stagingDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }

        @rmdir($stagingDir);
    }

    /**
     * Packages extracted contents into a compressed archive, optionally encrypts with native AES-256 ZIP encryption,
     * and computes the final SHA-256 checksum.
     */
    public static function packageAndEncrypt(
        string $stagingDir,
        string $archiveBaseName,
        bool $enableEncryption = false,
        ?string $passphrase = null
    ): array {
        $zipFilePath = $stagingDir . '/' . $archiveBaseName . '.zip';
        $zip = new ZipArchive();

        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Unable to create archive at {$zipFilePath}.");
        }

        $isEncrypted = $enableEncryption && ! empty($passphrase);
        if ($isEncrypted) {
            $zip->setPassword($passphrase);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stagingDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            $realPath = $file->getRealPath();
            if ($realPath === $zipFilePath) {
                continue;
            }
            $relativePath = ltrim(str_replace($stagingDir, '', $realPath), '/\\');
            $zip->addFile($realPath, $relativePath);

            if ($isEncrypted) {
                $zip->setEncryptionName($relativePath, ZipArchive::EM_AES_256);
            }
        }

        $zip->close();

        $finalFilePath = $zipFilePath;
        $finalFileName = $archiveBaseName . '.zip';
        $sha256 = hash_file('sha256', $finalFilePath);
        $sizeBytes = (int) filesize($finalFilePath);

        return [
            'local_path' => $finalFilePath,
            'file_name' => $finalFileName,
            'size_bytes' => $sizeBytes,
            'sha256_checksum' => $sha256,
            'compression_algo' => 'zip',
            'is_encrypted' => $isEncrypted,
            'encryption_iv' => null,
        ];
    }

    /**
     * Decrypts an archive (either standard AES-256 password ZIP or legacy SVENC1 .enc)
     * into a standard unencrypted .zip file.
     */
    public static function decryptToPlainZip(string $sourcePath, string $destPath, string $passphrase): bool
    {
        if (! file_exists($sourcePath)) {
            return false;
        }

        // 1. Backward-compatible check for legacy SVENC1 format
        $header = file_get_contents($sourcePath, false, null, 0, 6);
        if ($header === 'SVENC1') {
            $encryptedData = file_get_contents($sourcePath);
            if (strlen($encryptedData) < 34) {
                return false;
            }
            $iv = substr($encryptedData, 6, 12);
            $tag = substr($encryptedData, 18, 16);
            $cipherText = substr($encryptedData, 34);
            $key = hash('sha256', $passphrase, true);
            $plainData = openssl_decrypt($cipherText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plainData === false) {
                return false;
            }
            file_put_contents($destPath, $plainData);
            return true;
        }

        // 2. Native Standard Password-Protected Zip Archive
        $src = new ZipArchive();
        if ($src->open($sourcePath) !== true) {
            return false;
        }
        $src->setPassword($passphrase);

        if ($src->numFiles > 0) {
            $test = $src->getFromIndex(0);
            if ($test === false) {
                $src->close();
                return false; // Invalid passphrase
            }
        }

        $dst = new ZipArchive();
        if ($dst->open($destPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $src->close();
            return false;
        }

        for ($i = 0; $i < $src->numFiles; $i++) {
            $stat = $src->statIndex($i);
            $name = $stat['name'];
            if (str_ends_with($name, '/')) {
                $dst->addEmptyDir($name);
            } else {
                $content = $src->getFromIndex($i);
                if ($content !== false) {
                    $dst->addFromString($name, $content);
                }
            }
        }

        $dst->close();
        $src->close();

        return file_exists($destPath);
    }

    /**
     * Decrypts an archive with the current master passphrase and re-packages it
     * into a standard WinRAR / WinZip / 7-Zip password-protected .zip using a new custom/client passphrase.
     */
    public static function reEncryptToCustomPasswordZip(
        string $sourcePath,
        string $destPath,
        string $currentPassphrase,
        string $newPassphrase
    ): bool {
        $tempDecrypted = tempnam(sys_get_temp_dir(), 'sv_re_') . '.zip';
        $decOk = self::decryptToPlainZip($sourcePath, $tempDecrypted, $currentPassphrase);
        if (! $decOk || ! file_exists($tempDecrypted)) {
            return false;
        }

        $src = new ZipArchive();
        if ($src->open($tempDecrypted) !== true) {
            @unlink($tempDecrypted);
            return false;
        }

        $dst = new ZipArchive();
        if ($dst->open($destPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $src->close();
            @unlink($tempDecrypted);
            return false;
        }

        $dst->setPassword($newPassphrase);

        for ($i = 0; $i < $src->numFiles; $i++) {
            $stat = $src->statIndex($i);
            $name = $stat['name'];
            if (str_ends_with($name, '/')) {
                $dst->addEmptyDir($name);
            } else {
                $content = $src->getFromIndex($i);
                if ($content !== false) {
                    $dst->addFromString($name, $content);
                    $dst->setEncryptionName($name, ZipArchive::EM_TRAD_PKWARE);
                }
            }
        }

        $dst->close();
        $src->close();
        @unlink($tempDecrypted);

        return file_exists($destPath);
    }

    /**
     * Verifies whether a given passphrase can successfully decrypt the archive.
     */
    public static function verifyPassphrase(string $sourcePath, string $passphrase): bool
    {
        if (! file_exists($sourcePath)) {
            return false;
        }

        $header = file_get_contents($sourcePath, false, null, 0, 6);
        if ($header === 'SVENC1') {
            $encryptedData = file_get_contents($sourcePath);
            if (strlen($encryptedData) < 34) {
                return false;
            }
            $iv = substr($encryptedData, 6, 12);
            $tag = substr($encryptedData, 18, 16);
            $cipherText = substr($encryptedData, 34);
            $key = hash('sha256', $passphrase, true);
            return openssl_decrypt($cipherText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag) !== false;
        }

        $src = new ZipArchive();
        if ($src->open($sourcePath) !== true) {
            return false;
        }
        $src->setPassword($passphrase);
        $valid = true;
        if ($src->numFiles > 0) {
            $test = $src->getFromIndex(0);
            $valid = ($test !== false);
        }
        $src->close();

        return $valid;
    }
}

